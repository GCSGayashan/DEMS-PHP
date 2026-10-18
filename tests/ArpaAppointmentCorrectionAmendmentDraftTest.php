<?php
declare(strict_types=1);

$interfaceFeatureEnabled=($argv[1]??'')==='--enabled-interface';
if($interfaceFeatureEnabled){$_ENV['FEATURE_ARPA_CORRECTION_AMENDMENTS']='true';putenv('FEATURE_ARPA_CORRECTION_AMENDMENTS=true');}

use App\Core\{Auth,Database,DataTableQuery,DataTableRegistry,DataTableRequest};
use App\Services\{ArpaAppointmentCorrectionAmendmentService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentCorrectionAmendmentDraftTest
{
    private bool $interfaceFeatureEnabled=false;
    private const ACTOR='00000000-0000-4000-8000-000000000001';
    private const OTHER_ASC_ACTOR='00000000-0000-4000-8000-000000000002';
    private const WRONG_ROLE_ACTOR='00000000-0000-4000-8000-000000000003';
    private const ASC_VERIFIER='00000000-0000-4000-8000-000000000004';
    private const ASC_APPROVER='00000000-0000-4000-8000-000000000005';
    private const DISTRICT_VERIFIER='00000000-0000-4000-8000-000000000006';
    private const DISTRICT_APPROVER='00000000-0000-4000-8000-000000000007';
    private const NATIONAL_VERIFIER='00000000-0000-4000-8000-000000000008';
    private const NATIONAL_APPROVER='00000000-0000-4000-8000-000000000009';
    private const OUTSIDE_DISTRICT='00000000-0000-4000-8000-000000000010';
    private const VIEWER='00000000-0000-4000-8000-000000000011';
    private const ASC_ONE='10000000-0000-4000-8000-000000000001';
    private const ASC_TWO='10000000-0000-4000-8000-000000000002';
    private const MAIN_CORRECTION='50000000-0000-4000-8000-000000000001';
    private const REQUEST_ONLY_CORRECTION='50000000-0000-4000-8000-000000000002';
    private const MISMATCHED_CORRECTION='50000000-0000-4000-8000-000000000003';
    private const SUPERSEDED_CORRECTION='50000000-0000-4000-8000-000000000004';
    private const KEEP_CORRECTION='50000000-0000-4000-8000-000000000005';
    private const ATOMIC_CORRECTION='50000000-0000-4000-8000-000000000006';
    private const WORKFLOW_CORRECTION='50000000-0000-4000-8000-000000000008';
    private const RETURN_CORRECTION='50000000-0000-4000-8000-000000000009';
    private const REJECT_CORRECTION='50000000-0000-4000-8000-000000000010';
    private const STALE_CORRECTION='50000000-0000-4000-8000-000000000011';
    private const EVENT_FAILURE_CORRECTION='50000000-0000-4000-8000-000000000012';
    private const PROMOTION_CORRECTION='50000000-0000-4000-8000-000000000013';
    private const PARALLEL_CORRECTION='50000000-0000-4000-8000-000000000014';
    private const MULTI_CORRECTION='50000000-0000-4000-8000-000000000015';
    private const RELATED_STALE_CORRECTION='50000000-0000-4000-8000-000000000016';
    private const CROSS_ASC_CORRECTION='50000000-0000-4000-8000-000000000017';
    private const FINAL_EVENT_CORRECTION='50000000-0000-4000-8000-000000000018';

    private PDO $server;
    private PDO $pdo;
    private string $databaseName;
    private ReflectionProperty $databaseProperty;
    private ?PDO $previousDatabase=null;
    private int $assertions=0;

    public function run(array $argv):int
    {
        $this->interfaceFeatureEnabled=($argv[1]??'')==='--enabled-interface';
        $config=config('database');
        $this->databaseName='dems_test_arpa_corr_amendment_draft_'.bin2hex(random_bytes(4));
        if(preg_match('/^dems_test_arpa_corr_amendment_draft_[a-f0-9]{8}$/D',$this->databaseName)!==1){
            throw new RuntimeException('Unsafe isolated test database name.');
        }
        $this->server=new PDO(
            sprintf('mysql:host=%s;port=%s;charset=%s',$config['host'],$config['port'],$config['charset']),
            $config['username'],$config['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]
        );

        try{
            $this->server->exec('CREATE DATABASE `'.$this->databaseName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $this->pdo=$this->database();
            $this->createFixtureSchema();
            $migration=(string)file_get_contents(BASE_PATH.'/database/migrations/062_arpa_appointment_correction_amendment_foundation.sql');
            $this->pdo->exec($migration);
            $this->insertFixtures();
            $this->installIsolatedDatabase();
            $this->exerciseService();
            $this->exerciseWorkflow();
            $this->exerciseConsistencyCheckpoint();
        }finally{
            $_SESSION=[];
            Auth::forgetRequestCache();
            if(isset($this->databaseProperty))$this->databaseProperty->setValue(null,$this->previousDatabase);
            if(isset($this->server))$this->server->exec('DROP DATABASE IF EXISTS `'.$this->databaseName.'`');
        }

        if(!$this->interfaceFeatureEnabled)$this->enabledInterfaceChild();
        echo "ArpaAppointmentCorrectionAmendmentDraftTest".($this->interfaceFeatureEnabled?' (enabled interface)':'').": {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function exerciseService():void
    {
        $service=new ArpaAppointmentCorrectionAmendmentService($this->pdo);
        $proposal=$this->proposal('SET_EFFECTIVE_TO',['effective_to'=>'2025-12-31','end_reason_id'=>null]);

        $this->useContext(self::WRONG_ROLE_ACTOR);
        $this->throwsDomain(fn()=>$service->createDraft(self::MAIN_CORRECTION,$proposal,self::WRONG_ROLE_ACTOR),'wrong role cannot create a draft');
        $this->useContext(self::OTHER_ASC_ACTOR);
        $this->throwsDomain(fn()=>$service->createDraft(self::MAIN_CORRECTION,$proposal,self::OTHER_ASC_ACTOR),'another ASC cannot create a draft');
        $this->throwsDomain(fn()=>$service->eligibility(self::MAIN_CORRECTION,self::OTHER_ASC_ACTOR),'another ASC cannot inspect direct-route eligibility');

        $this->useContext(self::ACTOR);
        $eligible=$service->eligibility(self::MAIN_CORRECTION,self::ACTOR,true);
        $this->same(true,$eligible['eligible'],'authorized exact-ASC actor sees eligible source decision');
        $this->same('30000000-0000-4000-8000-000000000001',$eligible['baseline']['appointment']['id'],'eligibility returns the server-derived canonical baseline');
        $this->same('Division One',$eligible['arpa_divisions'][0]['name_en'],'form eligibility supplies approved ASC ARPA Division options');
        $this->same('Transfer',$eligible['end_reasons'][0]['name_en'],'form eligibility supplies active end-reason options');
        $requestOnly=$service->eligibility(self::REQUEST_ONLY_CORRECTION,self::ACTOR);
        $this->same('REQUEST_ONLY',$requestOnly['code'],'request-only correction explains canonical appointment ineligibility');
        $superseded=$service->eligibility(self::SUPERSEDED_CORRECTION,self::ACTOR);
        $this->same('SUPERSEDED',$superseded['code'],'explicit supersession makes source ineligible');
        $this->throwsDomain(fn()=>$service->createDraft(self::REQUEST_ONLY_CORRECTION,$proposal,self::ACTOR),'request-only correction is ineligible');
        $this->throwsDomain(fn()=>$service->createDraft(self::MISMATCHED_CORRECTION,$proposal,self::ACTOR),'mismatched source identity is rejected');
        $this->throwsDomain(fn()=>$service->createDraft(self::SUPERSEDED_CORRECTION,$proposal,self::ACTOR),'explicitly superseded source is rejected');
        foreach(['baseline_json','baseline_hash','workflow_status','applied_correction_id','appointment_id','officer_id','asc_location_id'] as $protected){
            $this->throwsDomain(fn()=>$service->createDraft(self::MAIN_CORRECTION,$proposal+[$protected=>'forged'],self::ACTOR),'protected '.$protected.' field is rejected');
        }
        $this->throwsDomain(fn()=>$service->createDraft(self::MAIN_CORRECTION,$this->proposal('SET_EFFECTIVE_TO',['effective_to'=>'not-a-date']),self::ACTOR),'invalid proposed date is rejected');
        $this->throwsDomain(fn()=>$service->createDraft(self::MAIN_CORRECTION,$this->proposal('SET_EFFECTIVE_TO',['effective_to'=>'2025-12-31','workflow_status'=>'DRAFT']),self::ACTOR),'protected proposal field is rejected');
        $this->throwsDomain(fn()=>$service->createDraft(self::MAIN_CORRECTION,$this->proposal('UNSUPPORTED_ACTION',[]),self::ACTOR),'unsupported action is rejected');

        $operationalBefore=$this->operationalState();
        $first=$service->createDraft(self::MAIN_CORRECTION,$proposal,self::ACTOR);
        $this->same('DRAFT',$first['workflow_status'],'authorized exact-ASC actor creates DRAFT');
        $this->same(self::MAIN_CORRECTION,$first['source_correction_id'],'source identity is derived server-side');
        $this->same('30000000-0000-4000-8000-000000000001',$first['appointment_id'],'appointment identity is derived server-side');
        $this->same(self::ASC_ONE,$first['asc_location_id'],'ASC identity is derived server-side');
        $this->same(hash('sha256',$this->canonicalJson($first['baseline'])),$first['baseline_hash'],'baseline SHA-256 is computed from normalized baseline');
        $this->same(null,$first['baseline']['closure'],'absence of closure is retained in baseline');
        $this->same('DISTRICT_APPROVED',$first['baseline']['request']['workflow_status'],'request workflow status is captured');
        $this->same(7,$first['baseline']['request']['version'],'request version is captured');
        $this->same(null,$first['baseline']['request']['deleted_at'],'request deletion state is captured');
        $this->same(1,count($first['events']),'creation appends one event');
        $this->same('CREATE_DRAFT',$first['events'][0]['action'],'creation event is structured');
        $this->same($proposal['proposed_json'],$first['events'][0]['after_proposal']['proposed_json'],'creation event retains structured proposal evidence');
        $active=$service->eligibility(self::MAIN_CORRECTION,self::ACTOR);
        $this->same('ACTIVE_AMENDMENT',$active['code'],'active draft changes profile entry point to view');
        $this->same($first['id'],$active['active_amendment_id'],'active draft link targets exact amendment');
        $this->throwsDomain(fn()=>$service->createDraft(self::MAIN_CORRECTION,$proposal,self::ACTOR),'duplicate active amendment is rejected');

        $this->useContext(self::OTHER_ASC_ACTOR);
        $this->throwsDomain(fn()=>$service->get((string)$first['id'],self::OTHER_ASC_ACTOR),'unauthorized read is rejected');
        $this->useContext(self::ACTOR);

        $baselineJson=(string)$first['baseline_json'];
        $baselineHash=(string)$first['baseline_hash'];
        $edit=$this->proposal('CORRECT_EFFECTIVE_FROM',['effective_from'=>'2025-01-02'],'Changed reason','Changed remarks','Evidence 2');
        $edited=$service->updateDraft((string)$first['id'],$edit,0,self::ACTOR);
        $this->same(1,(int)$edited['version'],'DRAFT edit increments version');
        $this->same('CORRECT_EFFECTIVE_FROM',$edited['proposed_action'],'DRAFT edit changes whitelisted proposal');
        $this->same($baselineJson,$edited['baseline_json'],'DRAFT edit does not refresh baseline');
        $this->same($baselineHash,$edited['baseline_hash'],'DRAFT edit preserves baseline hash');
        $lastEvent=$edited['events'][count($edited['events'])-1];
        $this->same('EDIT_DRAFT',$lastEvent['action'],'DRAFT edit appends audit event');
        $this->same('SET_EFFECTIVE_TO',$lastEvent['before_proposal']['proposed_action'],'edit event retains prior action');
        $this->same('CORRECT_EFFECTIVE_FROM',$lastEvent['after_proposal']['proposed_action'],'edit event retains new action');
        $this->same('Changed reason',$lastEvent['after_proposal']['correction_reason'],'edit event retains changed reason');
        $this->same('Changed remarks',$lastEvent['after_proposal']['remarks'],'edit event retains changed remarks');
        $this->same('Evidence 2',$lastEvent['after_proposal']['evidence_reference'],'edit event retains changed evidence');
        $this->throwsDomain(fn()=>$service->updateDraft((string)$first['id'],$proposal,0,self::ACTOR),'stale edit version is rejected');

        $withdrawn=$service->withdraw((string)$first['id'],1,self::ACTOR,'No longer required');
        $this->same('WITHDRAWN',$withdrawn['workflow_status'],'DRAFT may be withdrawn');
        $this->same(2,(int)$withdrawn['version'],'withdraw increments version');
        $this->same(self::ACTOR,$withdrawn['finalized_by'],'withdraw records actor');
        $this->same(null,$withdrawn['applied_correction_id'],'withdraw never applies a correction');
        $this->same('WITHDRAW',$withdrawn['events'][count($withdrawn['events'])-1]['action'],'withdraw appends event');

        $second=$service->createDraft(self::MAIN_CORRECTION,$proposal,self::ACTOR);
        $this->same($baselineJson,$second['baseline_json'],'same unchanged records produce deterministic baseline JSON');
        $this->same($baselineHash,$second['baseline_hash'],'same unchanged records produce deterministic baseline hash');
        $this->pdo->prepare("UPDATE arpa_appointment_correction_amendment_request SET workflow_status='RETURNED' WHERE id=?")->execute([$second['id']]);
        $returned=$service->updateDraft((string)$second['id'],$edit,0,self::ACTOR);
        $this->same('RETURNED',$returned['workflow_status'],'RETURNED draft remains returned while edited');
        $this->same(1,(int)$returned['version'],'RETURNED edit increments version');
        $returnedWithdrawn=$service->withdraw((string)$second['id'],1,self::ACTOR);
        $this->same('WITHDRAWN',$returnedWithdrawn['workflow_status'],'RETURNED proposal may be withdrawn');

        $keepBefore=$this->row("SELECT * FROM arpa_appointment_data_correction WHERE id=?",[self::KEEP_CORRECTION]);
        $keepAppointmentBefore=$this->row("SELECT * FROM arpa_division_appointment WHERE id='30000000-0000-4000-8000-000000000005'");
        $keep=$service->createDraft(self::KEEP_CORRECTION,$this->proposal('RESOLVE_CANONICAL_ASSIGNMENT',[],'Reconsider legacy exception'),self::ACTOR);
        $this->same('DRAFT',$keep['workflow_status'],'KEEP_AS_HISTORICAL_EXCEPTION may be reconsidered as a draft');
        $this->same('2025-06-30',$keep['baseline']['closure']['effective_to'],'existing canonical closure is captured in baseline');
        $this->same('KEEP_AS_HISTORICAL_EXCEPTION',$keep['baseline']['source_correction']['correction_action'],'effective source decision is captured in baseline');
        $this->same($keepBefore,$this->row("SELECT * FROM arpa_appointment_data_correction WHERE id=?",[self::KEEP_CORRECTION]),'draft does not override KEEP correction');
        $this->same($keepAppointmentBefore,$this->row("SELECT * FROM arpa_division_appointment WHERE id='30000000-0000-4000-8000-000000000005'"),'draft does not promote KEEP appointment');

        $this->pdo->exec("CREATE TRIGGER fail_arpa_amendment_event BEFORE INSERT ON arpa_appointment_correction_amendment_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced event failure'");
        $this->throwsThrowable(fn()=>$service->createDraft(self::ATOMIC_CORRECTION,$proposal,self::ACTOR),'event failure aborts draft transaction');
        $this->pdo->exec('DROP TRIGGER fail_arpa_amendment_event');
        $this->same(0,$this->count("SELECT COUNT(*) FROM arpa_appointment_correction_amendment_request WHERE source_correction_id=?",[self::ATOMIC_CORRECTION]),'failed event insert rolls back request insert');

        $this->same($operationalBefore,$this->operationalState(),'create, edit and withdraw leave corrections, appointments, closures and normal requests unchanged');
        $this->staticNonOperationalGuard();
    }

    private function exerciseWorkflow():void
    {
        $service=new ArpaAppointmentCorrectionAmendmentService($this->pdo);
        $proposal=$this->proposal('MARK_HISTORICAL_ONLY',[],'Governed amendment workflow');
        $this->useContext(self::ACTOR);
        $workflow=$service->createDraft(self::WORKFLOW_CORRECTION,$proposal,self::ACTOR);
        $returned=$service->createDraft(self::RETURN_CORRECTION,$proposal,self::ACTOR);
        $rejected=$service->createDraft(self::REJECT_CORRECTION,$proposal,self::ACTOR);
        $stale=$service->createDraft(self::STALE_CORRECTION,$proposal,self::ACTOR);
        $eventFailure=$service->createDraft(self::EVENT_FAILURE_CORRECTION,$proposal,self::ACTOR);
        $operationalBefore=$this->operationalState();

        $workflow=$service->submit((string)$workflow['id'],0,self::ACTOR);
        $this->same('SUBMITTED',$workflow['workflow_status'],'DRAFT submission enters SUBMITTED');
        $this->same(self::ACTOR,$workflow['submitted_by'],'submission records its authenticated actor');
        $this->same(true,$workflow['submitted_at']!==null,'submission records its timestamp');

        foreach([self::WRONG_ROLE_ACTOR,self::OTHER_ASC_ACTOR,self::VIEWER] as $unauthorized){
            $this->useContext($unauthorized);
            $this->throwsDomain(fn()=>$service->reviewerGet((string)$workflow['id'],$unauthorized),'wrong role or ASC cannot read the review item');
            $this->throwsDomain(fn()=>$service->review((string)$workflow['id'],'VERIFY',1,$unauthorized),'wrong role or ASC cannot advance the review item');
        }
        $this->useContextRole(self::ASC_VERIFIER,'ASC_ADMIN');
        $this->throwsDomain(fn()=>$service->reviewerGet((string)$workflow['id'],self::ASC_VERIFIER),'an account cannot borrow ASC verification permission from its inactive Subject Officer context');
        $this->useContextRole(self::ASC_VERIFIER,'ASC_SUBJECT_OFFICER');
        $this->same('SUBMITTED',$service->reviewerGet((string)$workflow['id'],self::ASC_VERIFIER)['workflow_status'],'eligible ASC verifier has reviewer read access');
        $interface=$service->interfaceGet((string)$workflow['id'],self::ASC_VERIFIER);
        $this->same('VERIFY',$interface['interface_actions']['primary_review']['action'],'server-derived interface exposes ASC verification');
        if($this->interfaceFeatureEnabled){
            $pending=DataTableRegistry::definition('arpa-correction-amendments-pending');$pending['baseWhere'][]='ar.id=?';$pending['baseParams'][]=$workflow['id'];
            $this->same(1,(new DataTableQuery($this->pdo,$pending,new DataTableRequest(['length'=>10,'search'=>['value'=>'MARK_HISTORICAL_ONLY']])))->response()['recordsFiltered'],'pending list count/search match the actionable ASC verification item');
            $this->useContext(self::OTHER_ASC_ACTOR);$outside=DataTableRegistry::definition('arpa-correction-amendments-pending');$outside['baseWhere'][]='ar.id=?';$outside['baseParams'][]=$workflow['id'];$outsideQuery=new DataTableQuery($this->pdo,$outside,new DataTableRequest(['length'=>10]));
            $this->same(0,$outsideQuery->response()['recordsFiltered'],'pending list count does not leak another ASC amendment');
            $this->same(0,count(iterator_to_array($outsideQuery->exportRows())),'pending export uses the same no-leak scope predicate');
            $this->useContextRole(self::ASC_VERIFIER,'ASC_SUBJECT_OFFICER');
        }
        $workflow=$service->review((string)$workflow['id'],'VERIFY',1,self::ASC_VERIFIER);
        $this->same('ASC_VERIFIED',$workflow['workflow_status'],'ASC verification advances the amendment');
        $this->throwsDomain(fn()=>$service->review((string)$workflow['id'],'VERIFY',2,self::ASC_VERIFIER),'duplicate workflow click is rejected');
        $historical=$service->interfaceGet((string)$workflow['id'],self::ASC_VERIFIER);
        $this->same('ASC_VERIFIED',$historical['workflow_status'],'completed ASC verifier retains scoped read access after the request advances');
        $this->same(null,$historical['interface_actions']['primary_review'],'historical review access does not grant the next approval action');
        if($this->interfaceFeatureEnabled){
            $completed=DataTableRegistry::definition('arpa-correction-amendments-completed');$completed['baseWhere'][]='ae.amendment_request_id=?';$completed['baseParams'][]=$workflow['id'];$completedResponse=(new DataTableQuery($this->pdo,$completed,new DataTableRequest(['length'=>10])))->response();$this->same(1,$completedResponse['recordsFiltered'],'completed list contains the acting verifier event, distinct from pending work');
            $this->useContext(self::OTHER_ASC_ACTOR);$outsideCompleted=DataTableRegistry::definition('arpa-correction-amendments-completed');$outsideCompleted['baseWhere'][]='ae.amendment_request_id=?';$outsideCompleted['baseParams'][]=$workflow['id'];$this->same(0,(new DataTableQuery($this->pdo,$outsideCompleted,new DataTableRequest(['length'=>10])))->response()['recordsFiltered'],'completed count does not leak an event to another ASC');
            $this->useContext(self::VIEWER);$viewerDefinition=DataTableRegistry::definition('arpa-correction-amendments-completed');$this->same(false,($viewerDefinition['authorize'])(),'non-reviewer is denied completed-list endpoint authorization');
            $this->useContextRole(self::ASC_VERIFIER,'ASC_SUBJECT_OFFICER');
        }

        $this->pdo->prepare('UPDATE arpa_appointment_correction_amendment_request SET created_by=? WHERE id=?')->execute([self::ASC_APPROVER,$workflow['id']]);
        $this->useContext(self::ASC_APPROVER);
        $this->throwsDomain(fn()=>$service->review((string)$workflow['id'],'APPROVE',2,self::ASC_APPROVER),'ASC maker cannot approve their own amendment');
        $this->pdo->prepare('UPDATE arpa_appointment_correction_amendment_request SET created_by=? WHERE id=?')->execute([self::ACTOR,$workflow['id']]);
        $this->useContextRole(self::ASC_VERIFIER,'ASC_ADMIN');
        $makerInterface=$service->interfaceGet((string)$workflow['id'],self::ASC_VERIFIER);
        $this->same(null,$makerInterface['interface_actions']['primary_review'],'maker-checker suppresses approval button for the acting verifier');
        $this->throwsDomain(fn()=>$service->review((string)$workflow['id'],'APPROVE',2,self::ASC_VERIFIER),'one account cannot verify then approve in another active role context');
        $this->useContext(self::ASC_APPROVER);
        $this->same('APPROVE',$service->interfaceGet((string)$workflow['id'],self::ASC_APPROVER)['interface_actions']['primary_review']['action'],'authorized ASC approver receives the approval button');
        $workflow=$service->review((string)$workflow['id'],'APPROVE',2,self::ASC_APPROVER);
        $this->same('ASC_APPROVED',$workflow['workflow_status'],'ASC approval advances without operational application');

        $this->useContext(self::OUTSIDE_DISTRICT);
        $this->throwsDomain(fn()=>$service->reviewerGet((string)$workflow['id'],self::OUTSIDE_DISTRICT),'another District cannot read the amendment review item');
        $this->useContext(self::DISTRICT_VERIFIER);
        $this->same('ASC_APPROVED',$service->reviewerGet((string)$workflow['id'],self::DISTRICT_VERIFIER)['workflow_status'],'District reviewer receives narrowly scoped read access');
        $this->throwsDomain(fn()=>$service->get((string)$workflow['id'],self::DISTRICT_VERIFIER),'review access does not grant ASC draft ownership');
        $this->throwsDomain(fn()=>$service->updateDraft((string)$workflow['id'],$proposal,3,self::DISTRICT_VERIFIER),'review access does not grant draft edit rights');
        $workflow=$service->review((string)$workflow['id'],'VERIFY',3,self::DISTRICT_VERIFIER);
        $this->same('DISTRICT_VERIFIED',$workflow['workflow_status'],'District verification advances the amendment');
        $districtHistory=$service->interfaceGet((string)$workflow['id'],self::DISTRICT_VERIFIER);
        $this->same('HISTORICAL_REVIEWER',$districtHistory['viewer_access'],'completed District verifier retains historical reviewer access after advancement');
        $this->same(null,$districtHistory['interface_actions']['primary_review'],'historical District reviewer cannot perform the District approval');
        $this->useContext(self::DISTRICT_APPROVER);
        $workflow=$service->review((string)$workflow['id'],'APPROVE',4,self::DISTRICT_APPROVER);
        $this->same('DISTRICT_APPROVED',$workflow['workflow_status'],'District approval advances the amendment');
        $this->useContextRole(self::NATIONAL_VERIFIER,'NATIONAL_SUBJECT_OFFICER');
        $workflow=$service->review((string)$workflow['id'],'VERIFY',5,self::NATIONAL_VERIFIER);
        $this->same('NATIONAL_VERIFIED',$workflow['workflow_status'],'National verification is the final implemented intermediate stage');
        $sourceBeforeFinal=$this->row('SELECT * FROM arpa_appointment_data_correction WHERE id=?',[self::WORKFLOW_CORRECTION]);
        $this->useContext(self::DISTRICT_APPROVER);
        $this->throwsDomain(fn()=>$service->review((string)$workflow['id'],'APPROVE',6,self::DISTRICT_APPROVER),'District actor cannot perform final National approval');
        $this->useContext(self::NATIONAL_APPROVER);
        $workflow=$service->review((string)$workflow['id'],'APPROVE',6,self::NATIONAL_APPROVER);
        $this->same('NATIONAL_APPROVED',$workflow['workflow_status'],'National approval is terminal');
        $this->same(self::NATIONAL_APPROVER,$workflow['finalized_by'],'final approval records the National actor');
        $this->same(1,(int)$this->row("SELECT legacy_history_only FROM arpa_division_appointment WHERE id='30000000-0000-4000-8000-000000000008'")['legacy_history_only'],'approved proposal is applied to the existing appointment');
        $replacement=$this->row('SELECT * FROM arpa_appointment_data_correction WHERE amendment_request_id=?',[$workflow['id']]);
        $this->same(self::WORKFLOW_CORRECTION,$replacement['supersedes_correction_id'],'replacement correction explicitly supersedes the reviewed decision');
        $this->same($replacement['id'],$workflow['applied_correction_id'],'amendment links its applied replacement correction');
        $this->same(true,trim((string)$replacement['before_json'])!=='[]'&&trim((string)$replacement['after_json'])!=='[]','replacement correction stores structured before/after evidence');
        $this->same(1,$this->count("SELECT COUNT(*) FROM audit_event WHERE action_key='arpa.appointment.correction-amendment.apply' AND target_id=?",[$replacement['id']]),'final application appends one audit event');
        $this->same(1,$this->count("SELECT COUNT(*) FROM arpa_appointment_correction_amendment_event WHERE amendment_request_id=? AND action='APPROVE' AND stage='NATIONAL'",[$workflow['id']]),'one final National approval event is appended');
        $approvedInterface=$service->interfaceGet((string)$workflow['id'],self::NATIONAL_APPROVER);
        $this->same($replacement['id'],$approvedInterface['applied_correction_id'],'approved detail links the replacement correction');
        $this->same(null,$approvedInterface['interface_actions']['primary_review'],'terminal approved detail exposes no further review control');
        $this->same($sourceBeforeFinal,$this->row('SELECT * FROM arpa_appointment_data_correction WHERE id=?',[self::WORKFLOW_CORRECTION]),'original correction ledger row remains unchanged and traceable');
        $this->useContext(self::ACTOR);
        $this->throwsDomain(fn()=>(new \App\Services\ArpaAppointmentDataIssueCorrectionService($this->pdo))->canonicalCorrectionForm('30000000-0000-4000-8000-000000000008',self::ACTOR),'superseded canonical-resolution decision is no longer effective even while the amendment feature flag is off');
        $this->useContext(self::NATIONAL_APPROVER);
        $this->throwsDomain(fn()=>$service->review((string)$workflow['id'],'APPROVE',7,self::NATIONAL_APPROVER),'repeated final approval is rejected');
        $this->throwsDomain(fn()=>(new \App\Services\ArpaAppointmentDataIssueCorrectionService($this->pdo))->applyApprovedAmendmentInTransaction((string)$workflow['id'],self::NATIONAL_APPROVER),'internal application helper cannot run outside the final approval transaction');

        $this->useContext(self::ACTOR);
        $promotion=$service->createDraft(self::PROMOTION_CORRECTION,$this->proposal('RESOLVE_CANONICAL_ASSIGNMENT',[],'Replace the historical-exception decision'),self::ACTOR);
        $parallel=$service->createDraft(self::PARALLEL_CORRECTION,$this->proposal('MARK_HISTORICAL_ONLY',[],'Conflicting parallel proposal'),self::ACTOR);
        $promotion=$this->advanceToNationalVerified($service,$promotion);
        $this->useContext(self::NATIONAL_APPROVER);
        $promotion=$service->review((string)$promotion['id'],'APPROVE',6,self::NATIONAL_APPROVER);
        $this->same('NATIONAL_APPROVED',$promotion['workflow_status'],'KEEP decision may be superseded by an approved authoritative decision');
        $this->same(0,(int)$this->row("SELECT legacy_history_only FROM arpa_division_appointment WHERE id='30000000-0000-4000-8000-000000000013'")['legacy_history_only'],'approved canonical-resolution amendment makes the existing imported appointment authoritative');
        $this->same(1,$this->count('SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE supersedes_correction_id=?',[self::PROMOTION_CORRECTION]),'promotion appends one explicit successor decision');
        $this->same(0,$this->count("SELECT COUNT(*) FROM arpa_division_appointment_request WHERE id='40000000-0000-4000-8000-000000000013' AND deleted_at IS NOT NULL"),'amendment promotion does not delete its source request');
        $this->useContext(self::ACTOR);
        $this->throwsDomainMessage(fn()=>$service->submit((string)$parallel['id'],0,self::ACTOR),'changed after this proposal was drafted','a competing amendment against another decision for the same appointment becomes stale after the first applies');
        $promotionDecision=(string)$promotion['applied_correction_id'];
        $this->useContext(self::ACTOR);
        $reconsidered=$service->createDraft($promotionDecision,$this->proposal('KEEP_AS_HISTORICAL_EXCEPTION',[],'Reconsider the replacement decision'),self::ACTOR);
        $reconsidered=$this->advanceToNationalVerified($service,$reconsidered);
        $this->useContext(self::NATIONAL_APPROVER);
        $reconsidered=$service->review((string)$reconsidered['id'],'APPROVE',6,self::NATIONAL_APPROVER);
        $this->same($promotionDecision,$this->row('SELECT supersedes_correction_id FROM arpa_appointment_data_correction WHERE id=?',[$reconsidered['applied_correction_id']])['supersedes_correction_id'],'a subsequent amendment links to the immediately preceding replacement decision');
        $this->same(1,(int)$this->row("SELECT legacy_history_only FROM arpa_division_appointment WHERE id='30000000-0000-4000-8000-000000000013'")['legacy_history_only'],'approved KEEP replacement returns the imported appointment to historical-only state');
        $this->same(1,$this->count('SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE id=?',[self::PROMOTION_CORRECTION]),'the original KEEP decision remains traceable after a two-step supersession chain');
        $effectiveAssessment=(new \App\Services\ArpaAppointmentDataIssueCorrectionService($this->pdo))->validateCanonicalPromotion('30000000-0000-4000-8000-000000000013');
        $this->same('CONFIRMED_HISTORICAL',$effectiveAssessment['blocker_code'],'canonical promotion reads only the final effective KEEP decision in the supersession chain');

        $this->useContext(self::ACTOR);$returned=$service->submit((string)$returned['id'],0,self::ACTOR);
        $this->useContextRole(self::ASC_VERIFIER,'ASC_SUBJECT_OFFICER');$returned=$service->review((string)$returned['id'],'VERIFY',1,self::ASC_VERIFIER);
        $this->useContext(self::ASC_APPROVER);
        $this->throwsDomain(fn()=>$service->review((string)$returned['id'],'RETURN_FOR_CORRECTION',2,self::ASC_APPROVER),'return requires a reason');
        $returned=$service->review((string)$returned['id'],'RETURN_FOR_CORRECTION',2,self::ASC_APPROVER,'Correct the supporting evidence.');
        $this->same('RETURNED',$returned['workflow_status'],'authorized reviewer returns the proposal for correction');
        $this->useContext(self::ACTOR);
        $returned=$service->updateDraft((string)$returned['id'],$this->proposal('SET_EFFECTIVE_TO',['effective_to'=>'2025-12-31','end_reason_id'=>null],'Updated after return'),3,self::ACTOR);
        $returned=$service->submit((string)$returned['id'],4,self::ACTOR);
        $this->same('SUBMITTED',$returned['workflow_status'],'RETURNED amendment resubmits from the start');
        $this->same(2,$this->count("SELECT COUNT(*) FROM arpa_appointment_correction_amendment_event WHERE amendment_request_id=? AND action='SUBMIT'",[$returned['id']]),'resubmission preserves both submission events');
        $this->same(1,$this->count("SELECT COUNT(*) FROM arpa_appointment_correction_amendment_event WHERE amendment_request_id=? AND action='VERIFY'",[$returned['id']]),'prior verification remains history but no approval is carried forward');

        $this->useContext(self::ACTOR);$rejected=$service->submit((string)$rejected['id'],0,self::ACTOR);
        $this->useContextRole(self::ASC_VERIFIER,'ASC_SUBJECT_OFFICER');$rejected=$service->review((string)$rejected['id'],'VERIFY',1,self::ASC_VERIFIER);
        $this->useContext(self::ASC_APPROVER);
        $this->throwsDomain(fn()=>$service->review((string)$rejected['id'],'REJECT',2,self::ASC_APPROVER),'rejection requires a reason');
        $rejected=$service->review((string)$rejected['id'],'REJECT',2,self::ASC_APPROVER,'Evidence is not valid.');
        $this->same('REJECTED',$rejected['workflow_status'],'rejection is terminal and distinct from RETURNED');
        $this->same(self::ASC_APPROVER,$rejected['finalized_by'],'terminal rejection records the finalizer');
        $this->useContext(self::ACTOR);
        $this->throwsDomain(fn()=>$service->updateDraft((string)$rejected['id'],$proposal,3,self::ACTOR),'rejected proposal cannot be edited');
        $this->throwsDomain(fn()=>$service->withdraw((string)$rejected['id'],3,self::ACTOR),'rejected proposal cannot be withdrawn');

        $this->pdo->exec("UPDATE arpa_division_appointment SET effective_from='2025-01-03' WHERE id='30000000-0000-4000-8000-000000000011'");
        $staleBefore=$this->row('SELECT workflow_status,version FROM arpa_appointment_correction_amendment_request WHERE id=?',[$stale['id']]);
        $staleEvents=$this->count('SELECT COUNT(*) FROM arpa_appointment_correction_amendment_event WHERE amendment_request_id=?',[$stale['id']]);
        $this->useContext(self::ACTOR);
        $this->throwsDomainMessage(fn()=>$service->submit((string)$stale['id'],0,self::ACTOR),'changed after this proposal was drafted','changed source data blocks submission');
        $this->same($staleBefore,$this->row('SELECT workflow_status,version FROM arpa_appointment_correction_amendment_request WHERE id=?',[$stale['id']]),'stale baseline rejection preserves request state');
        $this->same($staleEvents,$this->count('SELECT COUNT(*) FROM arpa_appointment_correction_amendment_event WHERE amendment_request_id=?',[$stale['id']]),'stale baseline rejection writes no event');
        $this->pdo->exec("UPDATE arpa_division_appointment SET effective_from='2025-01-01' WHERE id='30000000-0000-4000-8000-000000000011'");

        $this->pdo->exec("CREATE TRIGGER fail_arpa_amendment_transition_event BEFORE INSERT ON arpa_appointment_correction_amendment_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced transition event failure'");
        $this->throwsThrowable(fn()=>$service->submit((string)$eventFailure['id'],0,self::ACTOR),'event failure aborts workflow transition');
        $this->pdo->exec('DROP TRIGGER fail_arpa_amendment_transition_event');
        $this->same('DRAFT',$this->row('SELECT workflow_status FROM arpa_appointment_correction_amendment_request WHERE id=?',[$eventFailure['id']])['workflow_status'],'event failure rolls back request update');

        $this->pdo->exec("UPDATE arpa_division_appointment SET legacy_history_only=0 WHERE id='30000000-0000-4000-8000-000000000006';
            UPDATE arpa_division_appointment_request SET legacy_history_only=0 WHERE id='40000000-0000-4000-8000-000000000006'");
        $this->useContext(self::ACTOR);
        $atomic=$service->createDraft(self::ATOMIC_CORRECTION,$this->proposal('MARK_HISTORICAL_ONLY',[],'Atomic final approval'),self::ACTOR);
        $atomic=$this->advanceToNationalVerified($service,$atomic);
        $this->pdo->exec("CREATE TRIGGER fail_replacement_correction BEFORE INSERT ON arpa_appointment_data_correction FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced replacement correction failure'");
        $this->useContext(self::NATIONAL_APPROVER);
        $this->throwsThrowable(fn()=>$service->review((string)$atomic['id'],'APPROVE',6,self::NATIONAL_APPROVER),'failure after canonical mutation rolls back final approval transaction');
        $this->pdo->exec('DROP TRIGGER fail_replacement_correction');
        $this->same(0,(int)$this->row("SELECT legacy_history_only FROM arpa_division_appointment WHERE id='30000000-0000-4000-8000-000000000006'")['legacy_history_only'],'failed replacement ledger insert rolls back canonical mutation');
        $this->same('NATIONAL_VERIFIED',$this->row('SELECT workflow_status FROM arpa_appointment_correction_amendment_request WHERE id=?',[$atomic['id']])['workflow_status'],'failed final application preserves pre-approval status');
        $this->same(0,$this->count('SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE amendment_request_id=?',[$atomic['id']]),'failed final application writes no replacement correction');
        $this->same(0,$this->count("SELECT COUNT(*) FROM arpa_appointment_correction_amendment_event WHERE amendment_request_id=? AND action='APPROVE' AND stage='NATIONAL'",[$atomic['id']]),'failed final application writes no final event');

        $this->same(3,$this->count('SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE amendment_request_id IS NOT NULL'),'only the three successful final approvals create replacement corrections');
    }

    private function exerciseConsistencyCheckpoint():void
    {
        $service=new ArpaAppointmentCorrectionAmendmentService($this->pdo);
        $this->useContext(self::ACTOR);
        $this->throwsDomain(
            fn()=>$service->createDraft(self::CROSS_ASC_CORRECTION,$this->proposal('SELECT_CURRENT_RECORD',['appointment_id'=>'30000000-0000-4000-8000-000000000018']),self::ACTOR),
            'a multi-record proposal cannot include a related appointment from another ASC'
        );
        $this->pdo->exec("UPDATE arpa_division_appointment SET legacy_history_only=0,appointment_type='PERMANENT',arpa_division_location_id='16000000-0000-4000-8000-000000000002',arpa_name_snapshot='Division Two' WHERE id IN('30000000-0000-4000-8000-000000000014','30000000-0000-4000-8000-000000000015'); UPDATE arpa_division_appointment_request SET legacy_history_only=0,appointment_type='PERMANENT',arpa_division_location_id='16000000-0000-4000-8000-000000000002' WHERE id IN('40000000-0000-4000-8000-000000000014','40000000-0000-4000-8000-000000000015')");

        $multi=$service->createDraft(
            self::MULTI_CORRECTION,
            $this->proposal('SELECT_CURRENT_RECORD',['appointment_id'=>'30000000-0000-4000-8000-000000000014'],'Select the reviewed current record'),
            self::ACTOR
        );
        $this->same(
            ['30000000-0000-4000-8000-000000000014','30000000-0000-4000-8000-000000000015'],
            $multi['baseline']['related_write_set']['appointment_ids'],
            'multi-record proposal freezes the exact affected appointment IDs'
        );
        $this->same(2,count($multi['baseline']['related_write_set']['appointments']),'baseline captures every related appointment');
        $this->same(2,count($multi['baseline']['related_write_set']['requests']),'baseline captures every related request');
        $issueKey='DIVISION_MULTIPLE_OPEN:16000000-0000-4000-8000-000000000002';
        $issueSource=\App\Services\ArpaAppointmentReadService::issueSource();
        $this->same(1,$this->count("SELECT COUNT(*) FROM {$issueSource} q WHERE q.row_key=?",[$issueKey]),'shared issue source detects the pre-approval multi-record conflict');
        $multi=$service->submit((string)$multi['id'],0,self::ACTOR);
        $this->useContextRole(self::ASC_VERIFIER,'ASC_SUBJECT_OFFICER');
        $reviewRead=$service->reviewerGet((string)$multi['id'],self::ASC_VERIFIER);
        $this->same(2,count($reviewRead['baseline']['related_write_set']['appointments']),'authorized reviewer read exposes the complete original multi-record change set');
        $this->same(['appointment_id'=>'30000000-0000-4000-8000-000000000014'],$reviewRead['proposed'],'authorized reviewer read exposes the structured proposed selection');
        $multi=$service->review((string)$multi['id'],'VERIFY',1,self::ASC_VERIFIER);
        $this->useContext(self::ASC_APPROVER);$multi=$service->review((string)$multi['id'],'APPROVE',2,self::ASC_APPROVER);
        $this->useContext(self::DISTRICT_VERIFIER);$multi=$service->review((string)$multi['id'],'VERIFY',3,self::DISTRICT_VERIFIER);
        $this->useContext(self::DISTRICT_APPROVER);$multi=$service->review((string)$multi['id'],'APPROVE',4,self::DISTRICT_APPROVER);
        $this->useContextRole(self::NATIONAL_VERIFIER,'NATIONAL_SUBJECT_OFFICER');$multi=$service->review((string)$multi['id'],'VERIFY',5,self::NATIONAL_VERIFIER);
        $this->useContext(self::NATIONAL_APPROVER);
        $multi=$service->review((string)$multi['id'],'APPROVE',6,self::NATIONAL_APPROVER);
        $this->same(0,(int)$this->row("SELECT legacy_history_only FROM arpa_division_appointment WHERE id='30000000-0000-4000-8000-000000000014'")['legacy_history_only'],'selected record remains current');
        $this->same(1,(int)$this->row("SELECT legacy_history_only FROM arpa_division_appointment WHERE id='30000000-0000-4000-8000-000000000015'")['legacy_history_only'],'only the reviewed related record is moved to history');
        $this->same(1,(int)$this->row("SELECT legacy_history_only FROM arpa_division_appointment_request WHERE id='40000000-0000-4000-8000-000000000015'")['legacy_history_only'],'related request receives the approved matching history flag');
        $multiReplacement=$this->row('SELECT before_json,after_json FROM arpa_appointment_data_correction WHERE amendment_request_id=?',[$multi['id']]);
        $this->same(2,count(json_decode((string)$multiReplacement['before_json'],true,512,JSON_THROW_ON_ERROR)),'replacement audit captures before-state for every write-set appointment');
        $this->same(2,count(json_decode((string)$multiReplacement['after_json'],true,512,JSON_THROW_ON_ERROR)),'replacement audit captures after-state for every write-set appointment');
        $this->same(0,$this->count("SELECT COUNT(*) FROM arpa_division_appointment_request WHERE id IN('40000000-0000-4000-8000-000000000014','40000000-0000-4000-8000-000000000015') AND deleted_at IS NOT NULL"),'multi-record correction does not delete or supersede normal requests implicitly');
        $correctionHistory=(new \App\Services\ArpaAppointmentDataIssueCorrectionService($this->pdo))->correctionsForAppointment('30000000-0000-4000-8000-000000000014');
        $this->same(true,in_array(self::MULTI_CORRECTION,array_column($correctionHistory,'id'),true),'original correction remains visible in append-only profile correction history');
        $this->same(true,in_array((string)$multi['applied_correction_id'],array_column($correctionHistory,'id'),true),'approved replacement is appended beside its original decision');
        $effectiveAfterApproval=\App\Services\ArpaAppointmentCorrectionDecision::effectivePredicate($this->pdo,'decision');
        $this->same($this->interfaceFeatureEnabled,\App\Services\ArpaAppointmentCorrectionAmendmentFeature::enabled(),$this->interfaceFeatureEnabled?'isolated interface child enables the feature explicitly':'feature remains OFF while completed lineage is consumed');
        $this->same(0,$this->count("SELECT COUNT(*) FROM arpa_appointment_data_correction decision WHERE decision.id=? AND {$effectiveAfterApproval}",[self::MULTI_CORRECTION]),'successfully applied replacement retires only its explicit predecessor even with the feature OFF');
        $this->same(1,$this->count("SELECT COUNT(*) FROM arpa_appointment_data_correction decision WHERE decision.id=? AND {$effectiveAfterApproval}",[self::KEEP_CORRECTION]),'an unrelated correction decision remains effective');
        $this->same(0,$this->count("SELECT COUNT(*) FROM {$issueSource} q WHERE q.row_key=?",[$issueKey]),'shared issue source no longer reports the corrected conflict');
        $issueMap=\App\Services\ArpaAppointmentReadService::divisionIssueMapSource();
        $this->same(0,$this->count("SELECT COUNT(*) FROM {$issueMap} mapped WHERE mapped.division_id='16000000-0000-4000-8000-000000000002' AND mapped.row_key=?",[$issueKey]),'division issue-map count agrees for the corrected conflict');
        $continuityIssues=(new \App\Services\ArpaDivisionContinuityService($this->pdo))->unresolvedDataIssues('16000000-0000-4000-8000-000000000002');
        $this->same(0,count(array_filter($continuityIssues,static fn(array $issue):bool=>(string)$issue['row_key']===$issueKey)),'continuity no longer reports the corrected conflict');
        $this->useContext(self::VIEWER);
        $definition=\App\Core\DataTableRegistry::definition('arpa-appointment-issues');
        [$dataTableFilter]=$definition['filters']['category']['build']('CURRENT_ACTION_REQUIRED');
        $dataTableTotal=$this->count("SELECT COUNT(*) FROM {$issueSource} q WHERE {$dataTableFilter}");
        $dataTableFiltered=$this->count("SELECT COUNT(*) FROM {$issueSource} q WHERE {$dataTableFilter} AND q.officer_name LIKE ?",['%Officer%']);
        $this->same($dataTableTotal,$dataTableFiltered,'Data Issue records and filtered-count predicates agree after the approved replacement');
        $this->useContext(self::ACTOR);
        $presentation=\App\Services\ArpaAppointmentDisplayPresentation::decorate(['legacy_history_only'=>1,'legacy_exception'=>1,'closure_id'=>null,'effective_to'=>null,'record_origin'=>'LEGACY_IMPORT','effective_from'=>'2025-01-01','legacy_exception_codes_json'=>'["OFFICER_MULTIPLE_ACTING"]']);
        $this->same('Historical Exception',$presentation['display_status'],'existing profile classification remains unchanged for an open imported historical exception');
        $this->same(['Multiple Acting appointments'],$presentation['exception_labels'],'existing profile exception presentation remains human-readable');

        $this->useContext(self::ACTOR);
        $stale=$service->createDraft(self::RELATED_STALE_CORRECTION,$this->proposal('SELECT_CURRENT_RECORD',['appointment_id'=>'30000000-0000-4000-8000-000000000016']),self::ACTOR);
        $this->pdo->exec("UPDATE arpa_division_appointment SET effective_from='2025-01-02' WHERE id='30000000-0000-4000-8000-000000000017'");
        $this->throwsDomainMessage(fn()=>$service->submit((string)$stale['id'],0,self::ACTOR),'changed after this proposal was drafted','a changed related appointment makes the complete proposal stale');
        $this->pdo->exec("UPDATE arpa_division_appointment SET effective_from='2025-01-01' WHERE id='30000000-0000-4000-8000-000000000017'; UPDATE arpa_division_appointment_request SET workflow_status='NATIONAL_VERIFIED' WHERE id='40000000-0000-4000-8000-000000000017'");
        $this->throwsDomainMessage(fn()=>$service->submit((string)$stale['id'],0,self::ACTOR),'changed after this proposal was drafted','a changed related request makes the complete proposal stale');
        $this->pdo->exec("UPDATE arpa_division_appointment_request SET workflow_status='DISTRICT_APPROVED' WHERE id='40000000-0000-4000-8000-000000000017'; UPDATE arpa_division_appointment_closure SET effective_to='2025-07-01' WHERE appointment_id='30000000-0000-4000-8000-000000000017'");
        $this->throwsDomainMessage(fn()=>$service->submit((string)$stale['id'],0,self::ACTOR),'changed after this proposal was drafted','a changed related closure makes the complete proposal stale');
        $this->pdo->exec("UPDATE arpa_division_appointment_closure SET effective_to='2025-06-30' WHERE appointment_id='30000000-0000-4000-8000-000000000017'; UPDATE arpa_appointment_data_correction SET related_appointment_ids_json=JSON_ARRAY('30000000-0000-4000-8000-000000000016','30000000-0000-4000-8000-000000000017','30000000-0000-4000-8000-000000000018') WHERE id='".self::RELATED_STALE_CORRECTION."'");
        $this->throwsDomainMessage(fn()=>$service->submit((string)$stale['id'],0,self::ACTOR),'changed after this proposal was drafted','a newly discovered related record is not silently added to the approved write set');

        $this->useContext(self::ACTOR);
        $this->pdo->exec("UPDATE arpa_division_appointment SET legacy_history_only=0 WHERE id='30000000-0000-4000-8000-000000000020'; UPDATE arpa_division_appointment_request SET legacy_history_only=0 WHERE id='40000000-0000-4000-8000-000000000020'");
        $eventFailure=$service->createDraft(self::FINAL_EVENT_CORRECTION,$this->proposal('MARK_HISTORICAL_ONLY',[],'Final event rollback check'),self::ACTOR);
        $eventFailure=$this->advanceToNationalVerified($service,$eventFailure);
        $this->useContextRole(self::NATIONAL_VERIFIER,'NATIONAL_ADMIN');
        $this->throwsDomain(fn()=>$service->review((string)$eventFailure['id'],'APPROVE',6,self::NATIONAL_VERIFIER),'National verifier cannot bypass maker-checker by switching to an approval role');
        $this->pdo->exec("CREATE TRIGGER fail_final_approval_event BEFORE INSERT ON arpa_appointment_correction_amendment_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced final event failure'");
        $this->useContext(self::NATIONAL_APPROVER);
        $this->throwsThrowable(fn()=>$service->review((string)$eventFailure['id'],'APPROVE',6,self::NATIONAL_APPROVER),'failure inserting the final event rolls back every earlier final-approval write');
        $this->pdo->exec('DROP TRIGGER fail_final_approval_event');
        $this->same(0,(int)$this->row("SELECT legacy_history_only FROM arpa_division_appointment WHERE id='30000000-0000-4000-8000-000000000020'")['legacy_history_only'],'final event failure rolls back the appointment mutation');
        $this->same('NATIONAL_VERIFIED',$this->row('SELECT workflow_status FROM arpa_appointment_correction_amendment_request WHERE id=?',[$eventFailure['id']])['workflow_status'],'final event failure rolls back terminal amendment state');
        $this->same(0,$this->count('SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE amendment_request_id=?',[$eventFailure['id']]),'final event failure rolls back the replacement correction');

        $this->pdo->exec("INSERT INTO arpa_appointment_data_correction(
            id,issue_row_key,issue_type,officer_id,appointment_id,request_id,related_appointment_ids_json,asc_location_id,
            corrected_by,corrected_at,correction_action,resolution_status,correction_reason,remarks,evidence_reference,
            before_json,after_json,source,record_origin,legacy_source_references_json,supersedes_correction_id,amendment_request_id
        ) SELECT '50000000-0000-4000-8000-000000000099',issue_row_key,issue_type,officer_id,appointment_id,request_id,
            related_appointment_ids_json,asc_location_id,corrected_by,NOW(),correction_action,resolution_status,'Unapplied fixture successor',
            remarks,evidence_reference,before_json,after_json,source,record_origin,legacy_source_references_json,id,'".$stale['id']."'
          FROM arpa_appointment_data_correction WHERE id='".self::RELATED_STALE_CORRECTION."'");
        $effective=\App\Services\ArpaAppointmentCorrectionDecision::effectivePredicate($this->pdo,'source_decision');
        foreach(['DRAFT','REJECTED','WITHDRAWN'] as $status){
            $this->pdo->prepare('UPDATE arpa_appointment_correction_amendment_request SET workflow_status=? WHERE id=?')->execute([$status,$stale['id']]);
            $this->same(1,$this->count("SELECT COUNT(*) FROM arpa_appointment_data_correction source_decision WHERE source_decision.id='".self::RELATED_STALE_CORRECTION."' AND {$effective}"),"{$status} amendment successor does not retire its source decision");
        }
        $this->useContext(self::VIEWER);
        $definition=\App\Core\DataTableRegistry::definition('arpa-appointment-issues');
        [$currentFilter]=$definition['filters']['category']['build']('CURRENT_ACTION_REQUIRED');
        $this->same(true,str_contains($currentFilter,'successor_amendment.workflow_status=\'NATIONAL_APPROVED\''),'Data Issue DataTable reviewed filter uses the shared effective-decision predicate');
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function advanceToNationalVerified(ArpaAppointmentCorrectionAmendmentService $service,array $request):array
    {
        $this->useContext(self::ACTOR);$request=$service->submit((string)$request['id'],0,self::ACTOR);
        $this->useContextRole(self::ASC_VERIFIER,'ASC_SUBJECT_OFFICER');$request=$service->review((string)$request['id'],'VERIFY',1,self::ASC_VERIFIER);
        $this->useContext(self::ASC_APPROVER);$request=$service->review((string)$request['id'],'APPROVE',2,self::ASC_APPROVER);
        $this->useContext(self::DISTRICT_VERIFIER);$request=$service->review((string)$request['id'],'VERIFY',3,self::DISTRICT_VERIFIER);
        $this->useContext(self::DISTRICT_APPROVER);$request=$service->review((string)$request['id'],'APPROVE',4,self::DISTRICT_APPROVER);
        $this->useContextRole(self::NATIONAL_VERIFIER,'NATIONAL_SUBJECT_OFFICER');return $service->review((string)$request['id'],'VERIFY',5,self::NATIONAL_VERIFIER);
    }

    /** @return array<string,mixed> */
    private function proposal(string $action,array $values,string $reason='Correct reconciled appointment data',?string $remarks='Draft only',?string $evidence='Source ledger'):array
    {
        return ['proposed_action'=>$action,'proposed_json'=>$values,'correction_reason'=>$reason,'remarks'=>$remarks,'evidence_reference'=>$evidence];
    }

    private function staticNonOperationalGuard():void
    {
        $source=(string)file_get_contents(BASE_PATH.'/app/Services/ArpaAppointmentCorrectionAmendmentService.php');
        foreach(['->correct(','correctCanonicalEndDate(','promoteHistoricalAppointment(','resolveHistoricalRequest(','system_notification','arpa_appointment_workflow_action'] as $forbidden){
            $this->same(false,str_contains($source,$forbidden),'draft service does not invoke/write '.$forbidden);
        }
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function operationalState():array
    {
        $tables=['arpa_appointment_data_correction','arpa_division_appointment','arpa_division_appointment_closure','arpa_division_appointment_request'];
        $state=[];
        foreach($tables as $table)$state[$table]=$this->pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll();
        return $state;
    }

    private function useContext(string $userId):void
    {
        $_SESSION=['user_id'=>$userId,'authenticated_at'=>time(),'last_activity_at'=>time()];
        Auth::forgetRequestCache();
        $context=(new UserContextService($this->pdo))->availableContexts($userId)[0]??null;
        if(!$context)throw new RuntimeException('Fixture context is unavailable for '.$userId);
        (new UserContextService($this->pdo))->select($userId,(string)$context['role_assignment_id'],(string)$context['scope_assignment_id']);
        Auth::forgetRequestCache();
    }

    private function useContextRole(string $userId,string $roleCode):void
    {
        $_SESSION=['user_id'=>$userId,'authenticated_at'=>time(),'last_activity_at'=>time()];
        Auth::forgetRequestCache();
        $context=null;
        foreach((new UserContextService($this->pdo))->availableContexts($userId) as $candidate){
            if((string)$candidate['role_code']===$roleCode){$context=$candidate;break;}
        }
        if(!$context)throw new RuntimeException("Fixture context {$roleCode} is unavailable for {$userId}");
        (new UserContextService($this->pdo))->select($userId,(string)$context['role_assignment_id'],(string)$context['scope_assignment_id']);
        Auth::forgetRequestCache();
    }

    private function installIsolatedDatabase():void
    {
        $this->databaseProperty=new ReflectionProperty(Database::class,'pdo');
        $this->databaseProperty->setAccessible(true);
        $this->previousDatabase=$this->databaseProperty->getValue();
        $this->databaseProperty->setValue(null,$this->pdo);
        Auth::forgetRequestCache();
    }

    private function createFixtureSchema():void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE location_type(id CHAR(36) PRIMARY KEY,system_key VARCHAR(50) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE location(id CHAR(36) PRIMARY KEY,location_type_id CHAR(36) NOT NULL,dad_number VARCHAR(50),name_en VARCHAR(255),FOREIGN KEY(location_type_id) REFERENCES location_type(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE location_relationship(id CHAR(36) PRIMARY KEY,parent_location_id CHAR(36) NOT NULL,child_location_id CHAR(36) NOT NULL,relationship_type VARCHAR(80) NOT NULL,active TINYINT NOT NULL DEFAULT 1,approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',effective_from DATE NOT NULL,effective_to DATE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE system_user(id CHAR(36) PRIMARY KEY,username VARCHAR(100) NOT NULL,display_name VARCHAR(150) NULL,enabled TINYINT NOT NULL DEFAULT 1,account_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE application_role(id CHAR(36) PRIMARY KEY,role_code VARCHAR(80) NOT NULL,role_name VARCHAR(150) NOT NULL,role_level VARCHAR(30) NOT NULL,active TINYINT NOT NULL DEFAULT 1,approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE application_permission(id CHAR(36) PRIMARY KEY,permission_key VARCHAR(150) NOT NULL,active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE application_role_permission(role_id CHAR(36) NOT NULL,permission_id CHAR(36) NOT NULL,PRIMARY KEY(role_id,permission_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE user_account_role(id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,role_id CHAR(36) NOT NULL,active TINYINT NOT NULL DEFAULT 1,approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',effective_from DATE NOT NULL,effective_to DATE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE user_account_scope(id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,role_assignment_id CHAR(36) NOT NULL,scope_type VARCHAR(30) NOT NULL,scope_mode VARCHAR(30) NOT NULL,location_id CHAR(36) NOT NULL,active TINYINT NOT NULL DEFAULT 1,approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',effective_from DATE NOT NULL,effective_to DATE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 CREATE TABLE officer(id CHAR(36) PRIMARY KEY,dad_number VARCHAR(50) NULL,name_with_initials VARCHAR(255) NULL,nic VARCHAR(20) NULL,arpa_service_permanency VARCHAR(60) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE arpa_appointment_end_reason(id CHAR(36) PRIMARY KEY,system_key VARCHAR(80) NOT NULL,name_en VARCHAR(150) NOT NULL,active TINYINT NOT NULL DEFAULT 1,display_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE arpa_division_appointment_request(
 id CHAR(36) PRIMARY KEY,record_origin VARCHAR(40) NOT NULL,request_type VARCHAR(30) NOT NULL,officer_id CHAR(36) NOT NULL,appointment_type VARCHAR(50) NULL,
 source_appointment_id CHAR(36) NULL,asc_location_id CHAR(36) NOT NULL,arpa_division_location_id CHAR(36) NOT NULL,requested_effective_from DATE NOT NULL,
 requested_effective_to DATE NULL,end_reason_id CHAR(36) NULL,workflow_status VARCHAR(50) NOT NULL,legacy_history_only TINYINT NOT NULL DEFAULT 0,
 legacy_exception TINYINT NOT NULL DEFAULT 0,legacy_exception_codes_json JSON NULL,origin_metadata_json JSON NULL,version BIGINT NOT NULL DEFAULT 0,
 deleted_at DATETIME NULL,deleted_by CHAR(36) NULL,delete_reason VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE arpa_division_appointment(
 id CHAR(36) PRIMARY KEY,record_origin VARCHAR(40) NOT NULL,request_id CHAR(36) NOT NULL,officer_id CHAR(36) NOT NULL,appointment_type VARCHAR(50) NOT NULL,
 service_permanency_snapshot VARCHAR(60) NULL,service_permanency_source VARCHAR(80) NULL,asc_location_id CHAR(36) NOT NULL,arpa_division_location_id CHAR(36) NOT NULL,
 effective_from DATE NOT NULL,legacy_history_only TINYINT NOT NULL DEFAULT 0,legacy_exception TINYINT NOT NULL DEFAULT 0,
 legacy_exception_codes_json JSON NULL,hierarchy_snapshot_json JSON NULL,origin_metadata_json JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE arpa_division_appointment_closure(
 id CHAR(36) PRIMARY KEY,record_origin VARCHAR(40) NOT NULL,appointment_id CHAR(36) NOT NULL,request_id CHAR(36) NULL,effective_to DATE NOT NULL,
 end_reason_id CHAR(36) NULL,legacy_reason_id VARCHAR(100) NULL,legacy_reason_text VARCHAR(500) NULL,closure_kind VARCHAR(60) NULL,
 closure_source VARCHAR(80) NULL,data_correction_id CHAR(36) NULL,remarks TEXT NULL,context_snapshot_json JSON NOT NULL,
 approved_by CHAR(36) NULL,approved_at DATETIME NULL,approval_timestamp_provenance VARCHAR(80) NOT NULL,origin_metadata_json JSON NULL,
 UNIQUE KEY uq_fixture_closure(appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE arpa_appointment_data_correction(
 id CHAR(36) PRIMARY KEY,issue_row_key VARCHAR(255) NOT NULL,issue_type VARCHAR(100) NOT NULL,officer_id CHAR(36) NOT NULL,appointment_id CHAR(36) NULL,
 request_id CHAR(36) NULL,related_appointment_ids_json JSON NULL,asc_location_id CHAR(36) NOT NULL,corrected_by CHAR(36) NOT NULL,corrected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 correction_action VARCHAR(80) NOT NULL,resolution_status VARCHAR(80) NOT NULL,correction_reason VARCHAR(500) NOT NULL,remarks TEXT NULL,evidence_reference VARCHAR(500) NULL,
 before_json JSON NOT NULL,after_json JSON NOT NULL,source VARCHAR(80) NOT NULL DEFAULT 'APPOINTMENT_DATA_ISSUE',record_origin VARCHAR(40) NOT NULL,
 legacy_source_references_json JSON NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE office_type(id CHAR(36) PRIMARY KEY,system_key VARCHAR(50) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE office(id CHAR(36) PRIMARY KEY,office_type_id CHAR(36) NOT NULL,linked_location_id CHAR(36) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE officer_office_assignment(id CHAR(36) PRIMARY KEY,officer_id CHAR(36) NOT NULL,office_id CHAR(36) NOT NULL,active TINYINT NOT NULL,approval_status VARCHAR(30) NOT NULL,effective_from DATE NOT NULL,effective_to DATE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE arpa_subject_assignment(id CHAR(36) PRIMARY KEY,record_origin VARCHAR(40) NOT NULL,officer_id CHAR(36) NOT NULL,asc_location_id CHAR(36) NOT NULL,asc_name_snapshot VARCHAR(255),subject_name_snapshot VARCHAR(255),subject_kind_snapshot VARCHAR(80),effective_from DATE NOT NULL,legacy_exception TINYINT NOT NULL DEFAULT 0,officer_exclusive_snapshot TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE arpa_subject_assignment_closure(id CHAR(36) PRIMARY KEY,assignment_id CHAR(36) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE legacy_arpa_appointment_preview(reconciled_business_key VARCHAR(100) PRIMARY KEY,officer_id CHAR(36),asc_location_id CHAR(36),arpa_location_id CHAR(36),appointment_type VARCHAR(50),subject_kind VARCHAR(80),effective_from DATE,effective_to DATE,blocker_types_json JSON,active TINYINT NOT NULL DEFAULT 1,assignment_category VARCHAR(50)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE legacy_arpa_appointment_migration_issue(id BIGINT AUTO_INCREMENT PRIMARY KEY,reconciled_business_key VARCHAR(100),issue_type VARCHAR(80)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE legacy_arpa_appointment_business_record(id CHAR(36) PRIMARY KEY,reconciled_business_key VARCHAR(100)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE legacy_arpa_reconciliation_item(id CHAR(36) PRIMARY KEY,reconciled_business_key VARCHAR(100),officer_id CHAR(36),appointment_type VARCHAR(50),subject_kind VARCHAR(80),effective_from DATE,effective_to DATE,active TINYINT,diagnostic_blocker TINYINT,item_type VARCHAR(50),candidate_arpa_id CHAR(36)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE legacy_arpa_appointment_resolution(id CHAR(36) PRIMARY KEY,reconciliation_item_id CHAR(36),selected_target_arpa_id CHAR(36),resolution_status VARCHAR(80)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 CREATE TABLE audit_event(id BIGINT AUTO_INCREMENT PRIMARY KEY,actor_user_id CHAR(36) NULL,action_key VARCHAR(150) NOT NULL,target_type VARCHAR(100) NOT NULL,target_id CHAR(36) NULL,details_json JSON NULL,severity VARCHAR(30) NOT NULL,source_ip VARCHAR(64) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    private function insertFixtures():void
    {
        $today=date('Y-m-d');
        $this->pdo->exec("INSERT INTO location_type VALUES('11000000-0000-4000-8000-000000000001','ASC'),('11000000-0000-4000-8000-000000000002','ARPA_DIVISION'),('11000000-0000-4000-8000-000000000003','DISTRICT');");
        $this->pdo->exec("INSERT INTO location VALUES
 ('".self::ASC_ONE."','11000000-0000-4000-8000-000000000001','70004-0000001','ASC One'),
 ('".self::ASC_TWO."','11000000-0000-4000-8000-000000000001','70004-0000002','ASC Two'),
 ('19000000-0000-4000-8000-000000000001','11000000-0000-4000-8000-000000000003','70002-0000001','District One'),
 ('19000000-0000-4000-8000-000000000002','11000000-0000-4000-8000-000000000003','70002-0000002','District Two'),
 ('16000000-0000-4000-8000-000000000001','11000000-0000-4000-8000-000000000002','70007-0000001','Division One');
 INSERT INTO location VALUES('16000000-0000-4000-8000-000000000002','11000000-0000-4000-8000-000000000002','70007-0000002','Division Two');
 INSERT INTO location_relationship VALUES
 ('17000000-0000-4000-8000-000000000001','".self::ASC_ONE."','16000000-0000-4000-8000-000000000001','ASC_ARPA_DIVISION',1,'APPROVED','2025-01-01',NULL),
 ('17000000-0000-4000-8000-000000000004','".self::ASC_ONE."','16000000-0000-4000-8000-000000000002','ASC_ARPA_DIVISION',1,'APPROVED','2025-01-01',NULL),
 ('17000000-0000-4000-8000-000000000002','19000000-0000-4000-8000-000000000001','".self::ASC_ONE."','DISTRICT_ASC',1,'APPROVED','2025-01-01',NULL),
 ('17000000-0000-4000-8000-000000000003','19000000-0000-4000-8000-000000000002','".self::ASC_TWO."','DISTRICT_ASC',1,'APPROVED','2025-01-01',NULL);
 INSERT INTO arpa_appointment_end_reason VALUES('18000000-0000-4000-8000-000000000001','TRANSFER','Transfer',1,1);");
        $this->pdo->exec("INSERT INTO system_user VALUES
 ('".self::ACTOR."','actor-one','Actor One',1,'ACTIVE','APPROVED'),
 ('".self::OTHER_ASC_ACTOR."','actor-two','Actor Two',1,'ACTIVE','APPROVED'),
 ('".self::WRONG_ROLE_ACTOR."','wrong-role','Wrong Role',1,'ACTIVE','APPROVED'),
 ('".self::ASC_VERIFIER."','asc-verifier','ASC Verifier',1,'ACTIVE','APPROVED'),
 ('".self::ASC_APPROVER."','asc-approver','ASC Approver',1,'ACTIVE','APPROVED'),
 ('".self::DISTRICT_VERIFIER."','district-verifier','District Verifier',1,'ACTIVE','APPROVED'),
 ('".self::DISTRICT_APPROVER."','district-approver','District Approver',1,'ACTIVE','APPROVED'),
 ('".self::NATIONAL_VERIFIER."','national-verifier','National Verifier',1,'ACTIVE','APPROVED'),
 ('".self::NATIONAL_APPROVER."','national-approver','National Approver',1,'ACTIVE','APPROVED'),
 ('".self::OUTSIDE_DISTRICT."','outside-district','Outside District',1,'ACTIVE','APPROVED'),
 ('".self::VIEWER."','viewer','Viewer',1,'ACTIVE','APPROVED');");
        $this->pdo->exec("INSERT INTO application_role VALUES
 ('12000000-0000-4000-8000-000000000001','ASC_SUBJECT_OFFICER','ASC Subject Officer','ASC',1,'APPROVED'),
 ('12000000-0000-4000-8000-000000000002','ASC_ADMIN','ASC Administrator','ASC',1,'APPROVED'),
 ('12000000-0000-4000-8000-000000000003','DISTRICT_SUBJECT_OFFICER','District Subject Officer','DISTRICT',1,'APPROVED'),
 ('12000000-0000-4000-8000-000000000004','DISTRICT_ADMIN','District Administrator','DISTRICT',1,'APPROVED'),
 ('12000000-0000-4000-8000-000000000005','NATIONAL_SUBJECT_OFFICER','National Subject Officer','NATIONAL',1,'APPROVED'),
 ('12000000-0000-4000-8000-000000000006','NATIONAL_ADMIN','National Administrator','NATIONAL',1,'APPROVED'),
 ('12000000-0000-4000-8000-000000000007','ASC_VIEWER','ASC Viewer','ASC',1,'APPROVED');
 INSERT INTO application_permission VALUES
 ('13000000-0000-4000-8000-000000000001','arpa.appointment.data-issue.correct',1),
 ('13000000-0000-4000-8000-000000000002','arpa.appointment.submit',1),
 ('13000000-0000-4000-8000-000000000003','arpa.appointment.asc-verify',1),
 ('13000000-0000-4000-8000-000000000004','arpa.appointment.asc-approve',1),
 ('13000000-0000-4000-8000-000000000005','arpa.appointment.district-verify',1),
 ('13000000-0000-4000-8000-000000000006','arpa.appointment.district-approve',1),
 ('13000000-0000-4000-8000-000000000007','arpa.appointment.national-verify',1),
 ('13000000-0000-4000-8000-000000000008','arpa.appointment.national-approve',1),
 ('13000000-0000-4000-8000-000000000009','arpa.appointment.return',1),
 ('13000000-0000-4000-8000-000000000010','arpa.appointment.reject',1),
 ('13000000-0000-4000-8000-000000000011','arpa.appointment.view',1);
 INSERT INTO application_role_permission VALUES
 ('12000000-0000-4000-8000-000000000001','13000000-0000-4000-8000-000000000001'),
 ('12000000-0000-4000-8000-000000000001','13000000-0000-4000-8000-000000000002'),
 ('12000000-0000-4000-8000-000000000001','13000000-0000-4000-8000-000000000003'),
 ('12000000-0000-4000-8000-000000000002','13000000-0000-4000-8000-000000000004'),
 ('12000000-0000-4000-8000-000000000002','13000000-0000-4000-8000-000000000009'),
 ('12000000-0000-4000-8000-000000000002','13000000-0000-4000-8000-000000000010'),
 ('12000000-0000-4000-8000-000000000003','13000000-0000-4000-8000-000000000005'),
 ('12000000-0000-4000-8000-000000000003','13000000-0000-4000-8000-000000000009'),
 ('12000000-0000-4000-8000-000000000004','13000000-0000-4000-8000-000000000006'),
 ('12000000-0000-4000-8000-000000000004','13000000-0000-4000-8000-000000000009'),
 ('12000000-0000-4000-8000-000000000004','13000000-0000-4000-8000-000000000010'),
 ('12000000-0000-4000-8000-000000000005','13000000-0000-4000-8000-000000000007'),
 ('12000000-0000-4000-8000-000000000005','13000000-0000-4000-8000-000000000009'),
 ('12000000-0000-4000-8000-000000000006','13000000-0000-4000-8000-000000000008'),
 ('12000000-0000-4000-8000-000000000006','13000000-0000-4000-8000-000000000009'),
 ('12000000-0000-4000-8000-000000000006','13000000-0000-4000-8000-000000000010'),
 ('12000000-0000-4000-8000-000000000007','13000000-0000-4000-8000-000000000011');");
        $roleRows=[
            [self::ACTOR,'12000000-0000-4000-8000-000000000001',self::ASC_ONE,'ASC','EXACT','14000000-0000-4000-8000-000000000001','15000000-0000-4000-8000-000000000001'],
            [self::OTHER_ASC_ACTOR,'12000000-0000-4000-8000-000000000001',self::ASC_TWO,'ASC','EXACT','14000000-0000-4000-8000-000000000002','15000000-0000-4000-8000-000000000002'],
            [self::WRONG_ROLE_ACTOR,'12000000-0000-4000-8000-000000000002',self::ASC_ONE,'ASC','EXACT','14000000-0000-4000-8000-000000000003','15000000-0000-4000-8000-000000000003'],
            [self::ASC_VERIFIER,'12000000-0000-4000-8000-000000000001',self::ASC_ONE,'ASC','EXACT','14000000-0000-4000-8000-000000000004','15000000-0000-4000-8000-000000000004'],
            [self::ASC_VERIFIER,'12000000-0000-4000-8000-000000000002',self::ASC_ONE,'ASC','EXACT','14000000-0000-4000-8000-000000000005','15000000-0000-4000-8000-000000000005'],
            [self::ASC_APPROVER,'12000000-0000-4000-8000-000000000002',self::ASC_ONE,'ASC','EXACT','14000000-0000-4000-8000-000000000006','15000000-0000-4000-8000-000000000006'],
            [self::DISTRICT_VERIFIER,'12000000-0000-4000-8000-000000000003','19000000-0000-4000-8000-000000000001','DISTRICT','INCLUDE_CHILDREN','14000000-0000-4000-8000-000000000007','15000000-0000-4000-8000-000000000007'],
            [self::DISTRICT_APPROVER,'12000000-0000-4000-8000-000000000004','19000000-0000-4000-8000-000000000001','DISTRICT','INCLUDE_CHILDREN','14000000-0000-4000-8000-000000000008','15000000-0000-4000-8000-000000000008'],
            [self::NATIONAL_VERIFIER,'12000000-0000-4000-8000-000000000005','19000000-0000-4000-8000-000000000001','NATIONAL','NATIONAL','14000000-0000-4000-8000-000000000009','15000000-0000-4000-8000-000000000009'],
            [self::NATIONAL_VERIFIER,'12000000-0000-4000-8000-000000000006','19000000-0000-4000-8000-000000000001','NATIONAL','NATIONAL','14000000-0000-4000-8000-000000000013','15000000-0000-4000-8000-000000000013'],
            [self::NATIONAL_APPROVER,'12000000-0000-4000-8000-000000000006','19000000-0000-4000-8000-000000000001','NATIONAL','NATIONAL','14000000-0000-4000-8000-000000000010','15000000-0000-4000-8000-000000000010'],
            [self::OUTSIDE_DISTRICT,'12000000-0000-4000-8000-000000000003','19000000-0000-4000-8000-000000000002','DISTRICT','INCLUDE_CHILDREN','14000000-0000-4000-8000-000000000011','15000000-0000-4000-8000-000000000011'],
            [self::VIEWER,'12000000-0000-4000-8000-000000000007',self::ASC_ONE,'ASC','EXACT','14000000-0000-4000-8000-000000000012','15000000-0000-4000-8000-000000000012'],
        ];
        $role=$this->pdo->prepare("INSERT INTO user_account_role VALUES(?,?,?,1,'APPROVED',?,NULL)");
        $scope=$this->pdo->prepare("INSERT INTO user_account_scope VALUES(?,?,?,?,?, ?,1,'APPROVED',?,NULL)");
        foreach($roleRows as [$user,$roleId,$location,$scopeType,$scopeMode,$roleAssignment,$scopeAssignment]){
            $role->execute([$roleAssignment,$user,$roleId,$today]);
            $scope->execute([$scopeAssignment,$user,$roleAssignment,$scopeType,$scopeMode,$location,$today]);
        }

        $officers=['20000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000002'];
        $insertOfficer=$this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,nic,arpa_service_permanency) VALUES(?,?,?,'000000000V','PERMANENT_IN_SERVICE')");foreach($officers as $index=>$id)$insertOfficer->execute([$id,'70045-'.str_pad((string)($index+1),7,'0',STR_PAD_LEFT),'Officer '.($index+1)]);
        $this->pdo->exec("INSERT INTO office_type VALUES('1a000000-0000-4000-8000-000000000001','ASC_OFFICE');
            INSERT INTO office VALUES('1b000000-0000-4000-8000-000000000001','1a000000-0000-4000-8000-000000000001','".self::ASC_ONE."');
            INSERT INTO officer_office_assignment VALUES('1c000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001','1b000000-0000-4000-8000-000000000001',1,'APPROVED','2025-01-01',NULL);");
        for($i=1;$i<=20;$i++){
            $request=sprintf('40000000-0000-4000-8000-%012d',$i);
            $appointment=sprintf('30000000-0000-4000-8000-%012d',$i);
            $officer=$i===3?$officers[1]:$officers[0];
            $appointmentType=$i===13?'PERMANENT':'ACTING';
            $division=$i===13?'16000000-0000-4000-8000-000000000002':'16000000-0000-4000-8000-000000000001';
            $this->pdo->prepare("INSERT INTO arpa_division_appointment_request VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$request,'LEGACY_IMPORT','APPOINTMENT',$officer,$appointmentType,null,self::ASC_ONE,$division,'2025-01-01',null,null,'DISTRICT_APPROVED',1,1,'["OFFICER_MULTIPLE_ACTING"]','{"source":"fixture"}',7,null,null,null]);
            $this->pdo->prepare("INSERT INTO arpa_division_appointment VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$appointment,'LEGACY_IMPORT',$request,$officer,$appointmentType,'PERMANENT_IN_SERVICE','LEGACY_PERSONNEL',self::ASC_ONE,$division,'2025-01-01',1,1,'["OFFICER_MULTIPLE_ACTING"]','{"asc":"ASC One"}','{"migration":"fixture"}']);
        }
        $this->pdo->exec('ALTER TABLE arpa_division_appointment_request ADD COLUMN updated_by CHAR(36) NULL, ADD COLUMN updated_at DATETIME NULL');
        $this->pdo->exec("ALTER TABLE arpa_division_appointment
            ADD COLUMN asc_name_snapshot VARCHAR(255) NULL,
            ADD COLUMN arpa_name_snapshot VARCHAR(255) NULL,
            ADD COLUMN district_name_snapshot VARCHAR(255) NULL");
        $this->pdo->exec("UPDATE arpa_division_appointment SET asc_name_snapshot='ASC One',
            arpa_name_snapshot=IF(arpa_division_location_id='16000000-0000-4000-8000-000000000002','Division Two','Division One'),
            district_name_snapshot='District One'");
        $this->pdo->exec("INSERT INTO arpa_division_appointment_closure(
 id,record_origin,appointment_id,request_id,effective_to,end_reason_id,legacy_reason_id,legacy_reason_text,closure_kind,closure_source,
 data_correction_id,remarks,context_snapshot_json,approved_by,approved_at,approval_timestamp_provenance,origin_metadata_json
) VALUES('31000000-0000-4000-8000-000000000005','LEGACY_IMPORT','30000000-0000-4000-8000-000000000005',
 '40000000-0000-4000-8000-000000000005','2025-06-30',NULL,'1','Legacy reason','DIRECT','DATA_ISSUE_CORRECTION',NULL,
 'Fixture closure','{\"asc\":\"ASC One\"}',NULL,NULL,'UNAVAILABLE_FROM_LEGACY_SOURCE','{\"source\":\"fixture\"}');");

        $correction=$this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(
 id,issue_row_key,issue_type,officer_id,appointment_id,request_id,related_appointment_ids_json,asc_location_id,corrected_by,corrected_at,
 correction_action,resolution_status,correction_reason,remarks,evidence_reference,before_json,after_json,source,
 record_origin,legacy_source_references_json
) VALUES(?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?,?,?,?,?,?)");
        $base=['LEGACY_HISTORICAL_EXCEPTION','20000000-0000-4000-8000-000000000001',self::ASC_ONE,self::ACTOR,'RESOLVE_CANONICAL_ASSIGNMENT','RESOLVED_BY_CORRECTION','Fixture correction','Fixture remarks','Fixture evidence','{}','{}','APPOINTMENT_DATA_ISSUE','NATIVE','[]'];
        $this->insertCorrection($correction,self::MAIN_CORRECTION,'issue-main','30000000-0000-4000-8000-000000000001','40000000-0000-4000-8000-000000000001',$base);
        $this->insertCorrection($correction,self::REQUEST_ONLY_CORRECTION,'issue-request-only',null,'40000000-0000-4000-8000-000000000002',$base);
        $mismatch=$base;
        $this->insertCorrection($correction,self::MISMATCHED_CORRECTION,'issue-mismatch','30000000-0000-4000-8000-000000000003','40000000-0000-4000-8000-000000000003',$mismatch);
        $this->insertCorrection($correction,self::SUPERSEDED_CORRECTION,'issue-superseded','30000000-0000-4000-8000-000000000004','40000000-0000-4000-8000-000000000004',$base);
        $keep=$base;$keep[4]='KEEP_AS_HISTORICAL_EXCEPTION';
        $this->insertCorrection($correction,self::KEEP_CORRECTION,'issue-keep','30000000-0000-4000-8000-000000000005','40000000-0000-4000-8000-000000000005',$keep);
        $this->insertCorrection($correction,self::ATOMIC_CORRECTION,'issue-atomic','30000000-0000-4000-8000-000000000006','40000000-0000-4000-8000-000000000006',$base);
        $this->insertCorrection($correction,self::WORKFLOW_CORRECTION,'issue-workflow','30000000-0000-4000-8000-000000000008','40000000-0000-4000-8000-000000000008',$base);
        $this->insertCorrection($correction,self::RETURN_CORRECTION,'issue-return','30000000-0000-4000-8000-000000000009','40000000-0000-4000-8000-000000000009',$base);
        $this->insertCorrection($correction,self::REJECT_CORRECTION,'issue-reject','30000000-0000-4000-8000-000000000010','40000000-0000-4000-8000-000000000010',$base);
        $this->insertCorrection($correction,self::STALE_CORRECTION,'issue-stale','30000000-0000-4000-8000-000000000011','40000000-0000-4000-8000-000000000011',$base);
        $this->insertCorrection($correction,self::EVENT_FAILURE_CORRECTION,'issue-event-failure','30000000-0000-4000-8000-000000000012','40000000-0000-4000-8000-000000000012',$base);
        $promotion=$base;$promotion[4]='KEEP_AS_HISTORICAL_EXCEPTION';$promotion[5]='KEPT_HISTORICAL_EXCEPTION';
        $this->insertCorrection($correction,self::PROMOTION_CORRECTION,'issue-promotion','30000000-0000-4000-8000-000000000013','40000000-0000-4000-8000-000000000013',$promotion);
        $parallel=$base;$parallel[4]='CORRECT_EFFECTIVE_FROM';
        $this->insertCorrection($correction,self::PARALLEL_CORRECTION,'issue-parallel','30000000-0000-4000-8000-000000000013','40000000-0000-4000-8000-000000000013',$parallel);
        $this->insertCorrection($correction,self::MULTI_CORRECTION,'issue-multi','30000000-0000-4000-8000-000000000014','40000000-0000-4000-8000-000000000014',$base);
        $this->insertCorrection($correction,self::RELATED_STALE_CORRECTION,'issue-related-stale','30000000-0000-4000-8000-000000000016','40000000-0000-4000-8000-000000000016',$base);
        $this->insertCorrection($correction,self::CROSS_ASC_CORRECTION,'issue-cross-asc','30000000-0000-4000-8000-000000000018','40000000-0000-4000-8000-000000000018',$base);
        $this->insertCorrection($correction,self::FINAL_EVENT_CORRECTION,'issue-final-event','30000000-0000-4000-8000-000000000020','40000000-0000-4000-8000-000000000020',$base);
        $this->pdo->exec("UPDATE arpa_appointment_data_correction SET related_appointment_ids_json=JSON_ARRAY(
                '30000000-0000-4000-8000-000000000014','30000000-0000-4000-8000-000000000015') WHERE id='".self::MULTI_CORRECTION."';
            UPDATE arpa_appointment_data_correction SET related_appointment_ids_json=JSON_ARRAY(
                '30000000-0000-4000-8000-000000000016','30000000-0000-4000-8000-000000000017') WHERE id='".self::RELATED_STALE_CORRECTION."';
            UPDATE arpa_appointment_data_correction SET related_appointment_ids_json=JSON_ARRAY(
                '30000000-0000-4000-8000-000000000018','30000000-0000-4000-8000-000000000019') WHERE id='".self::CROSS_ASC_CORRECTION."';
            UPDATE arpa_division_appointment_request SET asc_location_id='".self::ASC_TWO."' WHERE id='40000000-0000-4000-8000-000000000019';
            UPDATE arpa_division_appointment SET asc_location_id='".self::ASC_TWO."' WHERE id='30000000-0000-4000-8000-000000000019';");
        $this->pdo->exec("INSERT INTO arpa_division_appointment_closure(
 id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_kind,closure_source,remarks,context_snapshot_json,approval_timestamp_provenance
) VALUES('31000000-0000-4000-8000-000000000017','LEGACY_IMPORT','30000000-0000-4000-8000-000000000017',
 '40000000-0000-4000-8000-000000000017','2025-06-30',NULL,'DIRECT','DATA_ISSUE_CORRECTION','Related fixture closure','{}','UNAVAILABLE_FROM_LEGACY_SOURCE')");
        $this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(
 id,issue_row_key,issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,corrected_at,correction_action,resolution_status,
 correction_reason,before_json,after_json,source,record_origin,legacy_source_references_json,supersedes_correction_id
) VALUES('50000000-0000-4000-8000-000000000007','issue-successor','LEGACY_HISTORICAL_EXCEPTION',
 '20000000-0000-4000-8000-000000000001','30000000-0000-4000-8000-000000000004','40000000-0000-4000-8000-000000000004',?,?,NOW(),
 'KEEP_AS_HISTORICAL_EXCEPTION','RESOLVED_BY_CORRECTION','Superseding decision','{}','{}','APPOINTMENT_DATA_ISSUE','NATIVE','[]',?)")
            ->execute([self::ASC_ONE,self::ACTOR,self::SUPERSEDED_CORRECTION]);
    }

    private function insertCorrection(PDOStatement $statement,string $id,string $rowKey,?string $appointmentId,?string $requestId,array $base):void
    {
        [$issueType,$officer,$asc,$actor,$action,$status,$reason,$remarks,$evidence,$before,$after,$source,$origin,$refs]=$base;
        $related=$appointmentId===null?'[]':json_encode([$appointmentId],JSON_THROW_ON_ERROR);
        $statement->execute([$id,$rowKey,$issueType,$officer,$appointmentId,$requestId,$related,$asc,$actor,$action,$status,$reason,$remarks,$evidence,$before,$after,$source,$origin,$refs]);
    }

    private function database():PDO
    {
        $config=config('database');
        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',$config['host'],$config['port'],$this->databaseName,$config['charset']),
            $config['username'],$config['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
        );
    }

    /** @return array<string,mixed> */
    private function row(string $sql,array $params=[]):array
    {
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);$row=$stmt->fetch();
        if(!$row)throw new RuntimeException('Expected fixture row was not found.');return $row;
    }

    private function count(string $sql,array $params=[]):int
    {
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);return (int)$stmt->fetchColumn();
    }

    private function canonicalJson(mixed $value):string
    {
        return json_encode($this->canonicalize($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }

    private function canonicalize(mixed $value):mixed
    {
        if(!is_array($value))return $value;
        if(array_is_list($value))return array_map(fn(mixed $item):mixed=>$this->canonicalize($item),$value);
        ksort($value,SORT_STRING);foreach($value as &$item)$item=$this->canonicalize($item);unset($item);return $value;
    }

    private function enabledInterfaceChild():void
    {
        $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--enabled-interface'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);
        if(!is_resource($process))throw new RuntimeException('Could not start enabled-interface isolated test child.');
        $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        $this->same(0,$code,'enabled-interface isolated child passes: '.$stderr);
        $this->same(true,str_contains((string)$stdout,'enabled interface'),'enabled-interface child exercised feature-gated lists');
    }

    private function same(mixed $expected,mixed $actual,string $message):void
    {
        $this->assertions++;
        if($expected!==$actual)throw new RuntimeException($message.'; expected '.var_export($expected,true).', got '.var_export($actual,true));
    }

    private function throwsDomain(callable $callback,string $message):void
    {
        $this->assertions++;
        try{$callback();}catch(DomainException){return;}catch(Throwable $e){throw new RuntimeException($message.'; expected DomainException, got '.get_class($e).': '.$e->getMessage(),0,$e);}
        throw new RuntimeException($message.'; no exception was thrown.');
    }

    private function throwsDomainMessage(callable $callback,string $contains,string $message):void
    {
        $this->assertions++;
        try{$callback();}
        catch(DomainException $e){if(str_contains($e->getMessage(),$contains))return;throw new RuntimeException($message.'; unexpected message: '.$e->getMessage(),0,$e);}
        catch(Throwable $e){throw new RuntimeException($message.'; expected DomainException, got '.get_class($e).': '.$e->getMessage(),0,$e);}
        throw new RuntimeException($message.'; no exception was thrown.');
    }

    private function throwsThrowable(callable $callback,string $message):void
    {
        $this->assertions++;
        try{$callback();}catch(Throwable){return;}throw new RuntimeException($message.'; no exception was thrown.');
    }
}

try{exit((new ArpaAppointmentCorrectionAmendmentDraftTest())->run($argv));}
catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
