<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\{ArpaAppointmentDisplayPresentation,ArpaAppointmentService,ArpaAscApprovedOperationalBackfillService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAscApprovedOperationalBackfillTest
{
    private PDO $pdo;
    private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();
        $this->pdo->beginTransaction();
        try{$this->previewIsReadOnly();$this->existingCanonicalIsIdempotent();$this->presentationDistinguishesOperationalAndWorkflow();}
        finally{if($this->pdo->inTransaction())$this->pdo->rollBack();}
        echo "ArpaAscApprovedOperationalBackfillTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function previewIsReadOnly():void
    {
        $before=$this->state();
        $statuses=$this->requestStatuses();
        $report=(new ArpaAscApprovedOperationalBackfillService($this->pdo))->run(false,200);
        $this->same('PREVIEW',$report['mode'],'backfill defaults to an explicit read-only preview path');
        $this->same(1,preg_match('/^[0-9a-f-]{36}$/',$report['run_id']),'every preview/execution receives an auditable run ID');
        $this->same(200,$report['limit'],'preview honours the safe maximum batch size');
        $this->same(true,count($report['details'])<=200,'preview never validates more than the requested batch size');
        $this->same(0,$report['summary']['appointments_materialized'],'preview reports no materialized appointments');
        $this->same(0,$report['summary']['appointment_closures_created'],'preview reports no bounded appointment closures');
        $this->same(0,$report['summary']['end_closures_created'],'preview reports no END closures');
        $this->same($before,$this->state(),'preview rolls back every validation materialization');
        $this->same($statuses,$this->requestStatuses(),'preview preserves every request workflow status');
        foreach($report['details'] as $row){
            $this->same(true,in_array($row['workflow_status'],['ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','NATIONAL_APPROVED'],true),'preview contains only ASC-approved-or-later workflow rows');
            $this->same(true,in_array($row['request_type'],['APPOINTMENT','END'],true),'preview contains only supported division request types');
            $request=$this->row('SELECT record_origin,deleted_at FROM arpa_division_appointment_request WHERE id=?',[(string)$row['request_id']]);
            $this->same('NATIVE',$request['record_origin'],'LEGACY_IMPORT requests are excluded from the backfill');
            $this->same(null,$request['deleted_at'],'administratively deleted requests are excluded from the backfill');
        }
    }

    private function existingCanonicalIsIdempotent():void
    {
        $row=$this->pdo->query("SELECT r.id,r.record_origin,r.workflow_status
                                FROM arpa_division_appointment_request r
                                JOIN arpa_division_appointment a ON a.request_id=r.id
                                WHERE r.request_type='APPOINTMENT' AND r.deleted_at IS NULL
                                ORDER BY r.id LIMIT 1")->fetch();
        if(!$row)return;
        $id=(string)$row['id'];
        $historyBefore=$this->count('SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE request_id=?',[$id]);
        $canonicalBefore=$this->count('SELECT COUNT(*) FROM arpa_division_appointment WHERE request_id=?',[$id]);
        $actor=(string)$this->value('SELECT id FROM system_user ORDER BY id LIMIT 1');
        $this->pdo->prepare("UPDATE arpa_division_appointment_request SET record_origin='NATIVE',workflow_status='DISTRICT_APPROVED',created_by=COALESCE(created_by,?) WHERE id=?")->execute([$actor,$id]);
        $result=(new ArpaAppointmentService($this->pdo))->materializeApprovedNativeDivisionRequest($id);
        $this->same(true,$result['already_materialized'],'existing request linkage is recognized idempotently');
        $this->same($canonicalBefore,$this->count('SELECT COUNT(*) FROM arpa_division_appointment WHERE request_id=?',[$id]),'existing canonical appointment is not duplicated');
        $this->same($historyBefore,$this->count('SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE request_id=?',[$id]),'idempotent materialization does not alter workflow history');
        $this->same('DISTRICT_APPROVED',(string)$this->value('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?',[$id]),'materializer never advances governance status');
    }

    private function presentationDistinguishesOperationalAndWorkflow():void
    {
        $native=ArpaAppointmentDisplayPresentation::decorate(['record_origin'=>'NATIVE','source_kind'=>'OPERATIONAL','effective_from'=>'2025-01-01']);
        $reservation=ArpaAppointmentDisplayPresentation::decorate(['record_origin'=>'NATIVE','source_kind'=>'RESERVATION','effective_from'=>'2025-01-01']);
        $this->same('Native Appointment',$native['display_origin'],'canonical native row is labelled as an appointment');
        $this->same('Native Workflow / Reservation',$reservation['display_origin'],'unmaterialized native request is labelled as workflow/reservation');
    }

    private function state():array
    {
        return [
            'appointments'=>$this->count('SELECT COUNT(*) FROM arpa_division_appointment'),
            'closures'=>$this->count('SELECT COUNT(*) FROM arpa_division_appointment_closure'),
            'workflow'=>$this->count('SELECT COUNT(*) FROM arpa_appointment_workflow_action'),
            'audit'=>$this->count("SELECT COUNT(*) FROM audit_event WHERE action_key IN('arpa.appointment.asc-approved-backfill','arpa.appointment-end.asc-approved-backfill')"),
        ];
    }

    private function requestStatuses():array
    {
        return $this->pdo->query("SELECT id,workflow_status FROM arpa_division_appointment_request ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private function count(string $sql,array $params=[]):int{return (int)$this->value($sql,$params);}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function row(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
}

exit((new ArpaAscApprovedOperationalBackfillTest())->run());
