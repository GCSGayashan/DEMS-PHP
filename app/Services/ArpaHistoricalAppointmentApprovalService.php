<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{Auth,ScopeService};
use DomainException;
use PDO;
use Throwable;

/** Governance only: source requests, appointments, closures and correction decisions are never written. */
final class ArpaHistoricalAppointmentApprovalService
{
    private const CASE_TABLE='arpa_appointment_historical_approval_case';
    private const EVENT_TABLE='arpa_appointment_historical_approval_event';
    public function __construct(private readonly PDO $pdo){}

    public function register(string $sourceCorrectionId,array $input,string $actorId):array
    {
        return $this->registerCase($sourceCorrectionId,$input,$actorId,false,null);
    }

    /** Trusted CLI registration only; this does not grant any submission or review authority. */
    public static function assertCliRegistrationOperator(string $actorId):void
    {
        // A batch may outlive a normal request: do not retain a revoked role/permission cache.
        Auth::forgetRequestCache();
        $c=Auth::activeContext(false);
        if(PHP_SAPI!=='cli'||!Auth::isCurrentUser($actorId)||$c===null
            ||$c['role_code']!=='SYSTEM_ADMIN'||$c['role_level']!=='SYSTEM'
            ||!Auth::can('arpa.appointment.view')||!ArpaAdministrativePolicy::isCanonicalDemsAdmin()){
            throw new DomainException('CLI registration requires the canonical DEMS administrator in an active SYSTEM_ADMIN / SYSTEM context.',403);
        }
    }

    /** Read-only registration assessment, reusing the exact Phase 1 protected source bundle. */
    public function registrationPreview(string $sourceCorrectionId,string $actorId,bool $cli=false,bool $lock=false):array
    {
        $this->actor($actorId);if($cli)self::assertCliRegistrationOperator($actorId);
        if($lock&&!$this->pdo->inTransaction())throw new DomainException('Registration locks require a transaction.',409);
        $identity=$this->one('SELECT asc_location_id FROM arpa_appointment_data_correction WHERE id=?',[$sourceCorrectionId]);
        if(!$cli)$this->owner($actorId,(string)$identity['asc_location_id']);
        $bundle=$this->bundle($sourceCorrectionId,$lock);
        if(!$cli)$this->owner($actorId,(string)$bundle['appointment']['asc_location_id']);
        $cases=$this->rows('SELECT * FROM '.self::CASE_TABLE.' WHERE appointment_id=? ORDER BY id'.($lock?' FOR UPDATE':''),[$bundle['appointment']['id']]);
        return ['baseline'=>$bundle,'baseline_hash'=>hash('sha256',$this->json($bundle)),'cases'=>$cases];
    }

    public function registerFromCli(string $sourceCorrectionId,array $input,string $actorId,string $expectedBaselineHash):array
    {
        self::assertCliRegistrationOperator($actorId);
        if(!preg_match('/^[a-f0-9]{64}$/D',$expectedBaselineHash))throw new DomainException('A reviewed source baseline hash is required.',409);
        return $this->registerCase($sourceCorrectionId,$input,$actorId,true,$expectedBaselineHash);
    }

    private function registerCase(string $sourceCorrectionId,array $input,string $actorId,bool $cli,?string $expectedHash):array
    {
        $this->actor($actorId);$this->input($input);if($cli)self::assertCliRegistrationOperator($actorId);
        return $this->transaction(function()use($sourceCorrectionId,$input,$actorId,$cli,$expectedHash):array{
            $preview=$this->registrationPreview($sourceCorrectionId,$actorId,$cli,true);$bundle=$preview['baseline'];
            if($expectedHash!==null&&!hash_equals($expectedHash,$preview['baseline_hash']))throw new DomainException('The historical registration baseline changed. Run a fresh dry-run.',409);
            return $this->writeRegistration($bundle,$preview['cases'],$input,$actorId);
        });
    }

    /** Internal canonicalization adapter. No web/CLI endpoint and no borrowed ASC identity. */
    public function registerCanonicalCorrectionInTransaction(string $correctionId,string $actorId,string $path,?string $batchId=null):array
    {
        if(!$this->pdo->inTransaction())throw new DomainException('Automatic historical registration requires the canonicalization transaction.',409);
        return $this->transaction(fn():array=>$this->registerCanonicalCorrection($correctionId,$actorId,$path,$batchId));
    }

    private function registerCanonicalCorrection(string $correctionId,string $actorId,string $path,?string $batchId):array
    {
        $this->actor($actorId);
        if(!in_array($path,['DIRECT','BULK','AMENDMENT'],true))throw new DomainException('Unsupported canonicalization registration path.',403);
        if(!ArpaAppointmentCorrectionDecision::lineageAvailable($this->pdo)||!(new ArpaHistoricalApprovalReadService($this->pdo))->available())throw new DomainException('Complete correction-lineage and migration 067 schemas are required before canonicalizing historical appointments.',503);
        $tables=$this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN('arpa_appointment_historical_approval_case','arpa_appointment_historical_approval_event') AND engine='InnoDB'")->fetchColumn();
        $triggers=$this->pdo->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND event_object_table='arpa_appointment_historical_approval_event' AND trigger_name IN('trg_hist_approval_event_no_update','trg_hist_approval_event_no_delete') AND action_timing='BEFORE' AND event_manipulation IN('UPDATE','DELETE')")->fetchColumn();
        if((int)$tables!==2||(int)$triggers!==2)throw new DomainException('Transactional historical case/event tables and append-only audit triggers are required.',503);
        $c=$this->one('SELECT * FROM arpa_appointment_data_correction WHERE id=? FOR UPDATE',[$correctionId]);
        if($c['corrected_by']!==$actorId||$c['correction_action']!=='RESOLVE_CANONICAL_ASSIGNMENT'||$c['resolution_status']!=='RESOLVED_BY_CORRECTION')throw new DomainException('A matching canonicalization decision by the current actor is required.',403);
        if($path==='AMENDMENT'){
            $m=$this->one('SELECT * FROM arpa_appointment_correction_amendment_request WHERE id=? FOR UPDATE',[(string)$c['amendment_request_id']]);
            if(!Auth::can('arpa.appointment.national-approve')||!ScopeService::canAccessCurrentArpaStage($actorId,'NATIONAL',$c['asc_location_id'])
                ||$m['workflow_status']!=='NATIONAL_APPROVED'||$m['applied_correction_id']!==$c['id']||$m['finalized_by']!==$actorId
                ||$m['source_correction_id']!==$c['supersedes_correction_id']||$m['appointment_id']!==$c['appointment_id']
                ||$m['officer_id']!==$c['officer_id']||$m['asc_location_id']!==$c['asc_location_id']||$m['proposed_action']!=='RESOLVE_CANONICAL_ASSIGNMENT')throw new DomainException('Effective final National amendment application is required.',403);
            if($this->rows("SELECT id FROM arpa_appointment_correction_amendment_event WHERE amendment_request_id=? AND user_id=? AND stage='NATIONAL' AND action='APPROVE' AND previous_status='NATIONAL_VERIFIED' AND new_status='NATIONAL_APPROVED' FOR UPDATE",[$m['id'],$actorId])===[])throw new DomainException('The final amendment approval event must precede registration.',409);
        }else{
            if($c['amendment_request_id']!==null)throw new DomainException('Amendment decisions require the final amendment approval path.',403);
            if($path==='DIRECT'&&!(new ArpaAppointmentDataIssueCorrectionService($this->pdo))->canCorrect($actorId,$c['asc_location_id']))throw new DomainException('The assigned ASC correction context is required.',403);
            if($path==='BULK'&&(!ArpaAdministrativePolicy::isCanonicalDemsAdmin()||$batchId===null||trim($batchId)===''))throw new DomainException('The existing canonical administrator bulk correction authority is required.',403);
        }
        $audit=$path==='AMENDMENT'?'arpa.appointment.correction-amendment.apply':'arpa.appointment.data-issue.correct';
        if($this->rows('SELECT id FROM audit_event WHERE target_id=? AND actor_user_id=? AND action_key=? FOR UPDATE',[$c['id'],$actorId,$audit])===[])throw new DomainException('Canonical correction audit evidence is incomplete.',409);
        $bundle=$this->bundle($correctionId,true);$a=$bundle['appointment'];
        if($path==='BULK'){
            $metadata=json_decode($a['origin_metadata_json']??'{}',true,512,JSON_THROW_ON_ERROR);$origin=$metadata['data_issue_resolution']??[];
            if(($origin['batch_id']??null)!==$batchId||($origin['correction_id']??null)!==$correctionId)throw new DomainException('The bulk correction batch provenance does not match.',409);
        }
        if($this->rows("SELECT id FROM audit_event WHERE target_id=? AND action_key='arpa.appointment.workflow-request.admin-delete' FOR UPDATE",[$a['request_id']])!==[])throw new DomainException('An administratively excluded source cannot be automatically registered.',409);
        if(count($this->rows('SELECT id FROM arpa_division_appointment WHERE request_id=? FOR UPDATE',[$a['request_id']]))!==1)throw new DomainException('Canonical source identity is ambiguous.',409);
        if(!in_array($a['appointment_type'],ArpaAppointmentRules::APPOINTMENT_TYPES,true)||$bundle['source_references']===[])throw new DomainException('Valid appointment type and verified legacy source references are required.',409);
        foreach($bundle['source_references'] as $ref)if(trim((string)$ref['source_system'])===''||trim((string)$ref['source_table'])===''||trim((string)$ref['legacy_appointment_id'])===''
            ||($ref['target_appointment_id']!==null&&$ref['target_appointment_id']!==$a['id']))throw new DomainException('Legacy source references are incomplete or inconsistent.',409);
        $input=['reason'=>'Historical approval required after canonical correction: '.$c['correction_reason'],
            'evidence_reference'=>trim((string)$c['evidence_reference'])!==''?$c['evidence_reference']:'Correction decision '.$c['id'].'; original source evidence remains in the linked correction.'];
        $this->input($input);
        return $this->writeRegistration($bundle,$this->rows('SELECT * FROM '.self::CASE_TABLE.' WHERE appointment_id=? ORDER BY id FOR UPDATE',[$a['id']]),$input,$actorId);
    }

    /** One writer for ASC, CLI and already-authorized correction transactions. */
    private function writeRegistration(array $bundle,array $cases,array $input,string $actorId):array
    {
        $a=$bundle['appointment'];$sourceCorrectionId=$bundle['correction']['id'];
        $existing=array_values(array_filter($cases,static fn($c)=>$c['source_correction_id']===$sourceCorrectionId));
        if(count($existing)>1)throw new DomainException('Historical case identity is ambiguous.',409);
        if($existing!==[]){$this->identity($existing[0],$bundle);return $this->present($existing[0]);} // Never reset an existing case or repeat REGISTER.
        if(array_filter($cases,static fn($c)=>$c['active_appointment_id']!==null)!==[])throw new DomainException('An active historical approval case already exists for this appointment. Resolve that case before applying a new canonical decision.',409);
        $id=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();$json=$this->json($bundle);
        $this->pdo->prepare('INSERT INTO '.self::CASE_TABLE.'(id,request_id,appointment_id,source_correction_id,officer_id,asc_location_id,baseline_json,baseline_hash,reason,evidence_reference,remarks,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$a['request_id'],$a['id'],$sourceCorrectionId,$a['officer_id'],$a['asc_location_id'],$json,hash('sha256',$json),$input['reason'],$input['evidence_reference'],$input['remarks']??null,$actorId]);
        $after=$this->caseRow($id,true);$this->event($after,'REGISTER','CREATOR',$actorId,null,$input['reason'],$input['evidence_reference']);return $this->present($after);
    }

    public function get(string $id,string $actorId):array
    {
        $this->actor($actorId);$case=$this->caseRow($id);$this->reader($case,$actorId);
        $result=$this->present($case);
        try{$current=$this->bundle($case['source_correction_id'],false);$this->identity($case,$current);$result['current_baseline']=$current;$result['current_source_hash']=hash('sha256',$this->json($current));}
        catch(DomainException $e){$result['current_baseline']=null;$result['current_source_hash']=null;$result['source_blocker']=$e->getMessage();}
        return $result;
    }

    /** Presentation follows the same owner/reviewer/maker-checker guards as every mutation. */
    public function interfaceGet(string $id,string $actorId):array
    {
        $r=$this->get($id,$actorId);$case=$r['case'];$editable=in_array($case['workflow_status'],['DRAFT','RETURNED'],true);
        $r['source_stale']=$r['current_source_hash']===null||!hash_equals($case['baseline_hash'],$r['current_source_hash']);
        $actions=['edit'=>false,'submit'=>false,'primary'=>null,'return'=>false,'reject'=>false,'final'=>false];
        try{$this->owner($actorId,$case['asc_location_id']);$actions['edit']=$editable&&$r['current_baseline']!==null;$actions['submit']=$editable&&!$r['source_stale'];}catch(DomainException){}
        try{
            $p=$this->reviewProfile($case,$actorId);$allowed=!$r['source_stale'];
            if($p['action']==='APPROVE')try{$this->makerChecker($case,$p['stage'],$actorId);}catch(DomainException $e){$allowed=false;$r['action_blocker']=$e->getMessage();}
            if($allowed)$actions['primary']=$p['action'];
            $actions['return']=Auth::can('arpa.appointment.return');$actions['reject']=Auth::can('arpa.appointment.reject');
            $actions['final']=$actions['primary']==='APPROVE'&&$case['workflow_status']==='NATIONAL_VERIFIED';
        }catch(DomainException){}
        if($editable&&$r['source_stale']&&$this->canRetireStaleDraft($case,$actorId))$actions['reject']=true;
        $r['actions']=$actions;$a=($r['current_baseline']??$r['baseline'])['appointment'];
        $d=$this->one("SELECT CONCAT(COALESCE(o.dad_number,''),' - ',COALESCE(o.name_with_initials,'')) officer,al.name_en asc_name,arpa.name_en division_name FROM officer o JOIN location al ON al.id=? JOIN location arpa ON arpa.id=? WHERE o.id=?",[$case['asc_location_id'],$a['arpa_division_location_id'],$case['officer_id']]);
        $to=($r['current_baseline']??$r['baseline'])['closures'][0]['effective_to']??null;
        $actors=array_values(array_unique(array_column($r['events'],'user_id')));$names=[];
        if($actors!==[])foreach($this->rows('SELECT id,COALESCE(NULLIF(display_name,\'\'),username) name FROM system_user WHERE id IN('.implode(',',array_fill(0,count($actors),'?')).')',$actors) as $actor)$names[$actor['id']]=$actor['name'];
        $r['display']=['officer'=>$d['officer'],'asc'=>$d['asc_name'],'division'=>$d['division_name'],'actors'=>$names,'operational_status'=>$to!==null&&$to<date('Y-m-d')?'Ended':($a['effective_from']>date('Y-m-d')?'Scheduled':'Current / Open')];return $r;
    }

    /** Explicit hash acknowledgement permits a fresh baseline only in DRAFT/RETURNED; no approval is carried forward. */
    public function updateDraft(string $id,array $input,int $expectedVersion,string $expectedSourceHash,string $actorId):array
    {
        $this->actor($actorId);$this->input($input);
        return $this->transaction(function()use($id,$input,$expectedVersion,$expectedSourceHash,$actorId):array{
            $case=$this->caseRow($id,true);$this->owner($actorId,$case['asc_location_id']);$this->version($case,$expectedVersion);
            if(!in_array($case['workflow_status'],['DRAFT','RETURNED'],true))throw new DomainException('Only Draft or Returned historical cases may be edited.',409);
            $bundle=$this->bundle($case['source_correction_id'],true);$this->identity($case,$bundle);
            $json=$this->json($bundle);$hash=hash('sha256',$json);
            if(!hash_equals($hash,$expectedSourceHash))throw new DomainException('Review the current source baseline before saving this case.',409);
            $this->pdo->prepare('UPDATE '.self::CASE_TABLE.' SET baseline_json=?,baseline_hash=?,reason=?,evidence_reference=?,remarks=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND version=?')
                ->execute([$json,$hash,$input['reason'],$input['evidence_reference'],$input['remarks']??null,$actorId,$id,$expectedVersion]);
            $after=$this->caseRow($id,true);$this->event($after,'EDIT_DRAFT','CREATOR',$actorId,$case,$input['reason'],$input['evidence_reference']);
            return $this->present($after);
        });
    }

    public function submit(string $id,int $expectedVersion,string $actorId):array
    {
        $this->actor($actorId);
        return $this->transaction(function()use($id,$expectedVersion,$actorId):array{
            $case=$this->caseRow($id,true);$this->owner($actorId,$case['asc_location_id']);$this->version($case,$expectedVersion);
            if(!in_array($case['workflow_status'],['DRAFT','RETURNED'],true))throw new DomainException('Only Draft or Returned historical cases may be submitted.',409);
            $bundle=$this->current($case);$this->validate($bundle,false);
            $next=ArpaAppointmentRules::transition($case['workflow_status']==='DRAFT'?'CREATED':$case['workflow_status'],'SUBMIT','CREATOR')['status'];
            $this->pdo->prepare('UPDATE '.self::CASE_TABLE.' SET workflow_status=?,submitted_by=?,submitted_at=NOW(),updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND version=?')
                ->execute([$next,$actorId,$actorId,$id,$expectedVersion]);
            $after=$this->caseRow($id,true);$this->event($after,'SUBMIT','CREATOR',$actorId,$case,$case['reason'],$case['evidence_reference']);return $this->present($after);
        });
    }

    public function review(string $id,string $action,int $expectedVersion,string $actorId,string $reason,string $evidenceReference):array
    {
        $this->actor($actorId);$action=strtoupper($action);$reason=$this->text($reason,'Review reason');$evidenceReference=$this->text($evidenceReference,'Evidence reference');
        if(!in_array($action,['VERIFY','APPROVE','RETURN_FOR_CORRECTION','REJECT'],true))throw new DomainException('Unsupported historical approval action.',422);
        return $this->transaction(function()use($id,$action,$expectedVersion,$actorId,$reason,$evidenceReference):array{
            $case=$this->caseRow($id,true);$this->version($case,$expectedVersion);
            // Retire an obsolete draft only through an independent, scoped ASC approver.
            // This lets an effective successor decision receive its own case without silently rebinding audit history.
            $staleDraft=$action==='REJECT'&&in_array($case['workflow_status'],['DRAFT','RETURNED'],true);
            if($staleDraft){
                $this->assertStaleDraftRetirement($case,$actorId,true);$stage='ASC';
                $next='REJECTED';
            }else{
                $profile=$this->reviewProfile($case,$actorId);$stage=$profile['stage'];
                if(in_array($action,['VERIFY','APPROVE'],true)&&$action!==$profile['action'])throw new DomainException('This action is not valid at the current historical approval stage.',409);
                if($action==='RETURN_FOR_CORRECTION'||$action==='REJECT')$this->permission($actorId,$action==='REJECT'?'arpa.appointment.reject':'arpa.appointment.return',$stage,$case['asc_location_id']);
                if(in_array($action,['VERIFY','APPROVE'],true)){$bundle=$this->current($case);$this->validate($bundle,$stage==='NATIONAL'&&$action==='APPROVE');}
                if($action==='APPROVE')$this->makerChecker($case,$stage,$actorId);
                $next=$action==='REJECT'?'REJECTED':ArpaAppointmentRules::transition($case['workflow_status'],$action,$stage)['status'];
            }
            $terminal=in_array($next,['NATIONAL_APPROVED','REJECTED'],true);
            $sql='UPDATE '.self::CASE_TABLE.' SET workflow_status=?,updated_by=?,updated_at=NOW(),version=version+1'.($terminal?',finalized_by=?,finalized_at=NOW()':'').' WHERE id=? AND version=?';
            $args=[$next,$actorId];if($terminal)$args[]=$actorId;$args[]=$id;$args[]=$expectedVersion;
            $this->pdo->prepare($sql)->execute($args);
            $after=$this->caseRow($id,true);$this->event($after,$action,$stage,$actorId,$case,$reason,$evidenceReference);return $this->present($after);
        });
    }

    private function bundle(string $correctionId,bool $lock):array
    {
        if(!ArpaAppointmentCorrectionDecision::lineageAvailable($this->pdo))throw new DomainException('Complete correction-lineage schema is required for historical approval.',503);
        $hint=$this->one('SELECT a.officer_id,a.arpa_division_location_id FROM arpa_appointment_data_correction c JOIN arpa_division_appointment a ON a.id=c.appointment_id WHERE c.id=?',[$correctionId]);
        $suffix=$lock?' FOR UPDATE':'';
        if($lock){$this->one('SELECT id FROM officer WHERE id=? FOR UPDATE',[$hint['officer_id']]);$this->one('SELECT id FROM location WHERE id=? FOR UPDATE',[$hint['arpa_division_location_id']]);}
        $c=$this->one('SELECT * FROM arpa_appointment_data_correction WHERE id=?'.$suffix,[$correctionId]);
        $a=$this->one('SELECT * FROM arpa_division_appointment WHERE id=?'.$suffix,[$c['appointment_id']]);
        $r=$this->one('SELECT * FROM arpa_division_appointment_request WHERE id=?'.$suffix,[$a['request_id']]);
        if($a['officer_id']!==$hint['officer_id']||$a['arpa_division_location_id']!==$hint['arpa_division_location_id'])throw new DomainException('The source identity changed. Retry from the current source.',409);
        if($r['record_origin']!=='LEGACY_IMPORT'||$a['record_origin']!=='LEGACY_IMPORT'||$r['request_type']!=='APPOINTMENT'||$r['deleted_at']!==null||(int)$a['legacy_history_only']!==0||(int)$r['legacy_history_only']!==0)throw new DomainException('An existing non-deleted authoritative imported appointment is required.',409);
        foreach(['officer_id','asc_location_id'] as $field)if($c[$field]!==$a[$field]||$r[$field]!==$a[$field])throw new DomainException('Source request, appointment and correction identities do not agree.',409);
        if($r['arpa_division_location_id']!==$a['arpa_division_location_id']||$r['appointment_type']!==$a['appointment_type']||$c['request_id']!==$r['id']||$c['correction_action']!=='RESOLVE_CANONICAL_ASSIGNMENT'||$c['resolution_status']!=='RESOLVED_BY_CORRECTION')throw new DomainException('An effective canonical-resolution correction matching this appointment is required.',409);
        $effective=ArpaAppointmentCorrectionDecision::effectivePredicate($this->pdo,'c');
        if($this->rows('SELECT c.id FROM arpa_appointment_data_correction c WHERE c.id=? AND '.$effective.$suffix,[$correctionId])===[])throw new DomainException('The source correction has been superseded.',409);
        $closures=$this->rows('SELECT * FROM arpa_division_appointment_closure WHERE appointment_id=? ORDER BY id'.$suffix,[$a['id']]);
        if(count($closures)>1)throw new DomainException('Ambiguous canonical closure evidence.',409);
        $officer=$this->one('SELECT id,arpa_service_permanency FROM officer WHERE id=?'.$suffix,[$a['officer_id']]);
        // Minimal cross-ASC dependency evidence: IDs/periods only, not other Officers' personnel records.
        $related=$this->rows("SELECT a.id,a.request_id,a.officer_id,a.appointment_type,a.asc_location_id,a.arpa_division_location_id,a.effective_from,a.legacy_history_only,a.legacy_exception,c.id closure_id,c.effective_to
            FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id
            WHERE ((a.officer_id=? AND a.appointment_type IN(?,'PERMANENT')) OR a.arpa_division_location_id=?)
              AND a.effective_from<=? AND COALESCE(c.effective_to,'9999-12-31')>=? ORDER BY a.id".$suffix,[$a['officer_id'],$a['appointment_type'],$a['arpa_division_location_id'],$closures[0]['effective_to']??'9999-12-31',$a['effective_from']]);
        $requests=$this->rows("SELECT id,officer_id,appointment_type,asc_location_id,arpa_division_location_id,request_type,requested_effective_from,requested_effective_to,workflow_status,record_origin,legacy_history_only,deleted_at,version FROM arpa_division_appointment_request
            WHERE ((officer_id=? AND appointment_type IN(?,'PERMANENT')) OR arpa_division_location_id=?)
              AND requested_effective_from<=? AND COALESCE(CASE WHEN request_type='TRANSFER' THEN NULL ELSE requested_effective_to END,'9999-12-31')>=? ORDER BY id".$suffix,[$a['officer_id'],$a['appointment_type'],$a['arpa_division_location_id'],$closures[0]['effective_to']??'9999-12-31',$a['effective_from']]);
        $decisions=$this->rows('SELECT * FROM arpa_appointment_data_correction WHERE appointment_id=? ORDER BY id'.$suffix,[$a['id']]);
        $relationships=$this->rows("SELECT * FROM location_relationship WHERE child_location_id IN(?,?) AND relationship_type IN('ASC_ARPA_DIVISION','DISTRICT_ASC') ORDER BY id".$suffix,[$a['arpa_division_location_id'],$a['asc_location_id']]);
        $workflow=$this->rows('SELECT * FROM arpa_appointment_workflow_action WHERE request_id=? ORDER BY id'.$suffix,[$r['id']]);
        $references=$this->rows('SELECT * FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=? ORDER BY id'.$suffix,[$r['id']]);
        return ['correction'=>$c,'appointment'=>$a,'request'=>$r,'closures'=>$closures,'officer'=>$officer,'related_appointments'=>$related,'related_requests'=>$requests,'correction_history'=>$decisions,'relationships'=>$relationships,'original_workflow'=>$workflow,'source_references'=>$references];
    }

    private function current(array $case,bool $lock=true):array
    {
        $bundle=$this->bundle($case['source_correction_id'],$lock);$this->identity($case,$bundle);
        $stored=$this->json(json_decode($case['baseline_json'],true,512,JSON_THROW_ON_ERROR));$current=$this->json($bundle);
        if(!hash_equals($case['baseline_hash'],hash('sha256',$stored))||!hash_equals($case['baseline_hash'],hash('sha256',$current)))throw new DomainException('The source baseline changed. Historical approval is stale; review a fresh baseline before resubmission.',409);
        return $bundle;
    }

    /** Read-only discovery; use the exact locked POST policy, never a second stale-source rule. */
    public function staleDraftRetirementIds(string $actorId):array
    {
        $this->actor($actorId);
        if(!Auth::can('arpa.appointment.asc-approve')||!Auth::can('arpa.appointment.reject')||!ScopeService::hasArpaStageScope($actorId,'ASC'))return [];
        $system=ScopeService::scopeProfile($actorId)['level']==='SYSTEM';
        $with=$system?'':ScopeService::arpaWorkflowScopeCte($actorId);$params=$system?[]:ScopeService::arpaWorkflowScopeParams($actorId);
        $scope=$system?'1=1':"EXISTS(SELECT 1 FROM workflow_scope_locations hs WHERE hs.stage='ASC' AND hs.id=hc.asc_location_id)";
        // Only same-scope editable cases reach the complete baseline assessment. No locks or writes on GET.
        $cases=$this->rows($with.'SELECT hc.* FROM '.self::CASE_TABLE." hc WHERE hc.workflow_status IN('DRAFT','RETURNED') AND {$scope} ORDER BY hc.id",$params);
        $ids=[];foreach($cases as $case)if($this->canRetireStaleDraft($case,$actorId))$ids[]=$case['id'];return $ids;
    }

    private function canRetireStaleDraft(array $case,string $actorId):bool
    {
        try{$this->assertStaleDraftRetirement($case,$actorId,false);return true;}catch(DomainException){return false;}
    }

    private function assertStaleDraftRetirement(array $case,string $actorId,bool $lock):void
    {
        if(!in_array($case['workflow_status'],['DRAFT','RETURNED'],true))throw new DomainException('Only a stale Draft or Returned case can be retired.',409);
        $this->permission($actorId,'arpa.appointment.asc-approve','ASC',$case['asc_location_id']);
        $this->permission($actorId,'arpa.appointment.reject','ASC',$case['asc_location_id']);$this->makerChecker($case,'ASC',$actorId);
        try{$this->current($case,$lock);}catch(DomainException $e){if($e->getCode()===409)return;throw $e;}
        throw new DomainException('A current draft cannot be rejected through stale-case retirement.',409);
    }

    private function validate(array $b,bool $final):void
    {
        $a=$b['appointment'];$to=$b['closures'][0]['effective_to']??null;
        foreach(array_filter([$a['effective_from'],$to]) as $date){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new DomainException('Invalid canonical appointment period.',409);}
        if($to!==null&&$to<$a['effective_from'])throw new DomainException('Invalid canonical closure period.',409);
        $policy=new ArpaAppointmentLocationPolicy();$parents=$policy->parents($this->pdo,$a['arpa_division_location_id'],'ASC_ARPA_DIVISION',$a['effective_from']);
        if(count($parents)!==1||$parents[0]['id']!==$a['asc_location_id'])throw new DomainException('Historical ASC hierarchy requires verification.',409);
        $districts=$policy->parents($this->pdo,$a['asc_location_id'],'DISTRICT_ASC',date('Y-m-d'));
        if(count($districts)!==1)throw new DomainException('Current District routing requires verification.',409);
        if((new ArpaDivisionContinuityService($this->pdo))->unresolvedDataIssues($a['arpa_division_location_id'])!==[])throw new DomainException('Resolve blocking Appointment Data Issues before historical approval.',409);
        $read=new ArpaAppointmentReadService($this->pdo);
        $read->assertDivisionPeriodDoesNotOverlap($a['arpa_division_location_id'],$a['effective_from'],$to,false,$a['request_id'],$a['id']);
        $read->assertAppointmentTypeAvailable($a['officer_id'],$a['appointment_type'],$a['arpa_division_location_id'],$a['effective_from'],$to,$a['request_id'],$a['id']);
        if($a['appointment_type']==='PERMANENT')return;
        $foundations=$this->rows("SELECT a.*,r.workflow_status foundation_workflow_status FROM arpa_division_appointment a JOIN arpa_division_appointment_request r ON r.id=a.request_id LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id
            WHERE a.officer_id=? AND a.appointment_type='PERMANENT' AND a.legacy_history_only=0 AND r.deleted_at IS NULL AND a.effective_from<=? AND (c.effective_to IS NULL OR c.effective_to>=?) ORDER BY a.id FOR UPDATE",[$a['officer_id'],$a['effective_from'],$a['effective_from']]);
        if(count($foundations)!==1)throw new DomainException('One authoritative Permanent foundation covering the dependent start date is required.',409);
        $foundation=$foundations[0];$cases=$this->rows('SELECT * FROM '.self::CASE_TABLE.' WHERE appointment_id=? ORDER BY created_at,id FOR UPDATE',[$foundation['id']]);
        $approved=false;
        foreach($cases as $dependency){
            if($dependency['workflow_status']==='NATIONAL_APPROVED'){
                try{$this->current($dependency);$approved=true;}catch(DomainException){/* Old approvals do not attest a changed source. */}
            }
        }
        if($final&&($cases!==[]||($foundation['record_origin']==='LEGACY_IMPORT'&&$foundation['foundation_workflow_status']!=='NATIONAL_APPROVED'))&&!$approved)throw new DomainException('Complete the Permanent foundation historical approval before final dependent approval.',409);
    }

    private function identity(array $case,array $b):void
    {
        foreach(['appointment_id'=>'id','request_id'=>'request_id','officer_id'=>'officer_id','asc_location_id'=>'asc_location_id'] as $field=>$source)if($case[$field]!==$b['appointment'][$source])throw new DomainException('The case no longer matches its protected source identity.',409);
    }
    private function actor(string $id):void
    {
        if(!Auth::isCurrentUser($id)||Auth::activeContext(false)===null)throw new DomainException('An authenticated current actor and active Working Context are required.',403);
    }
    private function permission(string $actor,string $permission,string $stage,string $asc):void
    {
        $this->actor($actor);if(!Auth::can($permission)||!ScopeService::canAccessCurrentArpaStage($actor,$stage,$asc))throw new DomainException('Historical approval is outside your current permission or geographic scope.',403);
    }
    private function owner(string $actor,string $asc):void
    {
        $this->permission($actor,'arpa.appointment.submit','ASC',$asc);$c=Auth::activeContext(false);
        if($c['role_code']!=='ASC_SUBJECT_OFFICER'||$c['role_level']!=='ASC'||$c['scope_type']!=='ASC'||$c['scope_mode']!=='EXACT'||$c['location_id']!==$asc)throw new DomainException('An exact assigned ASC Subject Officer context is required.',403);
    }
    private function reviewProfile(array $case,string $actor):array
    {
        foreach((new ArpaWorkflowQueuePolicy($this->pdo))->profiles($actor) as $profile)if($profile['status']===$case['workflow_status']){
            $this->permission($actor,$profile['permission'],$profile['stage'],$case['asc_location_id']);return $profile;
        }
        throw new DomainException('No authorized action exists at this historical approval stage.',403);
    }
    private function reader(array $case,string $actor):void
    {
        try{$this->owner($actor,$case['asc_location_id']);return;}catch(DomainException){}
        try{$this->reviewProfile($case,$actor);return;}catch(DomainException){}
        foreach((new ArpaWorkflowQueuePolicy($this->pdo))->profiles($actor) as $profile){
            if(!ScopeService::canAccessCurrentArpaStage($actor,$profile['stage'],$case['asc_location_id']))continue;
            $extra=$profile['stage']==='ASC'&&$profile['action']==='APPROVE'?" OR (action='REJECT' AND previous_status IN('DRAFT','RETURNED'))":'';
            if($this->rows('SELECT id FROM '.self::EVENT_TABLE." WHERE approval_case_id=? AND user_id=? AND stage=? AND (action=? OR (action IN('RETURN_FOR_CORRECTION','REJECT') AND previous_status=?) {$extra}) LIMIT 1",[$case['id'],$actor,$profile['stage'],$profile['action'],$profile['status']])!==[])return;
        }
        if(in_array($case['workflow_status'],['DRAFT','RETURNED'],true))try{
            $this->assertStaleDraftRetirement($case,$actor,false);return;
        }catch(DomainException){}
        throw new DomainException('You cannot inspect this historical approval case.',403);
    }
    private function makerChecker(array $case,string $stage,string $actor):void
    {
        $source=$this->one('SELECT corrected_by FROM arpa_appointment_data_correction WHERE id=?',[$case['source_correction_id']]);
        if(in_array($actor,[$case['created_by'],$case['submitted_by'],$source['corrected_by']],true))throw new DomainException('Maker-checker prevents approval of your own historical case or source correction.',403);
        if($this->rows('SELECT id FROM '.self::EVENT_TABLE." WHERE approval_case_id=? AND stage=? AND action='VERIFY' AND user_id=? LIMIT 1",[$case['id'],$stage,$actor])!==[])throw new DomainException('A verifier cannot approve the same historical case by switching roles.',403);
    }
    private function version(array $case,int $expected):void{if($expected<0||(int)$case['version']!==$expected)throw new DomainException('The historical case changed. Reload it before continuing.',409);}
    private function input(array &$input):void
    {
        if(array_diff(array_keys($input),['reason','evidence_reference','remarks'])!==[])throw new DomainException('Protected or unsupported historical case input.',422);
        $input['reason']=$this->text($input['reason']??'','Reason');$input['evidence_reference']=$this->text($input['evidence_reference']??'','Evidence reference');
        if(isset($input['remarks']))$input['remarks']=is_string($input['remarks'])&&trim($input['remarks'])===''?null:$this->text($input['remarks'],'Remarks',2000);
    }
    private function text(mixed $value,string $label,int $max=1000):string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||trim($value)===''||mb_strlen($value)>$max||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value))throw new DomainException($label.' is required and must be valid text.',422);return trim($value);
    }
    private function caseRow(string $id,bool $lock=false):array
    {
        if($lock){$hint=$this->one('SELECT officer_id FROM '.self::CASE_TABLE.' WHERE id=?',[$id]);$this->one('SELECT id FROM officer WHERE id=? FOR UPDATE',[$hint['officer_id']]);}
        $case=$this->one('SELECT * FROM '.self::CASE_TABLE.' WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);
        if($lock&&$case['officer_id']!==$hint['officer_id'])throw new DomainException('The case identity changed.',409);return $case;
    }
    private function event(array $after,string $action,string $stage,string $actor,?array $before,string $reason,string $evidence):void
    {
        $this->pdo->prepare('INSERT INTO '.self::EVENT_TABLE.'(approval_case_id,action,stage,user_id,previous_status,new_status,reason,evidence_reference,before_json,after_json) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$after['id'],$action,$stage,$actor,$before['workflow_status']??null,$after['workflow_status'],$reason,$evidence,$before===null?null:$this->json($before),$this->json(['case'=>$after,'active_context'=>ArpaAdministrativePolicy::auditContext(Auth::activeContext(false))])]);
    }
    private function present(array $case):array{return ['case'=>$case,'baseline'=>json_decode($case['baseline_json'],true,512,JSON_THROW_ON_ERROR),'events'=>$this->rows('SELECT * FROM '.self::EVENT_TABLE.' WHERE approval_case_id=? ORDER BY id',[$case['id']])];}
    private function rows(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    private function one(string $sql,array $params):array{$rows=$this->rows($sql,$params);if(count($rows)!==1)throw new DomainException('Historical source or case was not found or is ambiguous.',404);return $rows[0];}
    private function json(mixed $value):string
    {
        $sort=function(mixed $v)use(&$sort):mixed{if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as &$item)$item=$sort($item);return $v;};
        return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    private function transaction(callable $work):mixed
    {
        $owns=!$this->pdo->inTransaction();if($owns)$this->pdo->beginTransaction();else $this->pdo->exec('SAVEPOINT historical_approval');
        try{$result=$work();if($owns)$this->pdo->commit();else $this->pdo->exec('RELEASE SAVEPOINT historical_approval');return $result;}
        catch(Throwable $e){if($this->pdo->inTransaction()){if($owns)$this->pdo->rollBack();else{$this->pdo->exec('ROLLBACK TO SAVEPOINT historical_approval');$this->pdo->exec('RELEASE SAVEPOINT historical_approval');}}throw $e;}
    }
}
