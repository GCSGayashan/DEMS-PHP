<?php
declare(strict_types=1);

use App\Core\{Auth,Database};
use App\Services\{ArpaAppointmentService,ArpaDivisionContinuityService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaHistoricalGapLifecycleTest
{
    private const CANONICAL_GOVERNANCE_MESSAGE='This ARPA Division appointment is already operational after ASC approval and cannot be returned or rejected. Use the authorized administrative correction process if the historical assignment must be changed.';

    private PDO $pdo;
    private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();
        $before=$this->state();
        $this->pdo->beginTransaction();
        try{$this->exerciseHistoricalGapLifecycle();}finally{if($this->pdo->inTransaction())$this->pdo->rollBack();}
        $this->same($before,$this->state(),'focused lifecycle test leaves database state unchanged');
        echo "ArpaHistoricalGapLifecycleTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function exerciseHistoricalGapLifecycle():void
    {
        [$asc,$division]=$this->cleanDivision();
        $officers=$this->pdo->query("SELECT o.id FROM officer o JOIN designation d ON d.id=o.primary_designation_id AND d.system_key='ARPA_OFFICER' WHERE o.approval_status='APPROVED' AND o.operational_status='ACTIVE' AND NOT EXISTS(SELECT 1 FROM officer_office_assignment oa WHERE oa.officer_id=o.id) AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.officer_id=o.id) AND NOT EXISTS(SELECT 1 FROM arpa_subject_assignment s WHERE s.officer_id=o.id) ORDER BY o.id LIMIT 3 FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
        if(count($officers)<3)throw new RuntimeException('Three unassigned ARPA Officer fixtures are required.');
        [$historicalOfficer,$subsequentOfficer,$coverageOfficer]=array_map('strval',$officers);
        $creator=$this->user('historical-gap-maker');
        $approver=$this->user('historical-gap-approver');
        $this->authorizeAscSubject($creator,$asc,$approver);
        $office=(string)$this->value("SELECT o.id FROM office o JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' WHERE o.linked_location_id=? AND o.operational_status='ACTIVE' AND o.approval_status='APPROVED'",[$asc]);
        if($office==='')throw new RuntimeException('The fixture ASC Office is required.');
        foreach([$historicalOfficer,$subsequentOfficer] as $officer){
            $this->pdo->prepare("UPDATE officer SET arpa_service_permanency='PERMANENT_IN_SERVICE' WHERE id=?")->execute([$officer]);
            $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,is_primary,approval_status,active,reason,created_by,submitted_by,submitted_at,approved_by,approved_at) VALUES(UUID(),?,?,CURRENT_DATE(),1,'APPROVED',1,'Historical gap lifecycle fixture',?,?,NOW(),?,NOW())")->execute([$officer,$office,$creator,$creator,$approver]);
        }

        $this->seedPeriod($coverageOfficer,$asc,$division,'2025-01-01','2025-03-31',$approver);
        $this->seedPeriod($coverageOfficer,$asc,$division,'2025-07-01','2025-12-31',$approver);
        $reason=(string)$this->value("SELECT id FROM arpa_appointment_end_reason WHERE system_key='END_OF_APPOINTMENT_PERIOD' AND active=1");
        $service=new ArpaAppointmentService($this->pdo);
        $request=$service->createAndSubmitDivisionAppointmentRequest([
            'officer_id'=>$historicalOfficer,'appointment_type'=>'PERMANENT','asc_location_id'=>$asc,
            'arpa_division_location_id'=>$division,'effective_from'=>'2025-04-01','effective_to'=>'2025-06-30',
            'end_reason_id'=>$reason,'remarks'=>'Fill the bounded historical continuity gap',
        ],$creator);
        $this->same('SUBMITTED',(string)$this->value('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$request]),'historical request is Submitted');
        $this->same(0,$this->count('SELECT COUNT(*) FROM arpa_division_appointment WHERE request_id=?',[$request]),'Submitted historical request is not canonical');

        $service->workflow('division',$request,'VERIFY','ASC',null,$creator);
        $this->same(0,$this->count('SELECT COUNT(*) FROM arpa_division_appointment WHERE request_id=?',[$request]),'ASC-verified historical request is not canonical');
        $this->same('RETURNED',$service->workflow('division',$request,'RETURN_FOR_CORRECTION','ASC','Pre-materialization correction remains valid.',$approver),'pre-materialization return remains available');
        $service->workflow('division',$request,'SUBMIT','CREATOR','Corrected and resubmitted',$creator);
        $service->workflow('division',$request,'VERIFY','ASC',null,$creator);
        $this->same('RETURNED',$service->workflow('division',$request,'REJECT','ASC','Pre-materialization rejection remains valid.',$approver),'pre-materialization rejection remains available');
        $service->workflow('division',$request,'SUBMIT','CREATOR','Confirmed and resubmitted',$creator);
        $service->workflow('division',$request,'VERIFY','ASC',null,$creator);
        $this->same('ASC_APPROVED',$service->workflow('division',$request,'APPROVE','ASC',null,$approver),'ASC approval retains its governance status');

        $appointment=(string)$this->value('SELECT id FROM arpa_division_appointment WHERE request_id=?',[$request]);
        $this->same(1,$this->count('SELECT COUNT(*) FROM arpa_division_appointment WHERE request_id=?',[$request]),'ASC approval materializes exactly one canonical appointment');
        $this->same($request,(string)$this->value('SELECT request_id FROM arpa_division_appointment WHERE id=?',[$appointment]),'canonical appointment retains its source request ID');
        $this->same('2025-06-30',(string)$this->value('SELECT effective_to FROM arpa_division_appointment_closure WHERE appointment_id=? AND request_id=?',[$appointment,$request]),'bounded historical closure is materialized at ASC approval');

        $continuity=new ArpaDivisionContinuityService($this->pdo);
        $this->same('EXACT',$continuity->assertCanFillPeriod($division,'2026-01-01',null,null,null,false,false)['relation'],'continuity immediately recognizes the filled historical period');
        $subsequent=$service->createAndSubmitDivisionAppointmentRequest([
            'officer_id'=>$subsequentOfficer,'appointment_type'=>'PERMANENT','asc_location_id'=>$asc,
            'arpa_division_location_id'=>$division,'effective_from'=>'2026-01-01','remarks'=>'Subsequent continuous appointment',
        ],$creator);
        $this->same('SUBMITTED',(string)$this->value('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$subsequent]),'a subsequent continuous appointment can proceed after the gap is filled');

        $this->assertGovernanceActionsBlocked($service,$request,'DISTRICT');
        $this->same('DISTRICT_VERIFIED',$service->workflow('division',$request,'VERIFY','DISTRICT',null,$creator),'District verification succeeds after materialization');
        $this->assertGovernanceActionsBlocked($service,$request,'DISTRICT');
        $this->same('DISTRICT_APPROVED',$service->workflow('division',$request,'APPROVE','DISTRICT',null,$approver),'District approval succeeds after materialization');
        $this->assertGovernanceActionsBlocked($service,$request,'NATIONAL');
        $this->same('NATIONAL_VERIFIED',$service->workflow('division',$request,'VERIFY','NATIONAL',null,$creator),'National verification succeeds after materialization');
        $this->assertGovernanceActionsBlocked($service,$request,'NATIONAL');
        $this->same('NATIONAL_APPROVED',$service->workflow('division',$request,'APPROVE','NATIONAL',null,$approver),'National approval succeeds after materialization');
        $this->same(1,$this->count('SELECT COUNT(*) FROM arpa_division_appointment WHERE request_id=?',[$request]),'later governance creates no duplicate canonical appointment');
        $this->same(1,$this->count('SELECT COUNT(*) FROM arpa_division_appointment_closure WHERE appointment_id=? AND request_id=?',[$appointment,$request]),'later governance creates no duplicate historical closure');
        $this->same(0,$this->count('SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE appointment_id=?',[$appointment]),'lifecycle guard does not modify the administrative Data Issue correction path');
    }

    private function assertGovernanceActionsBlocked(ArpaAppointmentService $service,string $request,string $stage):void
    {
        $status=(string)$this->value('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$request]);
        $events=$this->count('SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE request_id=?',[$request]);
        foreach(['RETURN_FOR_CORRECTION','REJECT'] as $action){
            $this->throwsMessage(fn()=>$service->workflow('division',$request,$action,$stage,'Must use correction workflow',$this->userForAction($action)) ,self::CANONICAL_GOVERNANCE_MESSAGE,"{$stage} {$action} is blocked after materialization");
            $this->same($status,(string)$this->value('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$request]),"blocked {$stage} {$action} leaves status unchanged");
        }
        $this->same($events,$this->count('SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE request_id=?',[$request]),"blocked {$stage} actions create no workflow history");
    }

    private function userForAction(string $action):string
    {
        return (string)$this->value("SELECT id FROM system_user WHERE username IN('historical-gap-maker','historical-gap-approver') ORDER BY username=".$this->pdo->quote($action==='REJECT'?'historical-gap-approver':'historical-gap-maker')." DESC LIMIT 1");
    }

    /** @return array{0:string,1:string} */
    private function cleanDivision():array
    {
        $rows=$this->pdo->query("SELECT lr.parent_location_id asc_id,lr.child_location_id division_id FROM location_relationship lr JOIN location a ON a.id=lr.parent_location_id JOIN location d ON d.id=lr.child_location_id WHERE lr.relationship_type='ASC_ARPA_DIVISION' AND lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<='2025-04-01' AND (lr.effective_to IS NULL OR lr.effective_to>='2026-01-01') AND a.operational_status='ACTIVE' AND a.approval_status='APPROVED' AND a.effective_from<='2025-04-01' AND (a.effective_to IS NULL OR a.effective_to>='2026-01-01') AND d.operational_status='ACTIVE' AND d.approval_status='APPROVED' AND d.effective_from<='2025-04-01' AND (d.effective_to IS NULL OR d.effective_to>='2026-01-01') AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment x WHERE x.arpa_division_location_id=d.id) AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment_request r WHERE r.arpa_division_location_id=d.id AND r.deleted_at IS NULL) ORDER BY d.id LIMIT 100")->fetchAll();
        $continuity=new ArpaDivisionContinuityService($this->pdo);
        foreach($rows as $row)if($continuity->unresolvedDataIssues((string)$row['division_id'])===[])return [(string)$row['asc_id'],(string)$row['division_id']];
        throw new RuntimeException('A clean ARPA Division fixture is required.');
    }

    private function seedPeriod(string $officer,string $asc,string $division,string $from,string $to,string $actor):void
    {
        $location=$this->row('SELECT a.dad_number asc_dad,a.name_en asc_name,d.dad_number arpa_dad,d.name_en arpa_name FROM location a JOIN location d ON d.id=? WHERE a.id=?',[$division,$asc]);
        $request=$this->uuid();$appointment=$this->uuid();$reason=(string)$this->value("SELECT id FROM arpa_appointment_end_reason WHERE system_key='END_OF_APPOINTMENT_PERIOD'");
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,requested_effective_to,end_reason_id,workflow_status,created_by,finalized_by,finalized_at) VALUES(?,'NATIVE','APPOINTMENT',?,'DUTY_COVERING',?,?,?,?,?,'NATIONAL_APPROVED',?,?,NOW())")->execute([$request,$officer,$asc,$division,$from,$to,$reason,$actor,$actor]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,service_permanency_snapshot,asc_location_id,arpa_division_location_id,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,approved_by,approved_at) VALUES(?,'NATIVE',?,?,'DUTY_COVERING','PERMANENT_IN_SERVICE',?,?,?,?,?,?,'{}',?,?,NOW())")->execute([$appointment,$request,$officer,$asc,$division,$location['asc_dad'],$location['asc_name'],$location['arpa_dad'],$location['arpa_name'],$from,$actor]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_kind,context_snapshot_json,approved_by,approved_at) VALUES(UUID(),'NATIVE',?,?,?,?,'DIRECT','{}',?,NOW())")->execute([$appointment,$request,$to,$reason,$actor]);
    }

    private function user(string $username):string
    {
        $id=$this->uuid();$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE',1)")->execute([$id,$username,$username]);return $id;
    }

    private function authorizeAscSubject(string $user,string $asc,string $approver):void
    {
        $role=(string)$this->value("SELECT id FROM application_role WHERE role_code='ASC_SUBJECT_OFFICER'");
        $roleAssignment=$this->uuid();$scopeAssignment=$this->uuid();
        $this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason,created_by,approved_by,approved_at) VALUES(?,?,?,CURRENT_DATE(),'APPROVED',1,'Historical gap lifecycle fixture',?,?,NOW())")->execute([$roleAssignment,$user,$role,$approver,$approver]);
        $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason,created_by,approved_by,approved_at) VALUES(?,?,?,'ASC','EXACT',?,CURRENT_DATE(),'APPROVED',1,'Historical gap lifecycle fixture',?,?,NOW())")->execute([$scopeAssignment,$user,$roleAssignment,$asc,$approver,$approver]);
        $_SESSION=['user_id'=>$user,'authenticated_at'=>time(),'last_activity_at'=>time()];
        (new UserContextService($this->pdo))->select($user,$roleAssignment,$scopeAssignment);Auth::forgetRequestCache();
    }

    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function count(string $sql,array $params=[]):int{return (int)$this->value($sql,$params);}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function row(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throwsMessage(callable $callback,string $expected,string $message):void{$this->assertions++;try{$callback();}catch(DomainException $e){if($e->getMessage()===$expected)return;throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($e->getMessage(),true));}throw new RuntimeException($message.': expected DomainException');}
    private function state():array{return ['users'=>$this->count('SELECT COUNT(*) FROM system_user'),'office_assignments'=>$this->count('SELECT COUNT(*) FROM officer_office_assignment'),'requests'=>$this->count('SELECT COUNT(*) FROM arpa_division_appointment_request'),'appointments'=>$this->count('SELECT COUNT(*) FROM arpa_division_appointment'),'closures'=>$this->count('SELECT COUNT(*) FROM arpa_division_appointment_closure'),'workflow'=>$this->count('SELECT COUNT(*) FROM arpa_appointment_workflow_action'),'audit'=>$this->count('SELECT COUNT(*) FROM audit_event'),'corrections'=>$this->count('SELECT COUNT(*) FROM arpa_appointment_data_correction')];}
}

exit((new ArpaHistoricalGapLifecycleTest())->run());
