<?php
declare(strict_types=1);

use App\Controllers\ArpaAppointmentController;
use App\Core\{DataTableQuery,DataTableRegistry,DataTableRequest,Database};
use App\Services\{ArpaAppointmentReadService,ArpaAppointmentService,ArpaWorkflowQueuePolicy,ScopedDashboardService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaWorkflowQueueTest
{
    private PDO $pdo;private int $assertions=0;private string $asctest;private string $asc;private string $district;

    public function run():int
    {
        $this->pdo=Database::pdo();$before=$this->state();
        $this->asctest=(string)$this->pdo->query("SELECT id FROM system_user WHERE username='asctest' AND enabled=1 AND account_status='ACTIVE'")->fetchColumn();
        if($this->asctest==='')throw new RuntimeException('Operational asctest fixture is required.');
        $_SESSION=['user_id'=>$this->asctest];$this->layoutTest();$this->workflowTest();
        $this->same($before,$this->state(),'workflow queue test leaves migrated, native, role, scope, and audit state unchanged');
        echo "ArpaWorkflowQueueTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function layoutTest():void
    {
        $_SERVER['REQUEST_URI']='/DEMS-PHP/public/hr/arpa-appointments';ob_start();(new ArpaAppointmentController())->dashboard();$html=(string)ob_get_clean();
        foreach(['<!doctype html>','class="topbar"','class="sidebar"','ARPA Appointment Dashboard','assets/css/app.css','assets/js/dems-charts.js','Submitted Appointments','Approval / Verification'] as $needle)$this->same(true,str_contains($html,$needle),"dashboard layout contains {$needle}");
        $this->same(false,str_contains($html,'asset('),'dashboard output has no unresolved asset helper call');
    }

    private function workflowTest():void
    {
        $this->pdo->beginTransaction();
        try{
            $scope=$this->pdo->prepare("SELECT uas.location_id FROM user_account_scope uas JOIN location l ON l.id=uas.location_id JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC' WHERE uas.user_id=? AND uas.scope_mode='EXACT' AND uas.active=1 AND uas.approval_status='APPROVED' LIMIT 1");$scope->execute([$this->asctest]);$this->asc=(string)$scope->fetchColumn();
            $parents=$this->pdo->prepare("WITH RECURSIVE p(id) AS (SELECT ? UNION DISTINCT SELECT lr.parent_location_id FROM location_relationship lr JOIN p ON p.id=lr.child_location_id WHERE lr.active=1 AND lr.approval_status='APPROVED') SELECT l.id FROM p JOIN location l ON l.id=p.id JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='DISTRICT' LIMIT 1");$parents->execute([$this->asc]);$this->district=(string)$parents->fetchColumn();
            if($this->asc===''||$this->district==='')throw new RuntimeException('Kurunegala ASC and District fixtures are required.');

            $ascAdmin=$this->actor('ASC_ADMIN','ASC','EXACT',$this->asc);
            $districtSubject=$this->actor('DISTRICT_SUBJECT_OFFICER','DISTRICT','INCLUDE_CHILDREN',$this->district);
            $districtAdmin=$this->actor('DISTRICT_ADMIN','DISTRICT','INCLUDE_CHILDREN',$this->district);
            $nationalSubject=$this->actor('NATIONAL_SUBJECT_OFFICER',null,null,null,false);
            $nationalAdmin=$this->actor('NATIONAL_ADMIN','NATIONAL','NATIONAL',null);

            $policy=new ArpaWorkflowQueuePolicy($this->pdo);
            $this->same(false,$policy->canUseWorkflowQueues($nationalSubject),'National role without explicit NATIONAL scope cannot open a workflow queue');
            $this->scope($nationalSubject,'NATIONAL','NATIONAL',null);
            $this->same(true,$policy->canUseWorkflowQueues($nationalSubject),'explicit NATIONAL scope enables the National queue');

            $read=new ArpaAppointmentReadService($this->pdo);$today=date('Y-m-d');
            $officers=$read->eligibleOfficersForAsc($this->asctest,$this->asc,$today);$divisions=$read->vacantDivisionsForAsc($this->asctest,$this->asc,$today);
            if($officers===[]||$divisions===[])throw new RuntimeException('Eligible Kurunegala Officer and vacant Division fixtures are required.');
            $service=new ArpaAppointmentService($this->pdo);
            $request=$service->createDivisionAppointmentRequest(['officer_id'=>$officers[0]['id'],'appointment_type'=>'DUTY_COVERING','asc_location_id'=>$this->asc,'arpa_division_location_id'=>$divisions[0]['id'],'effective_from'=>$today,'remarks'=>'Transactional workflow queue test'],$this->asctest);

            $new=DataTableRegistry::definition('arpa-new-appointments');$new['baseWhere'][]='r.id=?';$new['baseParams'][]=$request;
            $this->same(1,$this->tableCount($new),'asctest sees their own CREATED request in New Appointments');
            $this->same(0,$this->inbox($this->asctest,$request),'CREATED request is not yet in the ASC submitted inbox');
            $service->workflow('division',$request,'SUBMIT','CREATOR',null,$this->asctest);
            $this->same(1,$this->inbox($this->asctest,$request),'asctest sees submitted Kurunegala request before ASC verification');
            $this->same(0,$this->completed($this->asctest,$request),'ASC verification is absent before action');

            $other=$this->outsideAscRequest((string)$officers[0]['id'],$this->asctest,$today);
            $this->same(0,$this->inbox($this->asctest,$other),'ASC EXACT scope excludes another ASC actionable request');
            $historyOnly=$this->historyOnlyRequest((string)$officers[0]['id'],$this->asc,(string)$divisions[1]['id'],$this->asctest,$today);
            $this->same(0,$this->inbox($this->asctest,$historyOnly),'legacy_history_only request is excluded from the live inbox');

            $service->workflow('division',$request,'VERIFY','ASC',null,$this->asctest);
            $this->same(0,$this->inbox($this->asctest,$request),'ASC verification removes request from ASC Subject Officer inbox');
            $this->same(1,$this->completed($this->asctest,$request),'ASC verification appears in asctest completed actions');
            $this->same(1,$this->inbox($ascAdmin,$request),'Kurunegala ASC Administrator receives ASC_VERIFIED request');
            $this->throws(fn()=>$service->workflow('division',$request,'APPROVE','ASC',null,$this->asctest),'creator cannot approve their own ASC request');

            $service->workflow('division',$request,'APPROVE','ASC',null,$ascAdmin);
            $this->same(0,$this->inbox($ascAdmin,$request),'ASC approval removes request from ASC Administrator inbox');
            $this->same(1,$this->completed($ascAdmin,$request),'ASC approval appears in Administrator completed actions');
            $this->same(1,$this->inbox($districtSubject,$request),'District Subject Officer receives ASC_APPROVED child-ASC request');

            $service->saveStageReview('division',$request,'DISTRICT','District review','Transactional test',$districtSubject);
            $service->workflow('division',$request,'VERIFY','DISTRICT',null,$districtSubject);
            $this->same(0,$this->inbox($districtSubject,$request),'District verification removes request from Subject Officer inbox');
            $this->same(1,$this->completed($districtSubject,$request),'District verification appears in completed actions');
            $this->same(1,$this->inbox($districtAdmin,$request),'District Administrator receives DISTRICT_VERIFIED request');

            $eventCount=(int)$this->scalar('SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE request_id=?',[$request]);
            $auditCount=(int)$this->scalar('SELECT COUNT(*) FROM audit_event WHERE target_id=?',[$request]);
            $this->throws(fn()=>$service->workflow('division',$request,'REJECT','DISTRICT','   ',$districtAdmin),'blank rejection reason is rejected');
            $this->same('DISTRICT_VERIFIED',(string)$this->scalar('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$request]),'failed rejection leaves workflow status unchanged');
            $this->same($eventCount,(int)$this->scalar('SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE request_id=?',[$request]),'failed rejection writes no workflow event');
            $this->same($auditCount,(int)$this->scalar('SELECT COUNT(*) FROM audit_event WHERE target_id=?',[$request]),'failed rejection writes no audit event');

            $reason='Please correct the appointment effective date.';
            $service->workflow('division',$request,'REJECT','DISTRICT',$reason,$districtAdmin);
            $this->same('RETURNED',(string)$this->scalar('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$request]),'District Administrator rejection returns request to ASC correction');
            $this->same(1,$this->inbox($this->asctest,$request),'returned request appears in ASC Subject Officer inbox');
            $this->same(0,$this->completed($this->asctest,$request),'rejection invalidates ASC Subject Officer successful actions in the current cycle');
            $this->same(0,$this->completed($ascAdmin,$request),'rejection invalidates ASC Administrator successful actions in the current cycle');
            $this->same(0,$this->completed($districtSubject,$request),'rejection invalidates District Subject Officer successful actions in the current cycle');
            $event=$this->row("SELECT action,stage,user_id,comments,previous_status,new_status FROM arpa_appointment_workflow_action WHERE request_id=? ORDER BY id DESC LIMIT 1",[$request]);
            $this->same(['action'=>'REJECT','stage'=>'DISTRICT','user_id'=>$districtAdmin,'comments'=>$reason,'previous_status'=>'DISTRICT_VERIFIED','new_status'=>'RETURNED'],$event,'append-only rejection event retains actor, level, reason, and status boundary');
            $this->same(5,(int)$this->scalar('SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE request_id=?',[$request]),'all first-cycle workflow actions remain preserved');
            $audit=json_decode((string)$this->scalar("SELECT details_json FROM audit_event WHERE target_id=? AND action_key='arpa.division-workflow.reject' ORDER BY id DESC LIMIT 1",[$request]),true);
            $this->same($reason,$audit['reason']??null,'rejection reason is retained in the transactionally committed audit event');

            $returned=DataTableRegistry::definition('arpa-submitted-appointments');$returned['baseWhere'][]='r.id=?';$returned['baseParams'][]=$request;
            $returnedResponse=(new DataTableQuery($this->pdo,$returned,new DataTableRequest(['length'=>10])))->response();
            $this->same(1,$returnedResponse['recordsFiltered'],'returned record is present in the server-side ASC inbox');
            $returnedRow=$returnedResponse['data'][0];
            $this->same('RETURNED FOR CORRECTION',strip_tags($returnedRow['workflow_status']),'returned inbox uses the correction warning status');
            $this->same($reason,strip_tags($returnedRow['return_reason']),'returned inbox displays the correction reason');
            $this->same('DISTRICT',strip_tags($returnedRow['returned_level']),'returned inbox displays rejecting level');
            $this->same(true,str_contains($returnedRow['actions'],'Resubmit'),'returned inbox exposes resubmit only to ASC correction role');
            $detailTemplate=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/request_detail.php');
            foreach(['RETURNED FOR CORRECTION','Workflow History','Stage / Level','Performed By','Reason / Comments','Unavailable from legacy source'] as $needle)$this->same(true,str_contains($detailTemplate,$needle),"returned request detail presents {$needle}");

            $ascCorrection=$this->actor('ASC_SUBJECT_OFFICER','ASC','EXACT',$this->asc);
            $this->same(true,$policy->canCorrectReturnedRequest($ascCorrection,$this->asc,$today),'another authorized ASC Subject Officer can own correction in the same ASC scope');
            $outsideAsc=(string)$this->scalar("SELECT l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC' WHERE l.id<>? AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' LIMIT 1",[$this->asc]);
            $outsideCorrection=$this->actor('ASC_SUBJECT_OFFICER','ASC','EXACT',$outsideAsc);
            $this->same(false,$policy->canCorrectReturnedRequest($outsideCorrection,$this->asc,$today),'ASC correction permission does not bypass geographic scope');
            $this->throws(fn()=>$service->workflow('division',$request,'SUBMIT','CREATOR','Out-of-scope attempt',$outsideCorrection),'out-of-scope ASC officer cannot resubmit by direct service call');
            $service->workflow('division',$request,'SUBMIT','CREATOR','Corrected and resubmitted',$ascCorrection);
            $this->same('SUBMITTED',(string)$this->scalar('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$request]),'resubmission starts a new review cycle on the original request');
            $this->same(1,$this->inbox($this->asctest,$request),'second cycle re-enters ASC verification inbox');

            $service->workflow('division',$request,'VERIFY','ASC','Second-cycle ASC verification',$this->asctest);
            $service->workflow('division',$request,'APPROVE','ASC','Second-cycle ASC approval',$ascAdmin);
            $service->workflow('division',$request,'VERIFY','DISTRICT','Second-cycle District verification',$districtSubject);
            $service->workflow('division',$request,'APPROVE','DISTRICT','Second-cycle District approval',$districtAdmin);
            $this->same(1,$this->inbox($nationalSubject,$request),'National Subject Officer receives the successful second cycle');

            $service->saveStageReview('division',$request,'NATIONAL','National review','Transactional test',$nationalSubject);
            $service->workflow('division',$request,'VERIFY','NATIONAL',null,$nationalSubject);
            $this->same(0,$this->inbox($nationalSubject,$request),'National verification removes request from Subject Officer inbox');
            $this->same(1,$this->completed($nationalSubject,$request),'National verification appears in completed actions');
            $this->same(1,$this->inbox($nationalAdmin,$request),'National Administrator receives NATIONAL_VERIFIED request');

            $service->workflow('division',$request,'APPROVE','NATIONAL',null,$nationalAdmin);
            $this->same(0,$this->inbox($nationalAdmin,$request),'National approval removes request from Administrator inbox');
            $this->same(1,$this->completed($nationalAdmin,$request),'National approval appears in completed actions');
            foreach([$this->asctest,$ascAdmin,$districtSubject,$districtAdmin,$nationalSubject,$nationalAdmin] as $successfulActor)$this->same(1,$this->completed($successfulActor,$request),'each second-cycle actor retains one successful current-cycle action after National approval');
            $this->same(12,(int)$this->scalar('SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE request_id=?',[$request]),'first-cycle rejection history and second-cycle success history both remain append-only');
            $this->same('NATIONAL_APPROVED',(string)$this->scalar('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$request]),'native workflow reaches NATIONAL_APPROVED');
            $this->same(1,(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment WHERE request_id=?',[$request]),'final native approval creates the operational appointment');

            $dashboard=(new ScopedDashboardService($this->pdo))->arpaModuleCounts($this->asctest);
            $this->same($policy->actionableCount($this->asctest),$dashboard['pending'],'dashboard pending counter uses the same role-specific inbox policy');

            $completed=DataTableRegistry::definition('arpa-approval-verification');$completed['baseWhere'][]='r.id=?';$completed['baseParams'][]=$request;
            $response=(new DataTableQuery($this->pdo,$completed,new DataTableRequest(['length'=>10])))->response();
            $this->same(1,$response['recordsFiltered'],'completed-actions DataTable retains asctest ASC verification after later stages');
            $this->same('ASC_VERIFIED',strip_tags($response['data'][0]['resulting_status']),'completed DataTable reports the resulting stage');

            $controller=file_get_contents(BASE_PATH.'/app/Controllers/DataTableController.php');
            $this->same(true,str_contains($controller,"isset(\$config['authorize'])"),'direct DataTable endpoint enforces queue-specific authorization');
        }finally{$this->pdo->rollBack();}
    }

    private function actor(string $role,?string $scopeType,?string $scopeMode,?string $location,bool $addScope=true):string
    {
        $id=$this->uuid();$username='queue-'.strtolower(str_replace('_','-',$role)).'-'.substr($id,0,6);
        $this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE',1)")->execute([$id,$username,$role]);
        $roleId=(string)$this->scalar('SELECT id FROM application_role WHERE role_code=?',[$role]);
        $this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason,created_by,approved_by,approved_at) VALUES(UUID(),?,?,CURRENT_DATE(),'APPROVED',1,'Workflow queue test',?,?,NOW())")->execute([$id,$roleId,$this->asctest,$this->asctest]);
        if($addScope&&$scopeType!==null&&$scopeMode!==null)$this->scope($id,$scopeType,$scopeMode,$location);return $id;
    }

    private function scope(string $user,string $type,string $mode,?string $location):void
    {
        $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason,created_by,approved_by,approved_at) VALUES(UUID(),?,?,?,?,CURRENT_DATE(),'APPROVED',1,'Workflow queue test',?,?,NOW())")->execute([$user,$type,$mode,$location,$this->asctest,$this->asctest]);
    }

    private function inbox(string $user,string $request):int
    {
        $access=(new ArpaWorkflowQueuePolicy($this->pdo))->requestAccess($user,'r');
        return (int)$this->scalar($access['with']."SELECT COUNT(*) FROM arpa_division_appointment_request r WHERE r.id=? AND r.record_origin='NATIVE' AND r.legacy_history_only=0 AND {$access['where']}",array_merge($access['params'],[$request]));
    }

    private function completed(string $user,string $request):int
    {
        $access=(new ArpaWorkflowQueuePolicy($this->pdo))->completedAccess($user,'r','w');
        return (int)$this->scalar($access['with']."SELECT COUNT(*) FROM arpa_appointment_workflow_action w JOIN arpa_division_appointment_request r ON r.id=w.request_id WHERE w.user_id=? AND r.id=? AND w.record_origin='NATIVE' AND r.legacy_history_only=0 AND {$access['where']}",array_merge($access['params'],[$user,$request]));
    }

    private function outsideAscRequest(string $officer,string $creator,string $date):string
    {
        $row=$this->pdo->query("SELECT a.id asc_id,d.id division_id FROM location a JOIN location_type at ON at.id=a.location_type_id AND at.system_key='ASC' JOIN location_relationship lr ON lr.parent_location_id=a.id AND lr.active=1 AND lr.approval_status='APPROVED' JOIN location d ON d.id=lr.child_location_id JOIN location_type dt ON dt.id=d.location_type_id AND dt.system_key='ARPA_DIVISION' WHERE a.id<>'{$this->asc}' LIMIT 1")->fetch();
        $id=$this->uuid();$this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,legacy_history_only,created_by) VALUES(?,'NATIVE','APPOINTMENT',?,'DUTY_COVERING',?,?,?,'SUBMITTED',0,?)")->execute([$id,$officer,$row['asc_id'],$row['division_id'],$date,$creator]);return $id;
    }

    private function historyOnlyRequest(string $officer,string $asc,string $division,string $creator,string $date):string
    {
        $id=$this->uuid();$this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,legacy_history_only,legacy_exception,created_by) VALUES(?,'NATIVE','APPOINTMENT',?,'DUTY_COVERING',?,?,?,'SUBMITTED',1,1,?)")->execute([$id,$officer,$asc,$division,$date,$creator]);return $id;
    }

    private function tableCount(array $definition):int{return (new DataTableQuery($this->pdo,$definition,new DataTableRequest(['length'=>10])))->response()['recordsFiltered'];}
    private function scalar(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function row(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function throws(callable $fn,string $message):void{$this->assertions++;try{$fn();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function state():array{return ['legacy_requests'=>(int)$this->pdo->query("SELECT COUNT(*) FROM arpa_division_appointment_request WHERE record_origin='LEGACY_IMPORT'")->fetchColumn(),'legacy_appointments'=>(int)$this->pdo->query("SELECT COUNT(*) FROM arpa_division_appointment WHERE record_origin='LEGACY_IMPORT'")->fetchColumn(),'native_requests'=>(int)$this->pdo->query("SELECT COUNT(*) FROM arpa_division_appointment_request WHERE record_origin='NATIVE'")->fetchColumn(),'native_appointments'=>(int)$this->pdo->query("SELECT COUNT(*) FROM arpa_division_appointment WHERE record_origin='NATIVE'")->fetchColumn(),'roles'=>(int)$this->pdo->query('SELECT COUNT(*) FROM user_account_role')->fetchColumn(),'scopes'=>(int)$this->pdo->query('SELECT COUNT(*) FROM user_account_scope')->fetchColumn(),'decisions'=>(int)$this->pdo->query("SELECT COUNT(*) FROM legacy_arpa_appointment_resolution WHERE resolution_status='CONFIRMED'")->fetchColumn()];}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
}

exit((new ArpaWorkflowQueueTest())->run());
