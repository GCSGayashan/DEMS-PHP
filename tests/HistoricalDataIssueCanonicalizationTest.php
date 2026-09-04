<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database};
use App\Services\{ArpaAppointmentDataIssueCorrectionService,ArpaAppointmentFormOptionsService,ArpaAppointmentService,ArpaDivisionContinuityService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class HistoricalDataIssueCanonicalizationTest
{
    private PDO $pdo;private int $assertions=0;private string $actor;private string $asc;private string $officer;private string $division;
    private string $openIssue;private string $closedIssue;private string $otherIssue;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->actor=(string)$this->scalar("SELECT id FROM system_user WHERE username='asctest' AND enabled=1");
        $context=$this->row("SELECT uar.id role_assignment_id,uas.id scope_assignment_id,uas.location_id asc_location_id FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id AND r.role_code='ASC_SUBJECT_OFFICER' JOIN user_account_scope uas ON uas.role_assignment_id=uar.id JOIN location l ON l.id=uas.location_id AND l.dad_number='70004-0000389' WHERE uar.user_id=? AND uar.active=1 AND uas.active=1 LIMIT 1",[$this->actor]);
        if(!$context)throw new RuntimeException('Kurunegala ASC regression context is unavailable.');
        $this->asc=(string)$context['asc_location_id'];
        $_SESSION=['user_id'=>$this->actor,'authenticated_at'=>time(),'last_activity_at'=>time()];(new UserContextService($this->pdo))->select($this->actor,$context['role_assignment_id'],$context['scope_assignment_id']);Auth::forgetRequestCache();
        $before=$this->state();$this->pdo->beginTransaction();
        try{$this->fixtures();$this->exercise();}finally{$this->pdo->rollBack();}
        $this->same($before,$this->state(),'rolled-back regression leaves all live appointment and correction data unchanged');
        echo "HistoricalDataIssueCanonicalizationTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        $continuity=new ArpaDivisionContinuityService($this->pdo);$service=new ArpaAppointmentDataIssueCorrectionService($this->pdo);
        $initialIssues=$continuity->unresolvedDataIssues($this->division);$initialKeys=array_column($initialIssues,'row_key');
        $this->same(3,count($initialIssues),'all fixture-specific unresolved Division issues are returned, regardless of proposed-date overlap');
        foreach([$this->openIssue,$this->closedIssue,$this->otherIssue] as $key)$this->same(true,in_array($key,$initialKeys,true),'fixture Data Issue is visible before resolution');
        $this->throwsContains(fn()=>$continuity->assertNoUnresolvedDataIssues($this->division),'unresolved Appointment Data Issues','normal assignment is hard-blocked before issue completion');
        $detail=$service->detail($this->openIssue,$this->actor);$this->same(true,$detail['correctable'],'request-only historical issue is reviewable');$this->same([],array_column($detail['appointments'],'id'),'unresolved raw issue is not canonical coverage');
        $request=$detail['historical_request'];$sourceBefore=(string)$this->scalar('SELECT legacy_payload_json FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=? LIMIT 1',[$request['id']]);
        $options=(new ArpaAppointmentFormOptionsService($this->pdo))->load($this->actor,(string)$request['asc_location_id'],'2026-09-03',['arpa_division_location_id'=>$this->division]);
        $this->same(3,count($options['unresolvedDataIssues']),'New Assignment options expose every unresolved issue for the selected Division');
        $requestCount=(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_request');
        $this->throwsContains(fn()=>(new ArpaAppointmentService($this->pdo))->createDivisionAppointmentRequest(['officer_id'=>$request['officer_id'],'appointment_type'=>'ACTING','asc_location_id'=>$request['asc_location_id'],'arpa_division_location_id'=>$this->division,'effective_from'=>'2026-09-03'],$this->actor),'unresolved Appointment Data Issues','forged server submission is rejected by the final transactional check');
        $this->same($requestCount,(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_request'),'blocked submission creates no request or reservation');
        $workflowBefore=(string)$request['workflow_status'];
        $result=$service->correct($this->openIssue,['correction_action'=>'RESOLVE_CANONICAL_ASSIGNMENT','officer_id'=>$request['officer_id'],'arpa_division_location_id'=>$this->division,'appointment_type'=>'PERMANENT','effective_from'=>'2026-01-01','appointment_status'=>'OPEN','service_permanency_snapshot'=>$request['source_service_permanency_snapshot'],'correction_reason'=>'Confirmed fixture historical appointment','evidence_reference'=>'Regression evidence'],$this->actor);
        $appointment=$result['appointment_id'];$this->same('OPEN',$result['appointment_status'],'open correction creates an open canonical assignment');
        $this->same(1,(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment WHERE id=? AND effective_from=? AND legacy_history_only=0',[$appointment,'2026-01-01']),'one canonical assignment is created');
        $this->same(0,(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_closure WHERE appointment_id=?',[$appointment]),'open canonical assignment stores no sentinel closure date');
        $this->same($workflowBefore,(string)$this->scalar('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$request['id']]),'reliable legacy workflow level is preserved rather than fabricated');
        $this->same($appointment,(string)$this->scalar('SELECT target_appointment_id FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=? LIMIT 1',[$request['id']]),'existing source reference is linked to the canonical appointment');
        $this->same($sourceBefore,(string)$this->scalar('SELECT legacy_payload_json FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=? LIMIT 1',[$request['id']]),'original legacy source payload remains immutable');
        $this->throwsContains(fn()=>$service->correct($this->openIssue,['correction_action'=>'RESOLVE_CANONICAL_ASSIGNMENT','correction_reason'=>'Duplicate attempt'],$this->actor),'already been resolved','duplicate resolution is explicitly blocked');
        $remainingAfterResolution=$continuity->unresolvedDataIssues($this->division);
        $this->same(false,in_array($this->openIssue,array_column($remainingAfterResolution,'row_key'),true),'resolved source issue leaves the unresolved queue');
        $this->same(true,in_array($this->otherIssue,array_column($remainingAfterResolution,'row_key'),true),'resolving one issue does not hide another unresolved issue');
        $this->same(true,in_array($this->closedIssue,array_column($remainingAfterResolution,'row_key'),true),'closed historical issue remains unresolved until explicitly completed');
        $this->throwsContains(fn()=>$continuity->assertNoUnresolvedDataIssues($this->division),'unresolved Appointment Data Issues','one remaining issue keeps the Division blocked');

        $endReason=(string)$this->scalar('SELECT id FROM arpa_appointment_end_reason WHERE active=1 ORDER BY display_order,id LIMIT 1');
        $closed=$service->correctCanonicalEndDate($appointment,['appointment_status'=>'CLOSED','effective_to'=>'2026-08-31','end_reason_id'=>$endReason,'correction_reason'=>'Confirmed historical closing date'],$this->actor);
        $this->same('CLOSED',$closed['appointment_status'],'Open to Closed transition succeeds on the same assignment');
        $this->same('2026-08-31',(string)$this->scalar('SELECT effective_to FROM arpa_division_appointment_closure WHERE appointment_id=?',[$appointment]),'closed assignment has the corrected date');
        $history=DataTableRegistry::definition('arpa-historical-appointments');$history['baseWhere'][]='a.id=?';$history['baseParams'][]=$appointment;
        $this->same(1,(new DataTableQuery($this->pdo,$history,new DataTableRequest(['length'=>10])))->response()['recordsFiltered'],'closed correction appears in Assignment History');
        $reopened=$service->correctCanonicalEndDate($appointment,['appointment_status'=>'OPEN','correction_reason'=>'Evidence confirms appointment remains open'],$this->actor);
        $this->same('OPEN',$reopened['appointment_status'],'Closed to Open transition succeeds without a duplicate appointment');
        $this->same(0,(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_closure WHERE appointment_id=?',[$appointment]),'reopened assignment returns to NULL/open query state');
        $open=DataTableRegistry::definition('arpa-open-appointments');$open['baseWhere'][]='a.id=?';$open['baseParams'][]=$appointment;
        $this->same(1,(new DataTableQuery($this->pdo,$open,new DataTableRequest(['length'=>10])))->response()['recordsFiltered'],'reopened correction appears in Current Assignments');
        $this->same(1,(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment WHERE request_id=?',[$request['id']]),'status changes retain the same canonical record');
        $this->same($workflowBefore,(string)$this->scalar('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$request['id']]),'Open and Closed state changes remain independent of preserved approval level');

        $service->correctCanonicalEndDate($appointment,['appointment_status'=>'CLOSED','effective_to'=>'2026-08-31','end_reason_id'=>$endReason,'correction_reason'=>'Prepare following-period validation'],$this->actor);
        $this->laterAssignment($appointment,'2026-09-01','2026-12-31');
        $this->throwsContains(fn()=>$service->correctCanonicalEndDate($appointment,['appointment_status'=>'CLOSED','effective_to'=>'2026-08-15','correction_reason'=>'Invalid shortening'], $this->actor),'uncovered period','shortening is blocked when it creates a gap before the following assignment');
        $this->throwsContains(fn()=>$service->correctCanonicalEndDate($appointment,['appointment_status'=>'CLOSED','effective_to'=>'2026-09-15','correction_reason'=>'Invalid extension'], $this->actor),'overlaps','extending is blocked when it overlaps the following assignment');
        $this->throwsContains(fn()=>$service->correctCanonicalEndDate($appointment,['appointment_status'=>'OPEN','correction_reason'=>'Invalid reopen'], $this->actor),'cannot be reopened','Closed to Open is blocked when a later assignment exists');

        $closedRequest=$service->detail($this->closedIssue,$this->actor)['historical_request'];
        $closedResult=$service->correct($this->closedIssue,['correction_action'=>'RESOLVE_CANONICAL_ASSIGNMENT','officer_id'=>$this->officer,'arpa_division_location_id'=>$this->division,'appointment_type'=>'DUTY_COVERING','effective_from'=>'2025-01-01','appointment_status'=>'CLOSED','effective_to'=>'2025-12-31','end_reason_id'=>$endReason,'service_permanency_snapshot'=>'PERMANENT_IN_SERVICE','correction_reason'=>'Confirmed closed fixture history'],$this->actor);
        $this->same('CLOSED',$closedResult['appointment_status'],'closed historical issue becomes one ended canonical assignment');
        $this->same((string)$closedRequest['workflow_status'],(string)$this->scalar('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$closedRequest['id']]),'closed canonicalization preserves its trustworthy approval level');
        $this->same(1,(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_closure WHERE appointment_id=? AND effective_to=?',[$closedResult['appointment_id'],'2025-12-31']),'closed source appears in Assignment History with its verified end date');

        $other=$service->correct($this->otherIssue,['correction_action'=>'KEEP_AS_HISTORICAL_EXCEPTION','correction_reason'=>'Source record is explicitly not applicable as canonical coverage'],$this->actor);
        $this->same('KEPT_HISTORICAL_EXCEPTION',$other['resolution_status'],'existing terminal historical/not-applicable status is reused');
        $remainingAfterTerminal=$continuity->unresolvedDataIssues($this->division);
        $this->same(false,in_array($this->otherIssue,array_column($remainingAfterTerminal,'row_key'),true),'terminal historical/not-applicable issue no longer blocks');
        $this->same([],array_column($continuity->unresolvedDataIssues($this->division),'row_key'),'all completed fixture issues leave the Division unblocked');
        $continuity->assertNoUnresolvedDataIssues($this->division);$this->assertions++;
        $this->same(4,(int)$this->scalar("SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE appointment_id=? AND resolution_status='RESOLVED_BY_CORRECTION'",[$appointment]),'canonical creation and every successful status transition are append-only correction events');
        $this->same(4,(int)$this->scalar("SELECT COUNT(*) FROM audit_event WHERE target_type='ARPA_APPOINTMENT_DATA_ISSUE' AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.appointment_id'))=?",[$appointment]),'canonical lifecycle transitions retain before/after audit events');
        $form=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/division_form.php');$javascript=(string)file_get_contents(BASE_PATH.'/public/assets/js/arpa-division-form.js');
        $this->same(true,str_contains($form,'Appointment Data Issue Must Be Resolved First'),'New Assignment renders a prominent Data Issue panel');
        $this->same(true,str_contains($javascript,'unresolved_data_issues')&&str_contains($javascript,'submit.disabled'),'client UI renders issue summaries and disables Submit while server remains authoritative');
    }

    private function fixtures():void
    {
        $this->division=$this->uuid();$locationType=(string)$this->scalar("SELECT id FROM location_type WHERE system_key='ARPA_DIVISION'");
        $this->pdo->prepare("INSERT INTO location(id,dad_number,location_type_id,name_en,effective_from,operational_status,approval_status) VALUES(?,?,?,?,?,'ACTIVE','APPROVED')")
            ->execute([$this->division,'TEST-'.substr(str_replace('-','',$this->division),0,12),$locationType,'Canonical Data Issue Test Division','2025-01-01']);
        $this->pdo->prepare("INSERT INTO location_relationship(id,parent_location_id,child_location_id,relationship_type,effective_from,approval_status,active) VALUES(?,?,?,'ASC_ARPA_DIVISION','2025-01-01','APPROVED',1)")
            ->execute([$this->uuid(),$this->asc,$this->division]);
        $this->officer=$this->uuid();$designation=(string)$this->scalar("SELECT id FROM designation WHERE system_key='ARPA_OFFICER'");$status=(string)$this->scalar('SELECT id FROM officer_status WHERE active=1 ORDER BY display_order,id LIMIT 1');
        $this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,arpa_service_permanency,primary_designation_id,officer_status_id,effective_from,operational_status,approval_status,created_by,approved_by,approved_at) VALUES(?,?,?,'PERMANENT_IN_SERVICE',?,?,'2025-01-01','ACTIVE','APPROVED',?,?,NOW())")
            ->execute([$this->officer,'TEST-'.substr(str_replace('-','',$this->officer),0,12),'Canonical Fixture Officer',$designation,$status,$this->actor,$this->actor]);
        $office=(string)$this->scalar("SELECT o.id FROM office o JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' WHERE o.linked_location_id=? AND o.operational_status='ACTIVE' AND o.approval_status='APPROVED' LIMIT 1",[$this->asc]);
        $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,approval_status,active,reason,created_by,submitted_by,submitted_at,approved_by,approved_at) VALUES(UUID(),?,?,?,'APPROVED',1,'Canonicalization fixture',?,?,NOW(),?,NOW())")
            ->execute([$this->officer,$office,'2025-01-01',$this->actor,$this->actor,$this->actor]);
        $open=$this->historicalExceptionRequest('2026-01-01',null,'DISTRICT_APPROVED');
        $closed=$this->historicalExceptionRequest('2025-01-01','2025-12-31','ASC_APPROVED');
        $other=$this->historicalExceptionRequest('2024-01-01','2024-12-31','ASC_APPROVED');
        $this->openIssue='LEGACY_HISTORICAL_EXCEPTION:'.$open;$this->closedIssue='LEGACY_HISTORICAL_EXCEPTION:'.$closed;$this->otherIssue='LEGACY_HISTORICAL_EXCEPTION:'.$other;
    }

    private function historicalExceptionRequest(string $from,?string $to,string $workflowStatus):string
    {
        $request=$this->uuid();$business=$this->uuid();$key='TEST-CANONICAL-'.str_replace('-','',$request);$snapshot=$this->json(['service_permanency_snapshot'=>'PERMANENT_IN_SERVICE','service_permanency_source'=>'EXACT_PERMANENTED_DATE']);
        $this->pdo->prepare("INSERT INTO legacy_arpa_appointment_business_record(id,reconciled_business_key,reconciliation_class,target_concept,officer_id,source_snapshot_json) VALUES(? ,?,'OLD_HISTORY_ONLY','ARPA_DIVISION_APPOINTMENT',?,?)")
            ->execute([$business,$key,$this->officer,$snapshot]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,requested_effective_to,workflow_status,legacy_history_only,legacy_exception,legacy_exception_codes_json,location_snapshot_json,origin_metadata_json) VALUES(?,'LEGACY_IMPORT','APPOINTMENT',?,'PERMANENT',?,?,?,?,?,1,1,'[\"TEST_LEGACY_EXCEPTION\"]','{}','{}')")
            ->execute([$request,$this->officer,$this->asc,$this->division,$from,$to,$workflowStatus]);
        $this->pdo->prepare("INSERT INTO legacy_arpa_appointment_source_reference(business_record_id,source_table,legacy_appointment_id,target_appointment_request_id,legacy_payload_json) VALUES(?,'test_historical_issue',?,?,?)")
            ->execute([$business,$key,$request,$this->json(['from'=>$from,'to'=>$to,'workflow_status'=>$workflowStatus])]);
        return $request;
    }

    private function laterAssignment(string $sourceAppointment,string $from,string $to):void
    {
        $request=$this->uuid();$appointment=$this->uuid();$closure=$this->uuid();
        $source=$this->row('SELECT * FROM arpa_division_appointment WHERE id=?',[$sourceAppointment]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,requested_effective_to,workflow_status,legacy_history_only,legacy_exception,legacy_exception_codes_json,origin_metadata_json) VALUES(?,'LEGACY_IMPORT','APPOINTMENT',?,?,?,?,? ,?,'DISTRICT_APPROVED',0,1,'[\"TEST_FOLLOWING_PERIOD\"]','{}')")->execute([$request,$source['officer_id'],'PERMANENT',$source['asc_location_id'],$source['arpa_division_location_id'],$from,$to]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,service_permanency_snapshot,service_permanency_source,province_location_id_snapshot,district_location_id_snapshot,asc_location_id,arpa_division_location_id,province_dad_snapshot,province_name_snapshot,district_dad_snapshot,district_name_snapshot,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,legacy_history_only,legacy_exception,legacy_exception_codes_json,approved_by,approved_at,approval_timestamp_provenance,origin_metadata_json) VALUES(?,'LEGACY_IMPORT',?,?,'PERMANENT',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,1,'[\"TEST_FOLLOWING_PERIOD\"]',NULL,NULL,'UNAVAILABLE_FROM_LEGACY_SOURCE','{}')")
            ->execute([$appointment,$request,$source['officer_id'],$source['service_permanency_snapshot'],$source['service_permanency_source'],$source['province_location_id_snapshot'],$source['district_location_id_snapshot'],$source['asc_location_id'],$source['arpa_division_location_id'],$source['province_dad_snapshot'],$source['province_name_snapshot'],$source['district_dad_snapshot'],$source['district_name_snapshot'],$source['asc_dad_snapshot'],$source['asc_name_snapshot'],$source['arpa_dad_snapshot'],$source['arpa_name_snapshot'],$source['hierarchy_snapshot_json'],$from]);
        $endReason=(string)$this->scalar('SELECT id FROM arpa_appointment_end_reason WHERE active=1 ORDER BY display_order,id LIMIT 1');
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_kind,closure_source,remarks,context_snapshot_json,approved_by,approved_at,approval_timestamp_provenance,origin_metadata_json) VALUES(?,'LEGACY_IMPORT',?,?,?,?,'DIRECT','DATA_ISSUE_CORRECTION','Test following period',?,NULL,NULL,'UNAVAILABLE_FROM_LEGACY_SOURCE','{}')")->execute([$closure,$appointment,$request,$to,$endReason,$source['hierarchy_snapshot_json']]);
    }

    private function state():array{return ['locations'=>(int)$this->scalar('SELECT COUNT(*) FROM location'),'relationships'=>(int)$this->scalar('SELECT COUNT(*) FROM location_relationship'),'officers'=>(int)$this->scalar('SELECT COUNT(*) FROM officer'),'office_assignments'=>(int)$this->scalar('SELECT COUNT(*) FROM officer_office_assignment'),'business_records'=>(int)$this->scalar('SELECT COUNT(*) FROM legacy_arpa_appointment_business_record'),'source_references'=>(int)$this->scalar('SELECT COUNT(*) FROM legacy_arpa_appointment_source_reference'),'requests'=>(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_request'),'appointments'=>(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment'),'closures'=>(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_closure'),'workflow'=>(int)$this->scalar('SELECT COUNT(*) FROM arpa_appointment_workflow_action'),'corrections'=>(int)$this->scalar('SELECT COUNT(*) FROM arpa_appointment_data_correction'),'audit'=>(int)$this->scalar('SELECT COUNT(*) FROM audit_event')];}
    private function scalar(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function row(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
    private function json(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function throwsContains(callable $work,string $needle,string $message):void{$this->assertions++;try{$work();}catch(DomainException $e){if(str_contains($e->getMessage(),$needle))return;throw new RuntimeException($message.': '.$e->getMessage());}throw new RuntimeException($message.': expected DomainException');}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
}

exit((new HistoricalDataIssueCanonicalizationTest())->run());
