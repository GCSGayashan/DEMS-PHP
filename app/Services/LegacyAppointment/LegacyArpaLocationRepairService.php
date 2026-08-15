<?php
declare(strict_types=1);

namespace App\Services\LegacyAppointment;

use App\Services\ArpaAppointmentReadService;
use Closure;
use PDO;
use RuntimeException;
use Throwable;

/** Deterministic repair for the verified legacy ARPA target-mapping defect. */
final class LegacyArpaLocationRepairService
{
    public const REASON = 'VERIFIED_LEGACY_ARPA_TARGET_MAPPING_DEFECT';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?Closure $faultInjector = null
    ) {}

    public function dryRun(): array
    {
        return $this->analyse(true);
    }

    public function execute(string $executorUserId): array
    {
        $this->assertExecutor($executorUserId);
        $analysis = $this->analyse(false);
        if ($analysis['request_layer']['repairable'] === 0 && $analysis['operational_layer']['repairable'] === 0) {
            return ['status' => 'ALREADY_REPAIRED', 'run_id' => null, 'analysis' => $analysis];
        }

        $runId = $this->uuid();
        $this->pdo->prepare(
            "INSERT INTO legacy_arpa_location_repair_run
             (id,status,executor_user_id,examined_requests,manual_review_count,summary_json)
             VALUES(?,'RUNNING',?,?,?,?)"
        )->execute([
            $runId,
            $executorUserId,
            $analysis['request_layer']['examined'],
            $analysis['manual_review'],
            $this->json($analysis['summary']),
        ]);

        $counts = ['requests' => 0, 'appointments' => 0, 'closures' => 0];
        try {
            $this->pdo->beginTransaction();
            foreach ($analysis['proposals'] as $index => $proposal) {
                $this->applyProposal($runId, $executorUserId, $proposal, $counts);
                if ($this->faultInjector !== null) {
                    ($this->faultInjector)($index + 1, $proposal);
                }
            }
            $status = $analysis['manual_review'] > 0 ? 'COMPLETED_WITH_REVIEW_ITEMS' : 'COMPLETED';
            $this->pdo->prepare(
                'UPDATE legacy_arpa_location_repair_run SET status=?,repaired_requests=?,repaired_appointments=?,repaired_closures=?,completed_at=NOW() WHERE id=?'
            )->execute([$status, $counts['requests'], $counts['appointments'], $counts['closures'], $runId]);
            $this->pdo->commit();
            return ['status' => $status, 'run_id' => $runId, 'counts' => $counts, 'analysis' => $analysis];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->prepare(
                "UPDATE legacy_arpa_location_repair_run SET status='FAILED',completed_at=NOW(),error_category=? WHERE id=?"
            )->execute([get_class($error), $runId]);
            throw $error;
        }
    }

    private function analyse(bool $writeReport): array
    {
        $integrityBefore = $this->integrityState();
        $rows = $this->rows();
        $request = $this->layerCounters();
        $operational = $this->layerCounters();
        $validation = array_fill_keys([
            'missing_expected_location', 'wrong_expected_location_type', 'legacy_id_mismatch',
            'legacy_name_mismatch', 'hierarchy_asc_mismatch',
        ], 0);
        $snapshots = array_fill_keys([
            'request_snapshots', 'appointment_arpa_snapshots', 'asc_snapshots',
            'district_province_snapshots', 'hierarchy_json', 'closure_context_snapshots',
        ], 0);
        $manual = [];
        $proposals = [];

        foreach ($rows as $row) {
            $request['examined']++;
            if ($row['appointment_id'] !== null) {
                $operational['examined']++;
            }
            $evidence = $this->evidence($row['request_origin_metadata_json']);
            $expectedId = $evidence['target_context_id'] ?? null;
            $requestMismatch = $expectedId !== null && (
                $row['request_arpa_id'] !== $expectedId || $row['request_asc_id'] !== $row['expected_asc_id']
            );
            $appointmentExpected = $this->evidence($row['appointment_origin_metadata_json'])['target_context_id'] ?? $expectedId;
            $appointmentMismatch = $row['appointment_id'] !== null && $appointmentExpected !== null && (
                $row['appointment_arpa_id'] !== $appointmentExpected || $row['appointment_asc_id'] !== $row['expected_asc_id']
            );

            $request[$requestMismatch ? 'mismatched' : 'correct_already']++;
            if ($row['appointment_id'] !== null) {
                $operational[$appointmentMismatch ? 'mismatched' : 'correct_already']++;
            }
            if (!$requestMismatch && !$appointmentMismatch) {
                continue;
            }

            $reasons = $this->validationReasons($row, $evidence, $appointmentExpected);
            if ($reasons !== []) {
                foreach ($reasons as $reason) {
                    $validation[$reason]++;
                }
                if ($requestMismatch) {
                    $request['manual_review']++;
                }
                if ($appointmentMismatch) {
                    $operational['manual_review']++;
                }
                $manual[$row['request_id']] = [
                    'request_id' => $row['request_id'],
                    'appointment_id' => $row['appointment_id'],
                    'reasons' => array_keys($reasons),
                ];
                continue;
            }

            if ($requestMismatch) {
                $request['repairable']++;
            }
            if ($appointmentMismatch) {
                $operational['repairable']++;
            }
            $proposal = $this->proposal($row, $evidence, $requestMismatch, $appointmentMismatch);
            foreach ($proposal['snapshot_changes'] as $key => $changed) {
                if ($changed) {
                    $snapshots[$key]++;
                }
            }
            $proposals[] = $proposal;
        }

        $beforeProjection = $this->collisionProjection(false);
        $afterProjection = $this->collisionProjection(true, $proposals);
        $wewagedara = $this->wewagedaraProjection($proposals);
        $integrityAfter = $this->integrityState();
        $integrity = [
            'officer_type_date_would_change' => 0,
            'workflow_records_requiring_recreation' => 0,
            'source_references_changed' => 0,
            'legacy_source_rows_changed' => 0,
            'target_immutable_state_unchanged' => $integrityBefore === $integrityAfter,
        ];
        if (!$integrity['target_immutable_state_unchanged']) {
            throw new RuntimeException('Dry-run integrity guard detected an unexpected persistent target change.');
        }

        $summary = [
            'mode' => 'DRY_RUN',
            'repair_reason' => self::REASON,
            'request_layer' => $request,
            'operational_layer' => $operational,
            'validation' => $validation,
            'manual_review' => count($manual),
            'snapshots' => $snapshots,
            'integrity' => $integrity,
            'collision_projection' => ['before' => $beforeProjection, 'after' => $afterProjection],
            'wewagedara' => $wewagedara,
        ];
        $paths = $writeReport ? $this->writeReports($summary, $proposals, array_values($manual)) : [];
        return $summary + [
            'summary' => $summary,
            'proposals' => $proposals,
            'manual_review_records' => array_values($manual),
            'report_paths' => $paths,
        ];
    }

    private function rows(): iterable
    {
        $sql = "SELECT
                    r.id request_id,r.arpa_division_location_id request_arpa_id,r.asc_location_id request_asc_id,
                    r.location_snapshot_json request_location_snapshot_json,r.origin_metadata_json request_origin_metadata_json,
                    a.id appointment_id,a.arpa_division_location_id appointment_arpa_id,a.asc_location_id appointment_asc_id,
                    a.arpa_dad_snapshot,a.arpa_name_snapshot,a.asc_dad_snapshot,a.asc_name_snapshot,
                    a.district_location_id_snapshot,a.district_dad_snapshot,a.district_name_snapshot,
                    a.province_location_id_snapshot,a.province_dad_snapshot,a.province_name_snapshot,
                    a.hierarchy_snapshot_json,a.origin_metadata_json appointment_origin_metadata_json,
                    c.id closure_id,c.context_snapshot_json closure_context_snapshot_json,
                    expected.id expected_arpa_id,expected.dad_number expected_arpa_dad,expected.name_en expected_arpa_name,
                    expected_type.system_key expected_type,
                    asc_rel.parent_location_id expected_asc_id,expected_asc.dad_number expected_asc_dad,expected_asc.name_en expected_asc_name,
                    district.id expected_district_id,district.dad_number expected_district_dad,district.name_en expected_district_name,
                    province.id expected_province_id,province.dad_number expected_province_dad,province.name_en expected_province_name,
                    asc_ref.legacy_id expected_legacy_asc_id,district_ref.legacy_id expected_legacy_district_id,
                    (SELECT COUNT(DISTINCT x.parent_location_id) FROM location_relationship x
                     WHERE x.child_location_id=expected.id AND x.relationship_type='ASC_ARPA_DIVISION'
                       AND x.active=1 AND x.approval_status='APPROVED') expected_asc_parent_count
                FROM arpa_division_appointment_request r
                LEFT JOIN arpa_division_appointment a ON a.request_id=r.id AND a.record_origin='LEGACY_IMPORT'
                LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id AND c.record_origin='LEGACY_IMPORT'
                LEFT JOIN location expected ON expected.id=JSON_UNQUOTE(JSON_EXTRACT(r.origin_metadata_json,'$.location_provenance.target_context_id'))
                LEFT JOIN location_type expected_type ON expected_type.id=expected.location_type_id
                LEFT JOIN location_relationship asc_rel ON asc_rel.child_location_id=expected.id
                    AND asc_rel.relationship_type='ASC_ARPA_DIVISION' AND asc_rel.active=1 AND asc_rel.approval_status='APPROVED'
                LEFT JOIN location expected_asc ON expected_asc.id=asc_rel.parent_location_id
                LEFT JOIN location_relationship district_rel ON district_rel.child_location_id=expected_asc.id
                    AND district_rel.relationship_type='DISTRICT_ASC' AND district_rel.active=1 AND district_rel.approval_status='APPROVED'
                LEFT JOIN location district ON district.id=district_rel.parent_location_id
                LEFT JOIN location_relationship province_rel ON province_rel.child_location_id=district.id
                    AND province_rel.relationship_type='PROVINCE_DISTRICT' AND province_rel.active=1 AND province_rel.approval_status='APPROVED'
                LEFT JOIN location province ON province.id=province_rel.parent_location_id
                LEFT JOIN legacy_location_reference asc_ref ON asc_ref.location_id=expected_asc.id
                    AND asc_ref.source_system='AGRARIANADMIN_HR' AND asc_ref.source_table='tbl_asc'
                LEFT JOIN legacy_location_reference district_ref ON district_ref.location_id=district.id
                    AND district_ref.source_system='AGRARIANADMIN_HR' AND district_ref.source_table='tbl_district'
                WHERE r.record_origin='LEGACY_IMPORT'
                ORDER BY r.id";
        $statement = $this->pdo->query($sql);
        while ($row = $statement->fetch()) {
            yield $row;
        }
    }

    private function validationReasons(array $row, array $evidence, ?string $appointmentExpected): array
    {
        $reasons = [];
        if ($row['expected_arpa_id'] === null) {
            $reasons['missing_expected_location'] = true;
            return $reasons;
        }
        if ($row['expected_type'] !== 'ARPA_DIVISION') {
            $reasons['wrong_expected_location_type'] = true;
        }
        $legacyId = trim((string)($evidence['legacy_location_id'] ?? ''));
        if ($legacyId === '' || $this->dadSuffix((string)$row['expected_arpa_dad']) !== (int)$legacyId) {
            $reasons['legacy_id_mismatch'] = true;
        }
        $legacyName = (string)($evidence['legacy_context']['arpa_name'] ?? '');
        if ($legacyName === '' || $this->normalize($legacyName) !== $this->normalize((string)$row['expected_arpa_name'])) {
            $reasons['legacy_name_mismatch'] = true;
        }
        $legacyAscId = trim((string)($evidence['legacy_context']['asc_id'] ?? ''));
        $legacyAscName = trim((string)($evidence['legacy_context']['asc_name'] ?? ''));
        $legacyDistrictId = trim((string)($evidence['legacy_context']['dis_id'] ?? ''));
        $hierarchyInvalid = (int)$row['expected_asc_parent_count'] !== 1
            || $row['expected_asc_id'] === null || $row['expected_district_id'] === null || $row['expected_province_id'] === null
            || ($legacyAscId !== '' && $legacyAscId !== (string)$row['expected_legacy_asc_id'])
            || ($legacyAscName !== '' && $this->normalize($legacyAscName) !== $this->normalize((string)$row['expected_asc_name']))
            || ($legacyDistrictId !== '' && $legacyDistrictId !== (string)$row['expected_legacy_district_id']);
        if ($hierarchyInvalid || ($appointmentExpected !== null && $appointmentExpected !== $row['expected_arpa_id'])) {
            $reasons['hierarchy_asc_mismatch'] = true;
        }
        return $reasons;
    }

    private function proposal(array $row, array $evidence, bool $repairRequest, bool $repairAppointment): array
    {
        $requestSnapshot = $this->decodeJson($row['request_location_snapshot_json']);
        $appointmentHierarchy = $this->decodeJson($row['hierarchy_snapshot_json']);
        $closureSnapshot = $this->decodeJson($row['closure_context_snapshot_json']);
        $replacements = [
            (string)$row['request_arpa_id'] => (string)$row['expected_arpa_id'],
            (string)$row['appointment_arpa_id'] => (string)$row['expected_arpa_id'],
        ];
        if ($row['request_asc_id'] !== $row['expected_asc_id']) {
            $replacements[(string)$row['request_asc_id']] = (string)$row['expected_asc_id'];
        }
        if ($row['appointment_asc_id'] !== $row['expected_asc_id']) {
            $replacements[(string)$row['appointment_asc_id']] = (string)$row['expected_asc_id'];
        }
        $requestSnapshotCorrected = $this->replaceExactValues($requestSnapshot, $replacements);
        $hierarchyCorrected = $this->replaceExactValues($appointmentHierarchy, $replacements);
        $closureCorrected = $this->replaceExactValues($closureSnapshot, $replacements);
        $snapshotChanges = [
            'request_snapshots' => $repairRequest && $requestSnapshotCorrected !== $requestSnapshot,
            'appointment_arpa_snapshots' => $repairAppointment && (
                $row['arpa_dad_snapshot'] !== $row['expected_arpa_dad'] || $row['arpa_name_snapshot'] !== $row['expected_arpa_name']
            ),
            'asc_snapshots' => $repairAppointment && (
                $row['appointment_asc_id'] !== $row['expected_asc_id'] || $row['asc_dad_snapshot'] !== $row['expected_asc_dad']
                || $row['asc_name_snapshot'] !== $row['expected_asc_name']
            ),
            'district_province_snapshots' => $repairAppointment && (
                $row['district_location_id_snapshot'] !== $row['expected_district_id']
                || $row['district_dad_snapshot'] !== $row['expected_district_dad']
                || $row['district_name_snapshot'] !== $row['expected_district_name']
                || $row['province_location_id_snapshot'] !== $row['expected_province_id']
                || $row['province_dad_snapshot'] !== $row['expected_province_dad']
                || $row['province_name_snapshot'] !== $row['expected_province_name']
            ),
            'hierarchy_json' => $repairAppointment && $hierarchyCorrected !== $appointmentHierarchy,
            'closure_context_snapshots' => $row['closure_id'] !== null && $closureCorrected !== $closureSnapshot,
        ];
        return [
            'request_id' => $row['request_id'],
            'appointment_id' => $row['appointment_id'],
            'closure_id' => $row['closure_id'],
            'repair_request' => $repairRequest,
            'repair_appointment' => $repairAppointment,
            'previous_request_arpa_id' => $row['request_arpa_id'],
            'previous_request_asc_id' => $row['request_asc_id'],
            'previous_appointment_arpa_id' => $row['appointment_arpa_id'],
            'previous_appointment_asc_id' => $row['appointment_asc_id'],
            'corrected_arpa_id' => $row['expected_arpa_id'],
            'corrected_arpa_dad' => $row['expected_arpa_dad'],
            'corrected_arpa_name' => $row['expected_arpa_name'],
            'corrected_asc_id' => $row['expected_asc_id'],
            'corrected_asc_dad' => $row['expected_asc_dad'],
            'corrected_asc_name' => $row['expected_asc_name'],
            'corrected_district_id' => $row['expected_district_id'],
            'corrected_district_dad' => $row['expected_district_dad'],
            'corrected_district_name' => $row['expected_district_name'],
            'corrected_province_id' => $row['expected_province_id'],
            'corrected_province_dad' => $row['expected_province_dad'],
            'corrected_province_name' => $row['expected_province_name'],
            'legacy_arpa_id' => (string)$evidence['legacy_location_id'],
            'legacy_arpa_name' => (string)$evidence['legacy_context']['arpa_name'],
            'request_snapshot_json' => $snapshotChanges['request_snapshots'] ? $requestSnapshotCorrected : null,
            'hierarchy_snapshot_json' => $snapshotChanges['hierarchy_json'] ? $hierarchyCorrected : null,
            'closure_snapshot_json' => $snapshotChanges['closure_context_snapshots'] ? $closureCorrected : null,
            'snapshot_changes' => $snapshotChanges,
        ];
    }

    private function applyProposal(string $runId, string $executor, array $p, array &$counts): void
    {
        if ($p['repair_request']) {
            $sql = 'UPDATE arpa_division_appointment_request SET arpa_division_location_id=?,asc_location_id=?,updated_at=updated_at';
            $params = [$p['corrected_arpa_id'], $p['corrected_asc_id']];
            if ($p['snapshot_changes']['request_snapshots']) {
                $sql .= ',location_snapshot_json=?';
                $params[] = $this->json($p['request_snapshot_json']);
            }
            $sql .= ' WHERE id=? AND record_origin=\'LEGACY_IMPORT\' AND arpa_division_location_id=?';
            array_push($params, $p['request_id'], $p['previous_request_arpa_id']);
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Request changed after dry-run; repair aborted.');
            }
            $counts['requests']++;
        }
        if ($p['repair_appointment']) {
            $sql = 'UPDATE arpa_division_appointment SET arpa_division_location_id=?,asc_location_id=?,arpa_dad_snapshot=?,arpa_name_snapshot=?,asc_dad_snapshot=?,asc_name_snapshot=?,district_location_id_snapshot=?,district_dad_snapshot=?,district_name_snapshot=?,province_location_id_snapshot=?,province_dad_snapshot=?,province_name_snapshot=?';
            $params = [$p['corrected_arpa_id'],$p['corrected_asc_id'],$p['corrected_arpa_dad'],$p['corrected_arpa_name'],$p['corrected_asc_dad'],$p['corrected_asc_name'],$p['corrected_district_id'],$p['corrected_district_dad'],$p['corrected_district_name'],$p['corrected_province_id'],$p['corrected_province_dad'],$p['corrected_province_name']];
            if ($p['snapshot_changes']['hierarchy_json']) {
                $sql .= ',hierarchy_snapshot_json=?';
                $params[] = $this->json($p['hierarchy_snapshot_json']);
            }
            $sql .= ' WHERE id=? AND record_origin=\'LEGACY_IMPORT\' AND arpa_division_location_id=?';
            array_push($params, $p['appointment_id'], $p['previous_appointment_arpa_id']);
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Operational appointment changed after dry-run; repair aborted.');
            }
            $counts['appointments']++;
        }
        if ($p['snapshot_changes']['closure_context_snapshots']) {
            $statement = $this->pdo->prepare("UPDATE arpa_division_appointment_closure SET context_snapshot_json=? WHERE id=? AND record_origin='LEGACY_IMPORT'");
            $statement->execute([$this->json($p['closure_snapshot_json']), $p['closure_id']]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Closure context changed after dry-run; repair aborted.');
            }
            $counts['closures']++;
        }
        $before = ['arpa_location_id' => $p['previous_request_arpa_id'], 'asc_location_id' => $p['previous_request_asc_id']];
        $after = ['arpa_location_id' => $p['corrected_arpa_id'], 'asc_location_id' => $p['corrected_asc_id']];
        $this->pdo->prepare(
            'INSERT INTO legacy_arpa_location_repair_item
             (repair_run_id,request_id,appointment_id,closure_id,previous_arpa_location_id,corrected_arpa_location_id,
              previous_asc_location_id,corrected_asc_location_id,legacy_arpa_id,legacy_arpa_name,repair_reason,
              before_target_json,corrected_target_json,repaired_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$runId,$p['request_id'],$p['appointment_id'],$p['closure_id'],$p['previous_request_arpa_id'],$p['corrected_arpa_id'],$p['previous_request_asc_id'],$p['corrected_asc_id'],$p['legacy_arpa_id'],$p['legacy_arpa_name'],self::REASON,$this->json($before),$this->json($after),$executor]);
    }

    private function collisionProjection(bool $after, array $proposals = []): array
    {
        $mapTable = null;
        if ($after) {
            $mapTable = $this->temporaryProjectionMap($proposals);
        }
        $join = $after ? " LEFT JOIN {$mapTable} repair ON repair.appointment_id=a.id" : '';
        $location = $after ? 'COALESCE(repair.corrected_arpa_id,a.arpa_division_location_id)' : 'a.arpa_division_location_id';
        $result = [];
        foreach (['PERMANENT', 'ACTING', 'ATTEND_TO_DUTY'] as $type) {
            $sql = "SELECT COUNT(*) FROM (SELECT {$location} location_id FROM arpa_division_appointment a {$join}
                    LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id
                    WHERE a.legacy_history_only=0 AND c.id IS NULL AND a.appointment_type=?
                    GROUP BY {$location} HAVING COUNT(*)>1) collisions";
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$type]);
            $result['arpa_divisions_multiple_open_'.strtolower($type)] = (int)$statement->fetchColumn();
        }
        foreach (['PERMANENT', 'ACTING'] as $type) {
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM (SELECT a.officer_id FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.legacy_history_only=0 AND c.id IS NULL AND a.appointment_type=? GROUP BY a.officer_id HAVING COUNT(*)>1) collisions");
            $statement->execute([$type]);
            $result['officers_multiple_open_'.strtolower($type)] = (int)$statement->fetchColumn();
        }
        $result['dependent_without_qualifying_permanent'] = (int)$this->pdo->query("SELECT COUNT(*) FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.legacy_history_only=0 AND c.id IS NULL AND a.appointment_type<>'PERMANENT' AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment p LEFT JOIN arpa_division_appointment_closure pc ON pc.appointment_id=p.id WHERE p.officer_id=a.officer_id AND p.legacy_history_only=0 AND p.appointment_type='PERMANENT' AND p.effective_from<=a.effective_from AND (pc.effective_to IS NULL OR pc.effective_to>=a.effective_from))")->fetchColumn();
        $issueTotal = (int)$this->pdo->query('SELECT COUNT(*) FROM '.ArpaAppointmentReadService::issueSource().' q')->fetchColumn();
        if ($after) {
            $beforeSensitive = $this->locationSensitiveIssueCounts(false);
            $afterSensitive = $this->locationSensitiveIssueCounts(true, $mapTable);
            $result['appointment_data_issues'] = $issueTotal - array_sum($beforeSensitive) + array_sum($afterSensitive);
        } else {
            $result['appointment_data_issues'] = $issueTotal;
        }
        if ($mapTable !== null) {
            $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$mapTable}");
        }
        return $result;
    }

    private function temporaryProjectionMap(array $proposals): string
    {
        $table = 'tmp_legacy_arpa_location_repair_map';
        $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$table}");
        $this->pdo->exec("CREATE TEMPORARY TABLE {$table}(appointment_id CHAR(36) PRIMARY KEY,corrected_arpa_id CHAR(36) NOT NULL,corrected_asc_id CHAR(36) NOT NULL,corrected_arpa_dad VARCHAR(20) NOT NULL,corrected_arpa_name VARCHAR(255) NOT NULL)");
        $insert = $this->pdo->prepare("INSERT INTO {$table} VALUES(?,?,?,?,?)");
        foreach ($proposals as $p) {
            if ($p['repair_appointment']) {
                $insert->execute([$p['appointment_id'],$p['corrected_arpa_id'],$p['corrected_asc_id'],$p['corrected_arpa_dad'],$p['corrected_arpa_name']]);
            }
        }
        return $table;
    }

    private function locationSensitiveIssueCounts(bool $after, ?string $mapTable = null): array
    {
        $join = $after ? " LEFT JOIN {$mapTable} repair ON repair.appointment_id=a.id" : '';
        $location = $after ? 'COALESCE(repair.corrected_arpa_id,a.arpa_division_location_id)' : 'a.arpa_division_location_id';
        $multiple = (int)$this->pdo->query("SELECT COUNT(*) FROM (SELECT {$location} location_id FROM arpa_division_appointment a {$join} LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE c.id IS NULL GROUP BY {$location} HAVING COUNT(*)>1) collisions")->fetchColumn();
        $future = (int)$this->pdo->query("SELECT COUNT(*) FROM (SELECT {$location} location_id FROM arpa_division_appointment a {$join} LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE c.id IS NULL GROUP BY {$location} HAVING COUNT(*)>1 AND MAX(a.effective_from>CURRENT_DATE())=1) collisions")->fetchColumn();
        return ['division_multiple_open' => $multiple, 'future_overlap' => $future];
    }

    private function wewagedaraProjection(array $proposals): array
    {
        $statement = $this->pdo->prepare("SELECT id FROM location WHERE dad_number='70007-0007026' LIMIT 1");
        $statement->execute();
        $id = $statement->fetchColumn();
        if (!$id) {
            return ['found' => false];
        }
        $before = $this->wewagedaraCounts((string)$id, false);
        $map = [];
        foreach ($proposals as $proposal) {
            $map[$proposal['request_id']] = $proposal['corrected_arpa_id'];
        }
        $afterRequests = 0;
        foreach ($this->pdo->query("SELECT id,arpa_division_location_id FROM arpa_division_appointment_request WHERE record_origin='LEGACY_IMPORT'")->fetchAll() as $row) {
            if (($map[$row['id']] ?? $row['arpa_division_location_id']) === $id) {
                $afterRequests++;
            }
        }
        $mapTable = $this->temporaryProjectionMap($proposals);
        $after = $this->wewagedaraCounts((string)$id, true, $mapTable);
        $after['requests'] = $afterRequests;
        $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$mapTable}");
        return ['found' => true, 'dad_number' => '70007-0007026', 'before' => $before, 'after' => $after];
    }

    private function wewagedaraCounts(string $id, bool $after, ?string $mapTable = null): array
    {
        $join = $after ? " LEFT JOIN {$mapTable} repair ON repair.appointment_id=a.id" : '';
        $location = $after ? 'COALESCE(repair.corrected_arpa_id,a.arpa_division_location_id)' : 'a.arpa_division_location_id';
        $statement = $this->pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN a.legacy_history_only=0 AND c.id IS NULL AND a.appointment_type='PERMANENT' THEN 1 ELSE 0 END) open_permanent,SUM(CASE WHEN a.legacy_history_only=0 AND c.id IS NULL AND a.appointment_type='ACTING' THEN 1 ELSE 0 END) open_acting FROM arpa_division_appointment a {$join} LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE {$location}=?");
        $statement->execute([$id]);
        $counts = $statement->fetch();
        if (!$after) {
            $request = $this->pdo->prepare("SELECT COUNT(*) total,SUM(JSON_UNQUOTE(JSON_EXTRACT(origin_metadata_json,'$.location_provenance.target_context_id'))<>?) wrong_preserved_target FROM arpa_division_appointment_request WHERE record_origin='LEGACY_IMPORT' AND arpa_division_location_id=?");
            $request->execute([$id, $id]);
            $requestCounts = $request->fetch();
            $counts['requests'] = (int)$requestCounts['total'];
            $counts['wrong_preserved_target'] = (int)$requestCounts['wrong_preserved_target'];
        }
        return array_map('intval', $counts);
    }

    private function integrityState(): array
    {
        return [
            'request_identity' => $this->checksum("SELECT CONCAT_WS('|',id,officer_id,appointment_type,request_type,COALESCE(requested_effective_from,''),COALESCE(requested_effective_to,''),workflow_status,COALESCE(created_by,''),created_at,legacy_history_only,legacy_exception,COALESCE(origin_metadata_json,'')) value FROM arpa_division_appointment_request WHERE record_origin='LEGACY_IMPORT' ORDER BY id"),
            'appointment_identity' => $this->checksum("SELECT CONCAT_WS('|',id,request_id,officer_id,appointment_type,effective_from,service_permanency_snapshot,service_permanency_source,legacy_history_only,legacy_exception,COALESCE(approved_by,''),COALESCE(approved_at,''),approval_timestamp_provenance,COALESCE(origin_metadata_json,'')) value FROM arpa_division_appointment WHERE record_origin='LEGACY_IMPORT' ORDER BY id"),
            'closures' => $this->checksum("SELECT CONCAT_WS('|',id,appointment_id,effective_to,COALESCE(end_reason_id,''),COALESCE(legacy_reason_id,''),COALESCE(legacy_reason_text,''),closure_kind,COALESCE(approved_by,''),COALESCE(approved_at,'')) value FROM arpa_division_appointment_closure WHERE record_origin='LEGACY_IMPORT' ORDER BY id"),
            'workflow' => $this->checksum("SELECT CONCAT_WS('|',id,request_id,action,stage,user_id,COALESCE(action_at,''),timestamp_provenance,COALESCE(legacy_source_payload_json,'')) value FROM arpa_appointment_workflow_action WHERE record_origin='LEGACY_IMPORT' ORDER BY id"),
            'source_references' => $this->checksum("SELECT CONCAT_WS('|',id,business_record_id,source_system,source_table,legacy_appointment_id,COALESCE(target_appointment_request_id,''),COALESCE(target_appointment_id,''),legacy_payload_json) value FROM legacy_arpa_appointment_source_reference ORDER BY id"),
        ];
    }

    private function checksum(string $sql): array
    {
        $hash = hash_init('sha256');
        $count = 0;
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $statement = $this->pdo->query($sql);
            while ($row = $statement->fetch()) {
                hash_update($hash, (string)$row['value']."\n");
                $count++;
            }
            $statement->closeCursor();
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        return ['count' => $count, 'sha256' => hash_final($hash)];
    }

    private function assertExecutor(string $userId): void
    {
        $sql = "SELECT COUNT(*) FROM system_user u
                JOIN user_account_role ur ON ur.user_id=u.id AND ur.active=1 AND ur.approval_status='APPROVED'
                  AND ur.effective_from<=CURRENT_DATE() AND (ur.effective_to IS NULL OR ur.effective_to>=CURRENT_DATE())
                JOIN application_role role ON role.id=ur.role_id AND role.active=1 AND role.approval_status='APPROVED'
                JOIN application_role_permission rp ON rp.role_id=role.id
                JOIN application_permission permission ON permission.id=rp.permission_id AND permission.active=1
                WHERE u.id=? AND u.enabled=1 AND u.account_status='ACTIVE'
                  AND permission.permission_key='arpa.legacy-reconciliation.decide'";
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$userId]);
        if ((int)$statement->fetchColumn() === 0) {
            throw new RuntimeException('An active authorized repair executor is required.');
        }
    }

    private function writeReports(array $summary, array $proposals, array $manual): array
    {
        $directory = BASE_PATH.'/storage/reports';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create repair report directory.');
        }
        $stamp = date('Ymd-His');
        $jsonPath = "{$directory}/legacy-arpa-location-repair-dry-run-{$stamp}.json";
        $csvPath = "{$directory}/legacy-arpa-location-repair-dry-run-{$stamp}.csv";
        file_put_contents($jsonPath, $this->json(['generated_at' => date(DATE_ATOM), 'summary' => $summary, 'manual_review' => $manual]), LOCK_EX);
        $handle = fopen($csvPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create repair CSV report.');
        }
        fputcsv($handle, ['request_id','appointment_id','previous_arpa_id','corrected_arpa_id','previous_asc_id','corrected_asc_id','legacy_arpa_id','legacy_arpa_name','request_repair','appointment_repair','closure_snapshot_repair'], ',', '"', '');
        foreach ($proposals as $p) {
            fputcsv($handle, [$p['request_id'],$p['appointment_id'],$p['previous_request_arpa_id'],$p['corrected_arpa_id'],$p['previous_request_asc_id'],$p['corrected_asc_id'],$p['legacy_arpa_id'],$p['legacy_arpa_name'],$p['repair_request']?1:0,$p['repair_appointment']?1:0,$p['snapshot_changes']['closure_context_snapshots']?1:0], ',', '"', '');
        }
        fclose($handle);
        return ['json' => $jsonPath, 'csv' => $csvPath];
    }

    private function evidence(?string $json): array
    {
        return $this->decodeJson($json)['location_provenance'] ?? [];
    }

    private function decodeJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function replaceExactValues(mixed $value, array $replacements): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replaceExactValues($item, $replacements);
            }
            return $value;
        }
        return is_string($value) && isset($replacements[$value]) ? $replacements[$value] : $value;
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return mb_strtolower($value, 'UTF-8');
    }

    private function dadSuffix(string $dadNumber): int
    {
        return preg_match('/-(\d+)$/', $dadNumber, $match) === 1 ? (int)$match[1] : -1;
    }

    private function layerCounters(): array
    {
        return ['examined' => 0, 'correct_already' => 0, 'mismatched' => 0, 'repairable' => 0, 'manual_review' => 0];
    }

    private function uuid(): string
    {
        return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
