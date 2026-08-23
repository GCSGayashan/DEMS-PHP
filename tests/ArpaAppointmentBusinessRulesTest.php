<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\ArpaAppointmentReadService;
use App\Services\ArpaAppointmentService;

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentBusinessRulesTest
{
    private PDO $pdo;
    private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$before=$this->state();$this->pdo->beginTransaction();
        try{$this->testRules();}finally{if($this->pdo->inTransaction())$this->pdo->rollBack();}
        $this->same($before,$this->state(),'business-rule tests leave the database unchanged');
        echo "ArpaAppointmentBusinessRulesTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function testRules():void
    {
        $today=date('Y-m-d');$future=date('Y-m-d',strtotime('+30 days'));
        $pastStart=date('Y-m-d',strtotime('-120 days'));$pastEnd=date('Y-m-d',strtotime('-30 days'));
        $actor=(string)$this->pdo->query('SELECT id FROM system_user ORDER BY id LIMIT 1')->fetchColumn();
        $fixture=$this->locationFixture($today);$asc=(string)$fixture['asc_id'];$office=(string)$fixture['office_id'];$divisions=$fixture['divisions'];
        $officers=$this->pdo->query("SELECT o.id FROM officer o JOIN designation d ON d.id=o.primary_designation_id AND d.system_key='ARPA_OFFICER'
            WHERE NOT EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.officer_id=o.id)
              AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment_request r WHERE r.officer_id=o.id AND r.workflow_status IN('SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED'))
            ORDER BY o.id LIMIT 2 FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
        if(count($officers)<2)throw new RuntimeException('Two unused ARPA Officer fixtures are required.');
        [$permanentOfficer,$nonPermanentOfficer]=array_map('strval',$officers);
        $this->pdo->prepare("UPDATE officer SET arpa_service_permanency='PERMANENT_IN_SERVICE' WHERE id=?")->execute([$permanentOfficer]);
        $this->pdo->prepare("UPDATE officer SET arpa_service_permanency='NOT_PERMANENT_IN_SERVICE' WHERE id=?")->execute([$nonPermanentOfficer]);
        foreach($officers as $officer)$this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,approval_status,active,reason,created_by,submitted_by,submitted_at,approved_by,approved_at) VALUES(UUID(),?,?,?,'APPROVED',1,'ARPA business-rule test',?,?,NOW(),?,NOW())")->execute([$officer,$office,$pastStart,$actor,$actor,$actor]);

        $service=new ArpaAppointmentService($this->pdo);$read=new ArpaAppointmentReadService($this->pdo);
        $this->same(['PERMANENT'],$read->appointmentTypeAvailability($permanentOfficer,$today)['allowed_types'],'Permanent-in-Service officer without a foundation may only receive Permanent');
        $permanentRequest=$service->createAndSubmitDivisionAppointmentRequest($this->request($permanentOfficer,'PERMANENT',$asc,$divisions[0],$today),$actor);
        $this->same('SUBMITTED',$this->value('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$permanentRequest]),'first Permanent assignment is submitted');
        $this->throwsMessage(fn()=>$service->createAndSubmitDivisionAppointmentRequest($this->request($permanentOfficer,'PERMANENT',$asc,$divisions[1],$today),$actor),'This officer already has a Permanent ARPA Division assignment.','submitted Permanent reserves the officer');
        $this->promoteRequest($permanentRequest,$actor);
        $this->throwsMessage(fn()=>$service->createAndSubmitDivisionAppointmentRequest($this->request($permanentOfficer,'PERMANENT',$asc,$divisions[1],$today),$actor),'This officer already has a Permanent ARPA Division assignment.','current Permanent blocks another overlapping Permanent');
        $this->same(['ACTING','DUTY_COVERING'],$read->appointmentTypeAvailability($permanentOfficer,$today)['allowed_types'],'Permanent-in-Service officer with a foundation may receive Acting and Duty Covering');

        $actingRequest=$service->createAndSubmitDivisionAppointmentRequest($this->request($permanentOfficer,'ACTING',$asc,$divisions[1],$today),$actor);
        $this->same('SUBMITTED',$this->value('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$actingRequest]),'eligible Acting assignment is submitted');
        $this->throwsMessage(fn()=>$service->createAndSubmitDivisionAppointmentRequest($this->request($permanentOfficer,'ACTING',$asc,$divisions[2],$today),$actor),'This officer already has an Acting assignment.','second overlapping Acting is blocked');
        $this->same(['DUTY_COVERING'],$read->appointmentTypeAvailability($permanentOfficer,$today)['allowed_types'],'existing Acting leaves Duty Covering available');
        $this->throwsMessage(fn()=>$service->createAndSubmitDivisionAppointmentRequest($this->request($permanentOfficer,'ATTEND_TO_DUTY',$asc,$divisions[2],$today),$actor),'Attend to the Duty can only be assigned to an officer who is not Permanent In Service.','Permanent-in-Service officer cannot receive Attend to the Duty');

        $firstDuty=$service->createAndSubmitDivisionAppointmentRequest($this->request($permanentOfficer,'DUTY_COVERING',$asc,$divisions[2],$today),$actor);
        $secondDuty=$service->createAndSubmitDivisionAppointmentRequest($this->request($permanentOfficer,'DUTY_COVERING',$asc,$divisions[3],$today),$actor);
        $this->same(2,$this->count("SELECT COUNT(*) FROM arpa_division_appointment_request WHERE id IN(?,?) AND workflow_status='SUBMITTED'",[$firstDuty,$secondDuty]),'multiple Duty Covering assignments to different Divisions are allowed');
        $this->throwsMessage(fn()=>$service->createAndSubmitDivisionAppointmentRequest($this->request($permanentOfficer,'DUTY_COVERING',$asc,$divisions[2],$today),$actor),'This officer already covers this ARPA Division for the selected period.','duplicate submitted Duty Covering is blocked for the same Division');
        $this->createApprovedAppointment($permanentOfficer,'DUTY_COVERING',$asc,$divisions[4],$future,$actor);
        $this->throwsMessage(fn()=>$service->createAndSubmitDivisionAppointmentRequest($this->request($permanentOfficer,'DUTY_COVERING',$asc,$divisions[4],$today),$actor),'This officer already covers this ARPA Division for the selected period.','future scheduled Duty Covering reserves its Division period');

        foreach(['ACTING','ATTEND_TO_DUTY','DUTY_COVERING'] as $dependent)$this->throwsMessage(
            fn()=>$service->createAndSubmitDivisionAppointmentRequest($this->request($nonPermanentOfficer,$dependent,$asc,$divisions[5],$today),$actor),
            'Assign a Permanent ARPA Division to this officer first.',
            "{$dependent} requires a qualifying Permanent assignment"
        );
        $endedPermanent=$this->createApprovedAppointment($nonPermanentOfficer,'PERMANENT',$asc,$divisions[5],$pastStart,$actor);
        $this->closeAppointment($endedPermanent,$pastEnd,$actor);
        $this->same(['PERMANENT'],$read->appointmentTypeAvailability($nonPermanentOfficer,$today)['allowed_types'],'ended historical Permanent does not block a new valid Permanent');
        $newPermanent=$service->createAndSubmitDivisionAppointmentRequest($this->request($nonPermanentOfficer,'PERMANENT',$asc,$divisions[6],$today),$actor);
        $this->promoteRequest($newPermanent,$actor);
        $this->same(['DUTY_COVERING','ATTEND_TO_DUTY'],$read->appointmentTypeAvailability($nonPermanentOfficer,$today)['allowed_types'],'non-permanent officer with a foundation may receive Attend to the Duty and Duty Covering');
        $this->throwsMessage(fn()=>$service->createAndSubmitDivisionAppointmentRequest($this->request($nonPermanentOfficer,'ACTING',$asc,$divisions[7],$today),$actor),'Acting can only be assigned to an officer who is Permanent In Service.','forged Acting type is rejected server-side');
        $attend=$service->createAndSubmitDivisionAppointmentRequest($this->request($nonPermanentOfficer,'ATTEND_TO_DUTY',$asc,$divisions[7],$today),$actor);
        $this->same('SUBMITTED',$this->value('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$attend]),'eligible Attend to the Duty assignment is submitted');
        $this->throwsMessage(fn()=>$service->createAndSubmitDivisionAppointmentRequest($this->request($nonPermanentOfficer,'ATTEND_TO_DUTY',$asc,$divisions[8],$today),$actor),'This officer already has an Attend to the Duty assignment.','second overlapping Attend to the Duty is blocked');
        $this->same(['DUTY_COVERING'],$read->appointmentTypeAvailability($nonPermanentOfficer,$today)['allowed_types'],'existing Attend to the Duty leaves Duty Covering available');

        $otherDivision=(string)$this->value("SELECT lr.child_location_id FROM location_relationship lr WHERE lr.relationship_type='ASC_ARPA_DIVISION' AND lr.parent_location_id<>? AND lr.active=1 AND lr.approval_status='APPROVED' LIMIT 1",[$asc]);
        $this->throws(fn()=>$service->createAndSubmitDivisionAppointmentRequest($this->request($permanentOfficer,'DUTY_COVERING',$asc,$otherDivision,$today),$actor),'a Division outside the selected ASC is rejected');
        $controller=(string)file_get_contents(BASE_PATH.'/app/Controllers/ArpaAppointmentController.php');
        $this->same(true,str_contains($controller,'The submitted Agrarian Service Center does not match your current working context.'),'forged ASC remains rejected against the active working context');
        $form=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/division_form.php');
        $this->same(true,str_contains($form,'data-allowed-types'),'form receives server-derived allowed appointment types');
    }

    /** @return array{asc_id:string,office_id:string,divisions:list<string>} */
    private function locationFixture(string $date):array
    {
        $status="'SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED'";
        $stmt=$this->pdo->prepare("SELECT lr.parent_location_id asc_id,ofc.id office_id,COUNT(*) available
            FROM location_relationship lr JOIN location l ON l.id=lr.child_location_id
            JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ARPA_DIVISION'
            JOIN office ofc ON ofc.linked_location_id=lr.parent_location_id JOIN office_type ot ON ot.id=ofc.office_type_id AND ot.system_key='ASC_OFFICE'
            WHERE lr.relationship_type='ASC_ARPA_DIVISION' AND lr.active=1 AND lr.approval_status='APPROVED'
              AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)
              AND l.approval_status='APPROVED' AND l.operational_status='ACTIVE'
              AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.arpa_division_location_id=l.id AND a.legacy_history_only=0 AND (c.effective_to IS NULL OR c.effective_to>=?))
              AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment_request r WHERE r.arpa_division_location_id=l.id AND r.record_origin='NATIVE' AND r.legacy_history_only=0 AND r.request_type IN('APPOINTMENT','TRANSFER') AND r.workflow_status IN({$status}))
            GROUP BY lr.parent_location_id,ofc.id HAVING available>=10 ORDER BY available DESC LIMIT 1");
        $stmt->execute([$date,$date,$date]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('An ASC with ten vacant ARPA Divisions is required.');
        $divisionStmt=$this->pdo->prepare("SELECT l.id FROM location_relationship lr JOIN location l ON l.id=lr.child_location_id JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ARPA_DIVISION'
            WHERE lr.parent_location_id=? AND lr.relationship_type='ASC_ARPA_DIVISION' AND lr.active=1 AND lr.approval_status='APPROVED'
              AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.arpa_division_location_id=l.id AND a.legacy_history_only=0 AND (c.effective_to IS NULL OR c.effective_to>=?))
              AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment_request r WHERE r.arpa_division_location_id=l.id AND r.record_origin='NATIVE' AND r.legacy_history_only=0 AND r.request_type IN('APPOINTMENT','TRANSFER') AND r.workflow_status IN({$status}))
            ORDER BY l.dad_number LIMIT 10 FOR UPDATE");
        $divisionStmt->execute([$row['asc_id'],$date]);$divisions=array_map('strval',$divisionStmt->fetchAll(PDO::FETCH_COLUMN));
        return ['asc_id'=>(string)$row['asc_id'],'office_id'=>(string)$row['office_id'],'divisions'=>$divisions];
    }

    private function request(string $officer,string $type,string $asc,string $division,string $from):array
    {return ['officer_id'=>$officer,'appointment_type'=>$type,'asc_location_id'=>$asc,'arpa_division_location_id'=>$division,'effective_from'=>$from];}

    private function createApprovedAppointment(string $officer,string $type,string $asc,string $division,string $from,string $actor):string
    {
        $request=$this->uuid();$this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,legacy_history_only,created_by) VALUES(?,'NATIVE','APPOINTMENT',?,?,?,?,?,'NATIONAL_APPROVED',0,?)")->execute([$request,$officer,$type,$asc,$division,$from,$actor]);
        return $this->promoteRequest($request,$actor);
    }

    private function promoteRequest(string $requestId,string $actor):string
    {
        $stmt=$this->pdo->prepare('SELECT r.*,o.arpa_service_permanency,asc_l.dad_number asc_dad,asc_l.name_en asc_name,arpa.dad_number arpa_dad,arpa.name_en arpa_name FROM arpa_division_appointment_request r JOIN officer o ON o.id=r.officer_id JOIN location asc_l ON asc_l.id=r.asc_location_id JOIN location arpa ON arpa.id=r.arpa_division_location_id WHERE r.id=?');
        $stmt->execute([$requestId]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Request fixture was not found.');
        $this->pdo->prepare("UPDATE arpa_division_appointment_request SET workflow_status='NATIONAL_APPROVED',finalized_by=?,finalized_at=NOW() WHERE id=?")->execute([$actor,$requestId]);
        $appointment=$this->uuid();$this->pdo->prepare('INSERT INTO arpa_division_appointment(id,request_id,officer_id,appointment_type,service_permanency_snapshot,asc_location_id,arpa_division_location_id,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,approved_by,approved_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')->execute([$appointment,$requestId,$row['officer_id'],$row['appointment_type'],$row['arpa_service_permanency'],$row['asc_location_id'],$row['arpa_division_location_id'],$row['asc_dad'],$row['asc_name'],$row['arpa_dad'],$row['arpa_name'],'{}',$row['requested_effective_from'],$actor]);
        return $appointment;
    }

    private function closeAppointment(string $appointment,string $to,string $actor):void
    {
        $request=(string)$this->value('SELECT request_id FROM arpa_division_appointment WHERE id=?',[$appointment]);
        $reason=(string)$this->pdo->query("SELECT id FROM arpa_appointment_end_reason WHERE active=1 ORDER BY display_order,id LIMIT 1")->fetchColumn();
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,appointment_id,request_id,effective_to,end_reason_id,closure_kind,context_snapshot_json,approved_by,approved_at) VALUES(UUID(),?,?,?,?,'DIRECT','{}',?,NOW())")->execute([$appointment,$request,$to,$reason,$actor]);
    }

    private function state():array
    {
        $tables=['officer','officer_office_assignment','arpa_division_appointment_request','arpa_division_appointment','arpa_division_appointment_closure','arpa_appointment_workflow_action','audit_event'];$state=[];
        foreach($tables as $table)$state[$table]=(int)$this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();return $state;
    }
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function value(string $sql,array $params=[]):mixed{$stmt=$this->pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchColumn();}
    private function count(string $sql,array $params=[]):int{return (int)$this->value($sql,$params);}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function throwsMessage(callable $callback,string $expected,string $message):void{$this->assertions++;try{$callback();}catch(DomainException $e){if($e->getMessage()===$expected)return;throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($e->getMessage(),true));}throw new RuntimeException($message.': expected DomainException');}
}

exit((new ArpaAppointmentBusinessRulesTest())->run());
