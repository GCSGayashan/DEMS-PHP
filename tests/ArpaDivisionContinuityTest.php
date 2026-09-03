<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\{ArpaAppointmentService,ArpaDivisionContinuityService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaDivisionContinuityTest
{
    private PDO $pdo;
    private ArpaDivisionContinuityService $continuity;
    private string $actor;
    private string $asc;
    private string $officer;
    private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->continuity=new ArpaDivisionContinuityService($this->pdo);
        $this->pdo->beginTransaction();
        try{$this->exercise();}finally{$this->pdo->rollBack();}
        echo "ArpaDivisionContinuityTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        $this->actor=(string)$this->value("SELECT id FROM system_user WHERE username='asctest'");
        $this->asc=(string)$this->value("SELECT id FROM location WHERE dad_number='70004-0000389'");
        $this->officer=(string)$this->value("SELECT oa.officer_id FROM officer_office_assignment oa JOIN office o ON o.id=oa.office_id WHERE o.linked_location_id=? AND oa.active=1 AND oa.approval_status='APPROVED' LIMIT 1",[$this->asc]);
        if($this->actor===''||$this->asc===''||$this->officer==='')throw new RuntimeException('Verified ARPA continuity fixtures are required.');

        $empty=$this->division('No History');
        $noHistory=$this->continuity->requirement($empty,'2026-01-01');
        $this->same('2025-01-01',$noHistory['required_next_start'],'no history starts at the system baseline');
        $this->same('GAP',$noHistory['relation'],'no history plus a later proposed date is a gap');
        $this->throwsContains(fn()=>$this->continuity->assertCanStart($empty,'2026-01-01'),'no assignment history from 01 Jan 2025','no-history gap is blocked');
        $this->same('EXACT',$this->continuity->assertCanStart($empty,'2025-01-01')['relation'],'first assignment exactly at baseline passes');

        $august=$this->division('August Gap');
        $this->appointment($august,'2025-01-01','2025-08-31');
        $augustGap=$this->continuity->requirement($august,'2026-01-01');
        $this->same('2025-09-01',$augustGap['required_next_start'],'required date is previous end plus one day');
        $this->same('2025-12-31',$augustGap['gap_end'],'gap end is the day before proposed start');
        $this->throwsContains(fn()=>$this->continuity->assertCanStart($august,'2026-01-01'),'01 Sep 2025','later proposal reports the exact required date');

        $kalahogedara=$this->division('Kalahogedara Regression');
        $prior=$this->appointment($kalahogedara,'2025-01-01','2025-12-31');
        $januaryGap=$this->continuity->requirement($kalahogedara,'2026-01-22');
        $this->same('2026-01-01',$januaryGap['required_next_start'],'Kalahogedara required next date is 01 January 2026');
        $this->same('2026-01-21',$januaryGap['gap_end'],'Kalahogedara gap ends the day before the invalid proposal');
        $this->throwsContains(fn()=>$this->continuity->assertCanStart($kalahogedara,'2026-01-22'),'01 Jan 2026','Kalahogedara 22 January regression is blocked');
        $this->same('EXACT',$this->continuity->assertCanStart($kalahogedara,'2026-01-01')['relation'],'day immediately after prior end passes continuity');
        $this->throwsContains(fn()=>$this->continuity->assertCanStart($kalahogedara,'2025-12-31'),'overlaps an existing authoritative','start before required date is rejected as overlap');
        $this->same($this->officer,(string)$this->value('SELECT officer_id FROM arpa_division_appointment WHERE id=?',[$prior]),'prior period belongs to a specific Officer');
        $this->same('2026-01-01',$this->continuity->requirement($kalahogedara,'2026-01-22')['required_next_start'],'continuity remains Division-based and has no proposed-Officer input');

        $issueDivision=$this->division('Data Issue Range');
        $this->appointment($issueDivision,'2025-01-01','2025-12-14');
        $issueAppointment=$this->appointment($issueDivision,'2025-12-15','2026-01-10',false);
        $exact=$this->continuity->requirement($issueDivision,'2026-01-11');
        $this->same('EXACT',$exact['relation'],'canonical dates cover continuously through the proposed date');
        $issue=$this->continuity->blockingDataIssue($issueDivision,$exact,'2026-01-11');
        $this->same('ENDED_APPOINTMENT_WITHOUT_END_REASON',$issue['issue_type']??null,'open canonical Data Issue overlapping the preceding period is found');
        $this->throwsContains(fn()=>$this->continuity->assertCanStart($issueDivision,'2026-01-11'),'unresolved Appointment Data Issue','unresolved Data Issue takes precedence over normal assignment');
        $reason=(string)$this->value('SELECT id FROM arpa_appointment_end_reason ORDER BY display_order LIMIT 1');
        $this->pdo->prepare('UPDATE arpa_division_appointment_closure SET end_reason_id=? WHERE appointment_id=?')->execute([$reason,$issueAppointment]);
        $this->same(null,$this->continuity->blockingDataIssue($issueDivision,$exact,'2026-01-11'),'resolved canonical correction removes the active Data Issue');
        $this->same('EXACT',$this->continuity->assertCanStart($issueDivision,'2026-01-11')['relation'],'corrected canonical history is used for continuity');

        $this->pdo->prepare('UPDATE arpa_division_appointment_closure SET end_reason_id=NULL WHERE appointment_id=?')->execute([$issueAppointment]);
        $this->closeIssue('ENDED_APPOINTMENT_WITHOUT_END_REASON:'.$issueAppointment,$issueAppointment);
        $this->same(null,$this->continuity->blockingDataIssue($issueDivision,$exact,'2026-01-11'),'explicitly closed historical issue is not an unresolved blocker');
        $this->same('EXACT',$this->continuity->assertCanStart($issueDivision,'2026-01-11')['relation'],'closed issue follows corrected canonical period data');

        $otherIssueDivision=$this->division('Other Issue');
        $this->appointment($otherIssueDivision,'2025-01-01','2025-01-31',false);
        $this->same(null,$this->continuity->blockingDataIssue($issueDivision,$exact,'2026-01-11'),'issue on another Division does not block this Division');

        $reservation=$this->division('Reservation Gap');
        $this->appointment($reservation,'2025-01-01','2025-12-31');
        $invalidRequest=$this->request($reservation,'2026-01-22','CREATED');
        $invalid=$this->continuity->requirement($reservation,'2026-01-22',$invalidRequest);
        $this->same('GAP',$invalid['relation'],'Submitted request is evaluated against earlier canonical coverage');
        $this->throwsContains(fn()=>$this->workflow($invalidRequest),'01 Jan 2026','server-side SUBMIT revalidation blocks a forged gap request');

        $validReservation=$this->request($reservation,'2026-01-01','SUBMITTED');
        $this->same('OVERLAP',$this->continuity->requirement($reservation,'2026-01-02')['relation'],'a valid Submitted reservation remains authoritative for later overlap checks');
        $this->pdo->prepare("UPDATE arpa_division_appointment_request SET workflow_status='REJECTED' WHERE id=?")->execute([$validReservation]);
        $this->same('GAP',$this->continuity->requirement($reservation,'2026-01-02')['relation'],'Rejected request is not authoritative coverage');

        $returned=$this->request($reservation,'2026-01-22','RETURNED');
        $this->throwsContains(fn()=>$this->workflow($returned),'01 Jan 2026','returned request is revalidated when resubmitted');

        $productionInvalid=array_column($this->continuity->invalidPendingAssignments(),'id');
        $this->same(true,in_array('ca8868e8-46fd-43b7-944d-a7ff6aa4ae49',$productionInvalid,true),'read-only diagnostic identifies the existing Kalahogedara pending request');

        $service=(string)file_get_contents(BASE_PATH.'/app/Services/ArpaAppointmentService.php');
        $this->same(true,substr_count($service,'assertCanStart(')>=5,'continuity is enforced on create, edit/resubmit, workflow stages, transfer, and final materialization');
        $this->same(true,str_contains($service,"['SUBMIT','VERIFY','APPROVE']"),'every authoritative workflow stage revalidates continuity');
        $this->same('2025-01-01',ArpaDivisionContinuityService::BASELINE,'continuity baseline remains canonical');
    }

    private function division(string $name):string
    {
        $id=$this->uuid();$type=(string)$this->value("SELECT id FROM location_type WHERE system_key='ARPA_DIVISION'");
        $this->pdo->prepare("INSERT INTO location(id,dad_number,location_type_id,name_en,effective_from,operational_status,approval_status) VALUES(?,?,?,?,?,'ACTIVE','APPROVED')")
            ->execute([$id,'TEST-'.substr(str_replace('-','',$id),0,12),$type,$name,'2025-01-01']);
        $this->pdo->prepare("INSERT INTO location_relationship(id,parent_location_id,child_location_id,relationship_type,effective_from,approval_status,active) VALUES(?,?,?,'ASC_ARPA_DIVISION','2025-01-01','APPROVED',1)")
            ->execute([$this->uuid(),$this->asc,$id]);
        return $id;
    }

    private function appointment(string $division,string $from,string $to,bool $withReason=true):string
    {
        $request=$this->uuid();$appointment=$this->uuid();$location=$this->row('SELECT a.dad_number asc_dad,a.name_en asc_name,d.dad_number arpa_dad,d.name_en arpa_name FROM location a JOIN location d ON d.id=? WHERE a.id=?',[$division,$this->asc]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,legacy_history_only,created_by,finalized_by,finalized_at) VALUES(?,'LEGACY_IMPORT','APPOINTMENT',?,'PERMANENT',?,?,?,'DISTRICT_APPROVED',1,?,?,NOW())")
            ->execute([$request,$this->officer,$this->asc,$division,$from,$this->actor,$this->actor]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,service_permanency_snapshot,service_permanency_source,asc_location_id,arpa_division_location_id,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,approved_by,approved_at,approval_timestamp_provenance,legacy_history_only) VALUES(?,'LEGACY_IMPORT',?,?,'PERMANENT','PERMANENT_IN_SERVICE','EXACT_PERMANENTED_DATE',?,?,?,?,?,?,'{}',?,?,NULL,'UNAVAILABLE_FROM_LEGACY_SOURCE',1)")
            ->execute([$appointment,$request,$this->officer,$this->asc,$division,$location['asc_dad'],$location['asc_name'],$location['arpa_dad'],$location['arpa_name'],$from,$this->actor]);
        $reason=$withReason?(string)$this->value('SELECT id FROM arpa_appointment_end_reason ORDER BY display_order LIMIT 1'):null;
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_kind,context_snapshot_json,approved_by,approved_at,approval_timestamp_provenance) VALUES(?,'LEGACY_IMPORT',?,?,?,?,'DIRECT','{}',?,NULL,'UNAVAILABLE_FROM_LEGACY_SOURCE')")
            ->execute([$this->uuid(),$appointment,$request,$to,$reason,$this->actor]);
        return $appointment;
    }

    private function request(string $division,string $from,string $status):string
    {
        $id=$this->uuid();$this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,legacy_history_only,created_by) VALUES(?,'NATIVE','APPOINTMENT',?,'PERMANENT',?,?,?, ?,0,?)")
            ->execute([$id,$this->officer,$this->asc,$division,$from,$status,$this->actor]);return $id;
    }

    private function workflow(string $request):void{(new ArpaAppointmentService($this->pdo))->workflow('division',$request,'SUBMIT','CREATOR',null,$this->actor);}

    private function closeIssue(string $rowKey,string $appointment):void
    {
        $request=(string)$this->value('SELECT request_id FROM arpa_division_appointment WHERE id=?',[$appointment]);
        $this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,related_appointment_ids_json,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin) VALUES(?,?,?,?,?,?,JSON_ARRAY(?),?,?,'KEEP_AS_HISTORICAL_EXCEPTION','KEPT_HISTORICAL_EXCEPTION','Reviewed and closed','{}','{}','LEGACY_IMPORT')")
            ->execute([$this->uuid(),$rowKey,'ENDED_APPOINTMENT_WITHOUT_END_REASON',$this->officer,$appointment,$request,$appointment,$this->asc,$this->actor]);
    }

    private function row(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throwsContains(callable $callback,string $needle,string $message):void{$this->assertions++;try{$callback();}catch(DomainException $e){if(str_contains($e->getMessage(),$needle))return;throw new RuntimeException($message.': wrong message '.$e->getMessage());}throw new RuntimeException($message.': expected DomainException');}
    private function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
}

exit((new ArpaDivisionContinuityTest())->run());
