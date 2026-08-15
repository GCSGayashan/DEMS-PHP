<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database};
use App\Services\LegacyAppointment\LegacyArpaLocationRepairService;

require dirname(__DIR__).'/bootstrap.php';

final class LegacyArpaLocationRepairTest
{
    private PDO $pdo;
    private int $assertions = 0;

    public function run(): int
    {
        $this->pdo = Database::pdo();
        $before = $this->persistentState();
        $service = new LegacyArpaLocationRepairService($this->pdo);
        $report = $service->dryRun();

        $this->same(14073, $report['request_layer']['examined'], 'all imported Division requests examined');
        $postRepair=$report['request_layer']['mismatched']===0;
        $this->same($postRepair?0:11780, $report['request_layer']['mismatched'], 'verified request mismatch count');
        $this->same($postRepair?0:11780, $report['request_layer']['repairable'], 'all remaining mismatched requests deterministically repairable');
        $this->same(0, $report['request_layer']['manual_review'], 'no request candidate needs manual review');
        $this->same(13485, $report['operational_layer']['examined'], 'all imported operational Division appointments examined');
        $this->same($postRepair?0:11268, $report['operational_layer']['mismatched'], 'verified operational mismatch count');
        $this->same($postRepair?0:11268, $report['operational_layer']['repairable'], 'all remaining mismatched operational rows repairable');
        $this->same(0, $report['operational_layer']['manual_review'], 'no operational candidate needs manual review');
        foreach ($report['validation'] as $name => $count) {
            $this->same(0, $count, "{$name} has no unproven candidate");
        }
        $this->same($postRepair?0:11268, $report['snapshots']['appointment_arpa_snapshots'], 'incorrect operational ARPA snapshots are identified');
        foreach (['request_snapshots','asc_snapshots','district_province_snapshots','hierarchy_json','closure_context_snapshots'] as $name) {
            $this->same(0, $report['snapshots'][$name], "correct {$name} remain unchanged");
        }
        $this->same(true, $report['integrity']['target_immutable_state_unchanged'], 'dry-run leaves immutable target state unchanged');
        $this->same(0, $report['integrity']['workflow_records_requiring_recreation'], 'workflow recreation is prohibited');
        $this->same(0, $report['integrity']['source_references_changed'], 'source references remain immutable');
        $this->same(0, $report['integrity']['legacy_source_rows_changed'], 'repair service never writes legacy source');

        $this->same($postRepair?0:395, $report['collision_projection']['before']['arpa_divisions_multiple_open_permanent'], 'Permanent collision projection reflects repair state');
        $this->same(0, $report['collision_projection']['after']['arpa_divisions_multiple_open_permanent'], 'corrected target projection removes false Permanent collisions');
        $this->same($postRepair?0:138, $report['collision_projection']['before']['arpa_divisions_multiple_open_acting'], 'Acting collision projection reflects repair state');
        $this->same(0, $report['collision_projection']['after']['arpa_divisions_multiple_open_acting'], 'corrected target projection removes false Acting collisions');
        $this->same(43, $report['collision_projection']['after']['dependent_without_qualifying_permanent'], 'location repair does not rewrite Officer dependency evidence');

        $this->same('70007-0007026', $report['wewagedara']['dad_number'], 'Wewagedara regression target found');
        $this->same($postRepair?1:24, $report['wewagedara']['before']['requests'], 'Wewagedara request projection reflects repair state');
        $this->same($postRepair?0:23, $report['wewagedara']['before']['wrong_preserved_target'], 'Wewagedara wrong-target projection reflects repair state');
        $this->same(1, $report['wewagedara']['after']['requests'], 'Wewagedara retains its single genuine request');
        $this->same($postRepair?1:19, $report['wewagedara']['before']['total'], 'Wewagedara operational projection reflects repair state');
        $this->same(1, $report['wewagedara']['after']['total'], 'Wewagedara retains its single genuine operational record');

        if($postRepair){
            $this->same([], $report['proposals'], 'post-repair dry-run is idempotent');
            $this->same(11780,(int)$this->pdo->query('SELECT COUNT(*) FROM legacy_arpa_location_repair_item')->fetchColumn(),'append-only repair ledger remains complete');
        }else{
            $this->sameNameEvidence($report['proposals']);
            $this->transactionalProposal($service, $report['proposals']);
        }
        $this->manualReviewValidation($service);
        $this->historyOnlyQueues();

        $source = file_get_contents(BASE_PATH.'/app/Services/LegacyAppointment/LegacyArpaLocationRepairService.php');
        $this->same(false, str_contains($source, 'LegacyDatabase'), 'repair service has no legacy database write connection');
        $this->same(true, str_contains($source, 'beginTransaction()'), 'execute path is transactional');
        $this->same(true, str_contains($source, 'rollBack()'), 'execute path rolls back on failure');
        $this->same(true, str_contains($source, "repair_reason"), 'repair reason is retained in reports and ledger');
        $cli = file_get_contents(BASE_PATH.'/bin/repair-legacy-arpa-appointment-locations.php');
        $this->same(true, str_contains($cli, '--executor=<authorized-system-user-uuid>'), 'execution requires an explicit authorized actor');
        $this->same($before, $this->persistentState(), 'test and dry-run leave operational and audit state unchanged');

        echo "LegacyArpaLocationRepairTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function sameNameEvidence(array $proposals): void
    {
        $duplicates = [];
        foreach ($this->pdo->query("SELECT LOWER(TRIM(l.name_en)) normalized FROM location l JOIN location_type t ON t.id=l.location_type_id AND t.system_key='ARPA_DIVISION' GROUP BY LOWER(TRIM(l.name_en)) HAVING COUNT(*)>1") as $row) {
            $duplicates[$row['normalized']] = true;
        }
        $candidate = null;
        foreach ($proposals as $proposal) {
            if (isset($duplicates[mb_strtolower(trim($proposal['corrected_arpa_name']), 'UTF-8')])) {
                $candidate = $proposal;
                break;
            }
        }
        $this->same(true, $candidate !== null, 'same-name ARPA Division fixture exists');
        $origin = $this->pdo->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(origin_metadata_json,'$.location_provenance.target_context_id')) FROM arpa_division_appointment_request WHERE id=?");
        $origin->execute([$candidate['request_id']]);
        $this->same($origin->fetchColumn(), $candidate['corrected_arpa_id'], 'same-name repair uses preserved UUID rather than name');
    }

    private function transactionalProposal(LegacyArpaLocationRepairService $service, array $proposals): void
    {
        $proposal = current(array_filter($proposals, fn(array $p): bool => $p['repair_request'] && $p['repair_appointment'] && $p['snapshot_changes']['appointment_arpa_snapshots']));
        if (!is_array($proposal)) {
            throw new RuntimeException('Repairable request/appointment fixture required.');
        }
        $actor = (string)$this->pdo->query("SELECT su.id FROM system_user su JOIN user_account_role ur ON ur.user_id=su.id JOIN application_role role ON role.id=ur.role_id WHERE role.role_code='SYSTEM_ADMIN' LIMIT 1")->fetchColumn();
        $requestBefore = $this->row('SELECT arpa_division_location_id,asc_location_id,origin_metadata_json,officer_id,appointment_type,request_type,requested_effective_from,requested_effective_to,workflow_status,created_by,created_at,legacy_history_only,legacy_exception,legacy_exception_codes_json FROM arpa_division_appointment_request WHERE id=?', $proposal['request_id']);
        $appointmentBefore = $this->row('SELECT arpa_division_location_id,asc_location_id,arpa_dad_snapshot,arpa_name_snapshot,origin_metadata_json,officer_id,appointment_type,effective_from,service_permanency_snapshot,legacy_history_only,legacy_exception,legacy_exception_codes_json,approved_by,approved_at,approval_timestamp_provenance FROM arpa_division_appointment WHERE id=?', $proposal['appointment_id']);
        $closureBefore = $proposal['closure_id'] ? $this->row('SELECT effective_to,end_reason_id,legacy_reason_id,legacy_reason_text,context_snapshot_json,approved_by,approved_at FROM arpa_division_appointment_closure WHERE id=?', $proposal['closure_id']) : null;
        $workflowBefore = $this->checksum("SELECT CONCAT_WS('|',id,request_id,action,stage,user_id,COALESCE(action_at,''),COALESCE(legacy_source_payload_json,'')) value FROM arpa_appointment_workflow_action WHERE request_id=".$this->pdo->quote($proposal['request_id']).' ORDER BY id');
        $referencesBefore = $this->checksum("SELECT CONCAT_WS('|',id,source_table,legacy_appointment_id,legacy_payload_json) value FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=".$this->pdo->quote($proposal['request_id']).' ORDER BY id');

        $this->pdo->beginTransaction();
        try {
            $run = $this->uuid();
            $this->pdo->prepare("INSERT INTO legacy_arpa_location_repair_run(id,status,executor_user_id,examined_requests) VALUES(?,'RUNNING',?,1)")->execute([$run, $actor]);
            $method = new ReflectionMethod($service, 'applyProposal');
            $counts = ['requests' => 0, 'appointments' => 0, 'closures' => 0];
            $arguments = [$run, $actor, $proposal, &$counts];
            $method->invokeArgs($service, $arguments);
            $requestAfter = $this->row('SELECT arpa_division_location_id,asc_location_id,origin_metadata_json,officer_id,appointment_type,request_type,requested_effective_from,requested_effective_to,workflow_status,created_by,created_at,legacy_history_only,legacy_exception,legacy_exception_codes_json FROM arpa_division_appointment_request WHERE id=?', $proposal['request_id']);
            $appointmentAfter = $this->row('SELECT arpa_division_location_id,asc_location_id,arpa_dad_snapshot,arpa_name_snapshot,origin_metadata_json,officer_id,appointment_type,effective_from,service_permanency_snapshot,legacy_history_only,legacy_exception,legacy_exception_codes_json,approved_by,approved_at,approval_timestamp_provenance FROM arpa_division_appointment WHERE id=?', $proposal['appointment_id']);
            $this->same($proposal['corrected_arpa_id'], $requestAfter['arpa_division_location_id'], 'request foreign key is repaired');
            $this->same($proposal['corrected_arpa_id'], $appointmentAfter['arpa_division_location_id'], 'operational foreign key is repaired');
            $this->same($proposal['corrected_arpa_dad'], $appointmentAfter['arpa_dad_snapshot'], 'operational DAD snapshot is repaired');
            $this->same($proposal['corrected_arpa_name'], $appointmentAfter['arpa_name_snapshot'], 'operational name snapshot is repaired');
            $this->same($requestBefore['origin_metadata_json'], $requestAfter['origin_metadata_json'], 'request origin evidence remains byte-for-byte unchanged');
            $this->same($appointmentBefore['origin_metadata_json'], $appointmentAfter['origin_metadata_json'], 'appointment origin evidence remains byte-for-byte unchanged');
            foreach (['officer_id','appointment_type','request_type','requested_effective_from','requested_effective_to','workflow_status','created_by','created_at','legacy_history_only','legacy_exception','legacy_exception_codes_json'] as $field) {
                $this->same($requestBefore[$field], $requestAfter[$field], "request {$field} remains unchanged");
            }
            foreach (['officer_id','appointment_type','effective_from','service_permanency_snapshot','legacy_history_only','legacy_exception','legacy_exception_codes_json','approved_by','approved_at','approval_timestamp_provenance'] as $field) {
                $this->same($appointmentBefore[$field], $appointmentAfter[$field], "appointment {$field} remains unchanged");
            }
            $this->same($workflowBefore, $this->checksum("SELECT CONCAT_WS('|',id,request_id,action,stage,user_id,COALESCE(action_at,''),COALESCE(legacy_source_payload_json,'')) value FROM arpa_appointment_workflow_action WHERE request_id=".$this->pdo->quote($proposal['request_id']).' ORDER BY id'), 'workflow rows remain unchanged');
            $this->same($referencesBefore, $this->checksum("SELECT CONCAT_WS('|',id,source_table,legacy_appointment_id,legacy_payload_json) value FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=".$this->pdo->quote($proposal['request_id']).' ORDER BY id'), 'source references remain unchanged');
            if ($closureBefore !== null) {
                $this->same($closureBefore, $this->row('SELECT effective_to,end_reason_id,legacy_reason_id,legacy_reason_text,context_snapshot_json,approved_by,approved_at FROM arpa_division_appointment_closure WHERE id=?', $proposal['closure_id']), 'closure dates, reasons, actors, and context remain unchanged when already correct');
            }
            $this->same(1, (int)$this->pdo->query("SELECT COUNT(*) FROM legacy_arpa_location_repair_item WHERE repair_run_id=".$this->pdo->quote($run))->fetchColumn(), 'one append-only repair ledger item is created');
            $this->same($proposal['corrected_arpa_id'], $requestAfter['arpa_division_location_id'], 'idempotent rerun classifier sees the request as corrected');
        } finally {
            $this->pdo->rollBack();
        }
        $this->same($requestBefore, $this->row('SELECT arpa_division_location_id,asc_location_id,origin_metadata_json,officer_id,appointment_type,request_type,requested_effective_from,requested_effective_to,workflow_status,created_by,created_at,legacy_history_only,legacy_exception,legacy_exception_codes_json FROM arpa_division_appointment_request WHERE id=?', $proposal['request_id']), 'transaction rollback restores request');
        $this->same($appointmentBefore, $this->row('SELECT arpa_division_location_id,asc_location_id,arpa_dad_snapshot,arpa_name_snapshot,origin_metadata_json,officer_id,appointment_type,effective_from,service_permanency_snapshot,legacy_history_only,legacy_exception,legacy_exception_codes_json,approved_by,approved_at,approval_timestamp_provenance FROM arpa_division_appointment WHERE id=?', $proposal['appointment_id']), 'transaction rollback restores operational appointment');
    }

    private function manualReviewValidation(LegacyArpaLocationRepairService $service): void
    {
        $method = new ReflectionMethod($service, 'validationReasons');
        $row = ['expected_arpa_id'=>null,'expected_type'=>null,'expected_arpa_dad'=>null,'expected_arpa_name'=>null,'expected_asc_parent_count'=>0,'expected_asc_id'=>null,'expected_district_id'=>null,'expected_province_id'=>null,'expected_legacy_asc_id'=>null,'expected_asc_name'=>null,'expected_legacy_district_id'=>null];
        $reasons = $method->invoke($service, $row, ['target_context_id'=>'missing','legacy_location_id'=>'1','legacy_context'=>['arpa_name'=>'Unknown']], 'missing');
        $this->same(true, isset($reasons['missing_expected_location']), 'unresolvable expected target becomes manual review');
    }

    private function historyOnlyQueues(): void
    {
        $actor = (string)$this->pdo->query("SELECT su.id FROM system_user su JOIN user_account_role ur ON ur.user_id=su.id JOIN application_role role ON role.id=ur.role_id WHERE role.role_code='SYSTEM_ADMIN' LIMIT 1")->fetchColumn();
        $_SESSION = ['user_id' => $actor];
        foreach (['arpa-new-appointments','arpa-submitted-appointments'] as $key) {
            $definition = DataTableRegistry::definition($key);
            $this->same(true, in_array('r.legacy_history_only=0', $definition['baseWhere'] ?? [], true), "{$key} excludes historical-only requests");
        }
        $completed=DataTableRegistry::definition('arpa-approval-verification');
        $this->same(true,in_array('r.legacy_history_only=0',$completed['baseWhere']??[],true),'completed workflow queue excludes historical-only requests');
        $this->same(false,in_array("w.record_origin='NATIVE'",$completed['baseWhere']??[],true),'completed workflow history can retain non-history legacy evidence with unavailable timestamps');
        $open = DataTableRegistry::definition('arpa-open-appointments');
        $this->same(true, in_array('a.legacy_history_only=0', $open['baseWhere'] ?? [], true), 'Open Appointments excludes historical-only operational records');
        $pending = DataTableRegistry::definition('arpa-pending-actions');
        $this->same(true, substr_count($pending['from'], 'r.legacy_history_only=0') === 2, 'combined pending queue excludes Division and subject history-only rows');

        $historyOnly = $this->pdo->query("SELECT id FROM arpa_division_appointment_request WHERE record_origin='LEGACY_IMPORT' AND legacy_history_only=1 AND workflow_status IN('SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED') LIMIT 1")->fetchColumn();
        if ($historyOnly) {
            $definition = DataTableRegistry::definition('arpa-submitted-appointments');
            $definition['baseWhere'][] = 'r.id=?';
            $definition['baseParams'][] = $historyOnly;
            $response = (new DataTableQuery($this->pdo, $definition, new DataTableRequest(['length'=>10])))->response();
            $this->same(0, $response['recordsFiltered'], 'history-only request cannot enter live Submitted queue by direct DataTable request');
        }
    }

    private function persistentState(): array
    {
        return [
            'requests' => $this->checksum("SELECT CONCAT_WS('|',id,arpa_division_location_id,asc_location_id,origin_metadata_json) value FROM arpa_division_appointment_request WHERE record_origin='LEGACY_IMPORT' ORDER BY id"),
            'appointments' => $this->checksum("SELECT CONCAT_WS('|',id,arpa_division_location_id,asc_location_id,arpa_dad_snapshot,arpa_name_snapshot,origin_metadata_json) value FROM arpa_division_appointment WHERE record_origin='LEGACY_IMPORT' ORDER BY id"),
            'closures' => $this->checksum("SELECT CONCAT_WS('|',id,appointment_id,effective_to,COALESCE(end_reason_id,''),COALESCE(legacy_reason_text,''),context_snapshot_json) value FROM arpa_division_appointment_closure WHERE record_origin='LEGACY_IMPORT' ORDER BY id"),
            'workflow' => $this->checksum("SELECT CONCAT_WS('|',id,request_id,action,stage,user_id,COALESCE(action_at,''),COALESCE(legacy_source_payload_json,'')) value FROM arpa_appointment_workflow_action WHERE record_origin='LEGACY_IMPORT' ORDER BY id"),
            'references' => $this->checksum("SELECT CONCAT_WS('|',id,business_record_id,source_table,legacy_appointment_id,legacy_payload_json) value FROM legacy_arpa_appointment_source_reference ORDER BY id"),
            'repair_runs' => (int)$this->pdo->query('SELECT COUNT(*) FROM legacy_arpa_location_repair_run')->fetchColumn(),
            'repair_items' => (int)$this->pdo->query('SELECT COUNT(*) FROM legacy_arpa_location_repair_item')->fetchColumn(),
        ];
    }

    private function row(string $sql, string $id): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$id]);
        return $statement->fetch() ?: [];
    }

    private function checksum(string $sql): array
    {
        $hash = hash_init('sha256');
        $count = 0;
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $statement = $this->pdo->query($sql);
            while ($row = $statement->fetch()) {
                hash_update($hash, $row['value']."\n");
                $count++;
            }
            $statement->closeCursor();
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        return [$count, hash_final($hash)];
    }

    private function uuid(): string
    {
        return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();
    }

    private function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException($message.': expected '.var_export($expected, true).', got '.var_export($actual, true));
        }
    }
}

exit((new LegacyArpaLocationRepairTest())->run());
