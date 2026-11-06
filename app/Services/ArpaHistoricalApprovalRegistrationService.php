<?php
declare(strict_types=1);
namespace App\Services;

use DomainException;
use PDO;
use Throwable;

/** CLI discovery/registration only. Eligibility here is for a DRAFT, never for approval. */
final class ArpaHistoricalApprovalRegistrationService
{
    public function __construct(private readonly PDO $pdo){}

    public function manifest(string $actorId,bool $lock=false):array
    {
        ArpaHistoricalAppointmentApprovalService::assertCliRegistrationOperator($actorId);
        if($lock&&!$this->pdo->inTransaction())throw new DomainException('Registration locks require a transaction.',409);
        if(!(new ArpaHistoricalApprovalReadService($this->pdo))->available()||!ArpaAppointmentCorrectionDecision::lineageAvailable($this->pdo)){
            throw new DomainException('Complete correction-lineage and migration 067 schemas must be verified before registration. No migration is run by this command.',503);
        }
        $tables=$this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN('arpa_appointment_historical_approval_case','arpa_appointment_historical_approval_event') AND engine='InnoDB'")->fetchColumn();
        $triggers=$this->pdo->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND event_object_table='arpa_appointment_historical_approval_event' AND trigger_name IN('trg_hist_approval_event_no_update','trg_hist_approval_event_no_delete') AND action_timing='BEFORE' AND event_manipulation IN('UPDATE','DELETE')")->fetchColumn();
        if((int)$tables!==2||(int)$triggers!==2)throw new DomainException('Transactional historical case/event tables and append-only triggers are required.',503);
        $identity=$this->pdo->query('SELECT DATABASE() database_name,@@server_uuid server_uuid,@@port server_port,@@version mysql_version')->fetch(PDO::FETCH_ASSOC);
        $sql="SELECT a.id appointment_id,a.request_id,a.officer_id,a.asc_location_id,a.arpa_division_location_id,
            a.appointment_type,a.effective_from,r.workflow_status original_workflow_status,
            o.dad_number officer_number,o.name_with_initials officer_name,al.dad_number asc_number,al.name_en asc_name,
            ar.dad_number division_number,ar.name_en division_name
            FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_request r ON r.id=a.request_id
            LEFT JOIN officer o ON o.id=a.officer_id LEFT JOIN location al ON al.id=a.asc_location_id
            LEFT JOIN location ar ON ar.id=a.arpa_division_location_id
            WHERE a.record_origin='LEGACY_IMPORT' AND a.legacy_history_only=0 AND a.legacy_exception=1
              AND a.approved_at IS NULL AND (r.workflow_status='CREATED' OR r.id IS NULL)
            ORDER BY a.officer_id,a.arpa_division_location_id,a.id";
        $rows=[];$summary=['discovered'=>0,'ELIGIBLE'=>0,'ALREADY_REGISTERED'=>0,'MANUAL_REVIEW'=>0,'by_type'=>[]];
        foreach($this->rows($sql) as $row){
            $result=$this->assess($row,$actorId,$lock);$rows[]=$result;$summary['discovered']++;$summary[$result['classification']]++;
            $type=$row['appointment_type'];$summary['by_type'][$type]=($summary['by_type'][$type]??0)+1;
        }
        ksort($summary['by_type']);
        $report=['database'=>$identity,'operator_id'=>$actorId,'operator_context'=>ArpaAdministrativePolicy::auditContext(\App\Core\Auth::activeContext(false)),'summary'=>$summary,'candidates'=>$rows];
        $report['manifest_hash']=hash('sha256',$this->json($report));
        $report['confirmation']='REGISTER HISTORICAL DRAFTS '.$identity['database_name'].' '.$report['manifest_hash'];
        return $report;
    }

    /** Entire reviewed batch rolls back on a stale source or any case/event insertion failure. */
    public function apply(string $actorId,string $expectedHash,string $confirmation,array $input):array
    {
        ArpaHistoricalAppointmentApprovalService::assertCliRegistrationOperator($actorId);
        if(!preg_match('/^[a-f0-9]{64}$/D',$expectedHash))throw new DomainException('Supply the exact dry-run manifest hash.',422);
        foreach(['reason','evidence_reference'] as $field){
            $v=$input[$field]??null;
            if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||trim($v)===''||mb_strlen($v)>1000||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$v))throw new DomainException('A valid registration reason and evidence reference are required.',422);
        }
        if(array_diff(array_keys($input),['reason','evidence_reference'])!==[])throw new DomainException('Unsupported registration input.',422);
        if($this->pdo->inTransaction())throw new DomainException('CLI batch registration requires its own transaction.',409);
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->pdo->beginTransaction();
        try{
            $report=$this->manifest($actorId,true);
            if(!hash_equals($expectedHash,$report['manifest_hash'])||!hash_equals($report['confirmation'],$confirmation))throw new DomainException('Manifest or confirmation changed. Review a fresh dry-run; nothing was registered.',409);
            $registered=[];$service=new ArpaHistoricalAppointmentApprovalService($this->pdo);
            foreach($report['candidates'] as $row){
                if($row['classification']!=='ELIGIBLE')continue;
                $case=$service->registerFromCli($row['correction_id'],$input,$actorId,$row['baseline_hash']);
                if($case['case']['workflow_status']!=='DRAFT'||count($case['events'])!==1||$case['events'][0]['action']!=='REGISTER')throw new DomainException('Registration did not produce a new Draft-only case.',409);
                $registered[]=['appointment_id'=>$row['appointment_id'],'case_id'=>$case['case']['id'],'workflow_status'=>'DRAFT'];
            }
            $this->pdo->commit();return ['mode'=>'APPLY','reviewed_manifest_hash'=>$expectedHash,'summary'=>$report['summary'],'registered_count'=>count($registered),'registered'=>$registered];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function assess(array $row,string $actorId,bool $lock):array
    {
        $row+=['effective_to'=>null,'closure_id'=>null,'correction_id'=>null,'correction_action'=>null,'resolution_status'=>null,
            'correction_reason'=>null,'correction_evidence'=>null,'correction_decisions'=>[],'existing_cases'=>[],
            'baseline_hash'=>null,'case_id'=>null,'case_status'=>null];
        try{
            $suffix=$lock?' FOR UPDATE':'';$a=$this->one('SELECT * FROM arpa_division_appointment WHERE id=?',[$row['appointment_id']]);
            // Acquire locks in the Phase 1 officer -> Division -> source order, never by changing source data.
            if($lock){$this->one('SELECT id FROM officer WHERE id=? FOR UPDATE',[$a['officer_id']]);$this->one('SELECT id FROM location WHERE id=? FOR UPDATE',[$a['arpa_division_location_id']]);}
            $a=$this->one('SELECT * FROM arpa_division_appointment WHERE id=?'.$suffix,[$row['appointment_id']]);
            $closures=$this->rows('SELECT * FROM arpa_division_appointment_closure WHERE appointment_id=? ORDER BY id'.$suffix,[$a['id']]);
            if(count($closures)===1){$row['effective_to']=$closures[0]['effective_to'];$row['closure_id']=$closures[0]['id'];}
            $effective=ArpaAppointmentCorrectionDecision::effectivePredicate($this->pdo,'c');
            $decisions=$this->rows('SELECT c.*,('.$effective.') is_effective FROM arpa_appointment_data_correction c WHERE c.appointment_id=? OR c.request_id=? ORDER BY c.id'.$suffix,[$a['id'],$a['request_id']]);
            foreach($decisions as $decision){$item=array_intersect_key($decision,array_flip(['id','correction_action','resolution_status','supersedes_correction_id','amendment_request_id']));$item['effective_predicate_match']=(bool)$decision['is_effective'];$row['correction_decisions'][]=$item;}
            $r=$this->one('SELECT * FROM arpa_division_appointment_request WHERE id=?'.$suffix,[$a['request_id']]);
            if($a['request_id']!==$row['request_id']||$a['officer_id']!==$row['officer_id']||$a['asc_location_id']!==$row['asc_location_id']||$a['arpa_division_location_id']!==$row['arpa_division_location_id'])throw new DomainException('Candidate identity changed during discovery.',409);
            if($a['record_origin']!=='LEGACY_IMPORT'||$a['approved_at']!==null||(int)$a['legacy_history_only']!==0||(int)$a['legacy_exception']!==1
                ||$r['record_origin']!=='LEGACY_IMPORT'||$r['request_type']!=='APPOINTMENT'||$r['workflow_status']!=='CREATED'||(int)$r['legacy_history_only']!==0||(int)$r['legacy_exception']!==1)throw new DomainException('Source flags or original workflow do not match the historical registration population.',409);
            if($r['deleted_at']!==null)throw new DomainException('Source request is soft-deleted or administratively excluded.',409);
            if($this->rows("SELECT id FROM audit_event WHERE target_id=? AND action_key='arpa.appointment.workflow-request.admin-delete'".$suffix,[$r['id']])!==[])throw new DomainException('Source request has administrative exclusion/deletion history; manual review is required.',409);
            if(count($this->rows('SELECT id FROM arpa_division_appointment WHERE request_id=? ORDER BY id'.$suffix,[$r['id']]))!==1)throw new DomainException('The source request has duplicate canonical appointment representations.',409);
            foreach(['officer_id','asc_location_id','arpa_division_location_id','appointment_type'] as $field)if($a[$field]!==$r[$field])throw new DomainException('Request and canonical appointment identities disagree.',409);
            if(!in_array($a['appointment_type'],ArpaAppointmentRules::APPOINTMENT_TYPES,true))throw new DomainException('The canonical appointment type is unsupported.',409);
            if(!$row['officer_number']||!$row['officer_name']||!$row['asc_number']||!$row['division_number'])throw new DomainException('Officer or location master references are incomplete.',409);
            foreach(['asc_location_id'=>'ASC','arpa_division_location_id'=>'ARPA_DIVISION'] as $field=>$type){
                $l=$this->one('SELECT t.system_key FROM location l JOIN location_type t ON t.id=l.location_type_id WHERE l.id=?',[$a[$field]]);
                if($l['system_key']!==$type)throw new DomainException('Invalid canonical location type.',409);
            }
            if(count($closures)>1)throw new DomainException('Multiple canonical closures require manual review.',409);
            foreach(array_filter([$a['effective_from'],$row['effective_to']]) as $date){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new DomainException('Canonical effective dates are invalid.',409);}
            if($row['effective_to']!==null&&$row['effective_to']<$a['effective_from'])throw new DomainException('Canonical closure precedes the appointment.',409);
            $this->lineage($decisions,$a,$lock);
            if(isset($closures[0]['data_correction_id'])&&!in_array($closures[0]['data_correction_id'],array_column($decisions,'id'),true))throw new DomainException('Canonical closure references an unverified correction decision.',409);
            $eligible=[];
            foreach($decisions as $c){
                if(!(int)$c['is_effective'])continue;
                if($c['correction_action']==='KEEP_AS_HISTORICAL_EXCEPTION')throw new DomainException('An effective KEEP decision requires manual historical-authority review.',409);
                if($c['correction_action']==='RESOLVE_CANONICAL_ASSIGNMENT'&&$c['resolution_status']==='RESOLVED_BY_CORRECTION')$eligible[]=$c;
            }
            if(count($eligible)!==1)throw new DomainException(count($eligible)===0?'No effective canonical-resolution correction is linked.':'Multiple effective canonical-resolution decisions are ambiguous.',409);
            $c=$eligible[0];$row['correction_id']=$c['id'];$row['correction_action']=$c['correction_action'];$row['resolution_status']=$c['resolution_status'];$row['correction_reason']=$c['correction_reason'];$row['correction_evidence']=$c['evidence_reference'];
            $pending=$this->rows("SELECT id FROM arpa_appointment_correction_amendment_request WHERE (appointment_id=? OR source_correction_id=?) AND workflow_status NOT IN('NATIONAL_APPROVED','REJECTED','WITHDRAWN')".$suffix,[$a['id'],$c['id']]);
            if($pending!==[])throw new DomainException('An active correction amendment must be completed or withdrawn first.',409);
            $refs=$this->rows('SELECT * FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=? ORDER BY id'.$suffix,[$r['id']]);
            if($refs===[])throw new DomainException('Verified legacy source references are missing.',409);
            foreach($refs as $ref)if(!trim((string)$ref['source_system'])||!trim((string)$ref['source_table'])||!trim((string)$ref['legacy_appointment_id'])
                ||($ref['target_appointment_id']!==null&&$ref['target_appointment_id']!==$a['id']))throw new DomainException('Legacy source references are incomplete or point to another appointment.',409);
            $preview=(new ArpaHistoricalAppointmentApprovalService($this->pdo))->registrationPreview($c['id'],$actorId,true,$lock);$row['baseline_hash']=$preview['baseline_hash'];
            foreach($preview['cases'] as $case)$row['existing_cases'][]=array_intersect_key($case,array_flip(['id','source_correction_id','workflow_status']));
            $same=[];$conflicting=[];
            foreach($preview['cases'] as $case){
                if($case['source_correction_id']===$c['id'])$same[]=$case;
                elseif($case['active_appointment_id']!==null)$conflicting[]=$case;
            }
            if($conflicting!==[]||count($same)>1)throw new DomainException('A conflicting historical approval case already exists.',409);
            if($same!==[]){
                $case=$same[0];$row['case_id']=$case['id'];$row['case_status']=$case['workflow_status'];
                foreach(['request_id','officer_id','asc_location_id'] as $field)if($case[$field]!==$a[$field])throw new DomainException('Existing case source identities disagree.',409);
                $events=$this->rows("SELECT id,user_id,new_status FROM arpa_appointment_historical_approval_event WHERE approval_case_id=? AND action='REGISTER'".$suffix,[$case['id']]);
                if(count($events)!==1||$events[0]['user_id']!==$case['created_by']||$events[0]['new_status']!=='DRAFT')throw new DomainException('Existing registration audit is missing or inconsistent.',409);
                return $row+['classification'=>'ALREADY_REGISTERED','reason'=>'An existing case is preserved without resetting status, baseline or events.'];
            }
            return $row+['classification'=>'ELIGIBLE','reason'=>'Eligible for DRAFT registration only; submission and approval validations remain independent.'];
        }catch(DomainException $e){if(!in_array($e->getCode(),[404,409,422],true))throw $e;return $row+['classification'=>'MANUAL_REVIEW','reason'=>$e->getMessage()];}
    }

    /** Structural checks complement (and never replace) the shared effective-decision predicate. */
    private function lineage(array $decisions,array $a,bool $lock):void
    {
        $map=[];foreach($decisions as $c)$map[$c['id']]=$c;
        foreach($decisions as $c){
            foreach(['appointment_id'=>'id','request_id'=>'request_id','officer_id'=>'officer_id','asc_location_id'=>'asc_location_id'] as $key=>$field)if($c[$key]!==$a[$field])throw new DomainException('Correction history contains mismatched source identities.',409);
            $previous=$c['supersedes_correction_id'];
            if($previous!==null&&!isset($map[$previous]))throw new DomainException('Correction lineage has a missing or cross-record predecessor.',409);
            $seen=[];$walk=$c;
            while($walk!==null){if(isset($seen[$walk['id']]))throw new DomainException('Correction lineage contains a cycle.',409);$seen[$walk['id']]=true;$p=$walk['supersedes_correction_id'];$walk=$p===null?null:($map[$p]??null);}
            if($c['amendment_request_id']!==null){
                $m=$this->one('SELECT * FROM arpa_appointment_correction_amendment_request WHERE id=?'.($lock?' FOR UPDATE':''),[$c['amendment_request_id']]);
                if($previous===null||$m['source_correction_id']!==$previous||$m['appointment_id']!==$a['id']||$m['officer_id']!==$a['officer_id']||$m['asc_location_id']!==$a['asc_location_id']
                    ||$m['workflow_status']!=='NATIONAL_APPROVED'||$m['applied_correction_id']!==$c['id'])throw new DomainException('Correction amendment lineage is incomplete, unapplied or inconsistent.',409);
            }
        }
    }
    private function rows(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    private function one(string $sql,array $params):array{$r=$this->rows($sql,$params);if(count($r)!==1)throw new DomainException('A linked source record is missing or ambiguous.',409);return $r[0];}
    private function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
}
