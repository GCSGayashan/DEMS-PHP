<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use Throwable;

final class Arpa2025NonPermanentClosureRepairService
{
    public const START_DATE = '2025-01-01';
    public const TARGET_END_DATE = '2025-12-31';
    public const END_REASON_KEY = 'END_OF_APPOINTMENT_PERIOD';

    public const PRE_OPEN = 3309;
    public const PRE_BEFORE = 213;
    public const PRE_EXACT = 8;
    public const PRE_AFTER = 7;
    public const PRE_CHANGE = 3316;

    public const POST_EXACT = 3324;

    /** @var array<int,string> */
    private const NON_PERMANENT_TYPES = [
        'ACTING',
        'DUTY_COVERING',
        'ATTEND_TO_DUTY',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function dryRun(): array
    {
        $summary = $this->summary();
        $state = $this->detectState($summary);
        $endReason = $this->endReasonState();

        return [
            'mode' => 'DRY_RUN',
            'state' => $state,
            'rule' => [
                'start_from' => self::START_DATE,
                'start_to' => self::TARGET_END_DATE,
                'appointment_types' => self::NON_PERMANENT_TYPES,
                'target_end_date' => self::TARGET_END_DATE,
                'preserve_earlier_closures' => true,
                'preserve_permanent_appointments' => true,
            ],
            'summary' => $summary,
            'by_type' => $this->summaryByType(),
            'end_reason' => $endReason,
            'errors' => $this->validationErrors($summary, $state, $endReason),
        ];
    }

    /** @return array<string,mixed> */
    public function execute(string $actorUserId, string $backupPath): array
    {
        $pre = $this->dryRun();

        if ($pre['errors'] !== []) {
            throw new DomainException(
                'Pre-execution validation failed: ' . implode(' | ', $pre['errors'])
            );
        }

        if ($pre['state'] !== 'READY') {
            throw new DomainException(
                'Repair execution requires the exact READY pre-repair state.'
            );
        }

        $this->assertExecutor($actorUserId);
        $backup = $this->assertBackup($backupPath);
        $reason = $this->requireEndReason();

        $appointmentHashBefore = $this->hashQuery(
            'SELECT * FROM arpa_division_appointment ORDER BY id'
        );

        $requestHashBefore = $this->hashQuery(
            'SELECT * FROM arpa_division_appointment_request ORDER BY id'
        );

        $workflowHashBefore = $this->hashQuery(
            'SELECT * FROM arpa_appointment_workflow_action ORDER BY id'
        );

        $permanentClosureHashBefore = $this->hashQuery(
            "SELECT c.*
             FROM arpa_division_appointment_closure c
             JOIN arpa_division_appointment a
               ON a.id=c.appointment_id
             WHERE a.effective_from BETWEEN ? AND ?
               AND a.appointment_type='PERMANENT'
             ORDER BY c.id",
            [self::START_DATE, self::TARGET_END_DATE]
        );

        $preservedIds = $this->preservedClosureIds();
        $preservedHashBefore = $this->hashRowsByIds(
            'arpa_division_appointment_closure',
            $preservedIds
        );

        $closureCountBefore = $this->count('arpa_division_appointment_closure');
        $correctionCountBefore = $this->count('arpa_appointment_data_correction');
        $auditCountBefore = $this->count('audit_event');

        $created = 0;
        $corrected = 0;
        $ledgerCreated = 0;
        $auditCreated = 0;

        $this->pdo->beginTransaction();

        try {
            $this->lockScope();

            $lockedPlan = $this->dryRun();

            if (
                $lockedPlan['state'] !== 'READY'
                || $lockedPlan['errors'] !== []
            ) {
                throw new DomainException(
                    'Repair state changed after row locking.'
                );
            }

            $targets = $this->changeRows();

            if (count($targets) !== self::PRE_CHANGE) {
                throw new DomainException(
                    'Expected exactly 3316 appointment corrections after locking.'
                );
            }

            foreach ($targets as $target) {
                $before = $this->snapshot((string)$target['id']);
                $correctionId = $this->uuid();

                if ($target['closure_id'] === null) {
                    $this->insertClosure(
                        $target,
                        $correctionId,
                        (string)$reason['id'],
                        $actorUserId
                    );
                    $created++;
                } else {
                    $stmt = $this->pdo->prepare(
                        'UPDATE arpa_division_appointment_closure
                         SET effective_to=?,
                             end_reason_id=?,
                             data_correction_id=?
                         WHERE id=?
                           AND effective_to>?'
                    );

                    $stmt->execute([
                        self::TARGET_END_DATE,
                        $reason['id'],
                        $correctionId,
                        $target['closure_id'],
                        self::TARGET_END_DATE,
                    ]);

                    if ($stmt->rowCount() !== 1) {
                        throw new DomainException(
                            'Expected one existing later closure to be corrected.'
                        );
                    }

                    $corrected++;
                }

                $after = $this->snapshot((string)$target['id']);

                $this->insertCorrectionLedger(
                    $target,
                    $correctionId,
                    $actorUserId,
                    $before,
                    $after
                );

                $ledgerCreated++;

                $this->insertAudit(
                    $target,
                    $correctionId,
                    $actorUserId
                );

                $auditCreated++;
            }

            if ($created !== 3309) {
                throw new DomainException(
                    "Expected 3309 new closures, created {$created}."
                );
            }

            if ($corrected !== 7) {
                throw new DomainException(
                    "Expected 7 later closures to be corrected, changed {$corrected}."
                );
            }

            if ($ledgerCreated !== 3316 || $auditCreated !== 3316) {
                throw new DomainException(
                    'Expected exactly 3316 correction and audit records.'
                );
            }

            $post = $this->dryRun();

            if ($post['state'] !== 'ALREADY_APPLIED') {
                throw new DomainException(
                    'Post-repair state is not the expected ALREADY_APPLIED state.'
                );
            }

            if ($post['errors'] !== []) {
                throw new DomainException(
                    'Post-repair validation failed: ' . implode(' | ', $post['errors'])
                );
            }

            if (
                $this->hashQuery(
                    'SELECT * FROM arpa_division_appointment ORDER BY id'
                ) !== $appointmentHashBefore
            ) {
                throw new DomainException(
                    'Appointment master rows changed unexpectedly.'
                );
            }

            if (
                $this->hashQuery(
                    'SELECT * FROM arpa_division_appointment_request ORDER BY id'
                ) !== $requestHashBefore
            ) {
                throw new DomainException(
                    'Appointment request rows changed unexpectedly.'
                );
            }

            if (
                $this->hashQuery(
                    'SELECT * FROM arpa_appointment_workflow_action ORDER BY id'
                ) !== $workflowHashBefore
            ) {
                throw new DomainException(
                    'Appointment workflow rows changed unexpectedly.'
                );
            }

            if (
                $this->hashQuery(
                    "SELECT c.*
                     FROM arpa_division_appointment_closure c
                     JOIN arpa_division_appointment a
                       ON a.id=c.appointment_id
                     WHERE a.effective_from BETWEEN ? AND ?
                       AND a.appointment_type='PERMANENT'
                     ORDER BY c.id",
                    [self::START_DATE, self::TARGET_END_DATE]
                ) !== $permanentClosureHashBefore
            ) {
                throw new DomainException(
                    'A 2025 PERMANENT appointment closure changed unexpectedly.'
                );
            }

            if (
                $this->hashRowsByIds(
                    'arpa_division_appointment_closure',
                    $preservedIds
                ) !== $preservedHashBefore
            ) {
                throw new DomainException(
                    'One of the 221 preserved existing closures changed unexpectedly.'
                );
            }

            if (
                $this->count('arpa_division_appointment_closure')
                !== $closureCountBefore + 3309
            ) {
                throw new DomainException(
                    'Unexpected final appointment closure count.'
                );
            }

            if (
                $this->count('arpa_appointment_data_correction')
                !== $correctionCountBefore + 3316
            ) {
                throw new DomainException(
                    'Unexpected final correction ledger count.'
                );
            }

            if (
                $this->count('audit_event')
                !== $auditCountBefore + 3316
            ) {
                throw new DomainException(
                    'Unexpected final audit-event count.'
                );
            }

            $this->pdo->commit();

            return [
                'mode' => 'EXECUTE',
                'state' => 'ALREADY_APPLIED',
                'backup' => $backup,
                'created_closures' => $created,
                'corrected_later_closures' => $corrected,
                'correction_ledger_rows' => $ledgerCreated,
                'audit_rows' => $auditCreated,
                'preserved_existing_closures' => count($preservedIds),
                'summary' => $post['summary'],
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /** @return array<string,int> */
    private function summary(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS non_permanent_2025,
                SUM(c.id IS NULL) AS open_without_closure,
                SUM(c.id IS NOT NULL AND c.effective_to < ?) AS closed_before_target,
                SUM(c.id IS NOT NULL AND c.effective_to = ?) AS already_target_date,
                SUM(c.id IS NOT NULL AND c.effective_to > ?) AS closed_after_target,
                SUM(c.id IS NULL OR c.effective_to > ?) AS rows_requiring_change,
                SUM(c.id IS NOT NULL AND c.effective_to < a.effective_from) AS invalid_date_ranges,
                SUM(
                    c.id IS NOT NULL
                    AND c.effective_to < ?
                    AND c.effective_to < ?
                ) AS earlier_closures_outside_2025
             FROM arpa_division_appointment a
             LEFT JOIN arpa_division_appointment_closure c
               ON c.appointment_id=a.id
             WHERE a.effective_from BETWEEN ? AND ?
               AND a.appointment_type IN (
                    'ACTING',
                    'DUTY_COVERING',
                    'ATTEND_TO_DUTY'
               )"
        );

        $stmt->execute([
            self::TARGET_END_DATE,
            self::TARGET_END_DATE,
            self::TARGET_END_DATE,
            self::TARGET_END_DATE,
            self::TARGET_END_DATE,
            self::START_DATE,
            self::START_DATE,
            self::TARGET_END_DATE,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $permanent = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM arpa_division_appointment
             WHERE effective_from BETWEEN ? AND ?
               AND appointment_type='PERMANENT'"
        );

        $permanent->execute([
            self::START_DATE,
            self::TARGET_END_DATE,
        ]);

        return [
            'non_permanent_2025' => (int)($row['non_permanent_2025'] ?? 0),
            'permanent_2025' => (int)$permanent->fetchColumn(),
            'open_without_closure' => (int)($row['open_without_closure'] ?? 0),
            'closed_before_target' => (int)($row['closed_before_target'] ?? 0),
            'already_target_date' => (int)($row['already_target_date'] ?? 0),
            'closed_after_target' => (int)($row['closed_after_target'] ?? 0),
            'rows_requiring_change' => (int)($row['rows_requiring_change'] ?? 0),
            'invalid_date_ranges' => (int)($row['invalid_date_ranges'] ?? 0),
            'earlier_closures_outside_2025' =>
                (int)($row['earlier_closures_outside_2025'] ?? 0),
        ];
    }

    private function detectState(array $s): string
    {
        $common =
            $s['invalid_date_ranges'] === 0
            && $s['earlier_closures_outside_2025'] === 0;

        if (
            $common
            && $s['open_without_closure'] === self::PRE_OPEN
            && $s['already_target_date'] === self::PRE_EXACT
            && $s['closed_after_target'] === self::PRE_AFTER
            && $s['rows_requiring_change'] === self::PRE_CHANGE
        ) {
            return 'READY';
        }

        if (
            $common
            && $s['open_without_closure'] === 0
            && $s['closed_after_target'] === 0
            && $s['rows_requiring_change'] === 0
        ) {
            return 'ALREADY_APPLIED';
        }

        return 'DRIFT';
    }

    /** @return array<int,string> */
    private function validationErrors(
        array $summary,
        string $state,
        array $endReason
    ): array {
        $errors = [];

        if ($state === 'DRIFT') {
            $errors[] = 'Appointment population does not match the approved pre- or post-repair state.';
        }

        if ($endReason['exists']) {
            if ((int)$endReason['service_terminating'] !== 0) {
                $errors[] = 'END_OF_APPOINTMENT_PERIOD must not terminate service.';
            }

            if ((int)$endReason['active'] !== 1) {
                $errors[] = 'END_OF_APPOINTMENT_PERIOD must be active.';
            }
        } elseif ($state === 'ALREADY_APPLIED') {
            $errors[] = 'Post-repair state requires END_OF_APPOINTMENT_PERIOD.';
        }

        return $errors;
    }

    /** @return array<string,array<string,int>> */
    private function summaryByType(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                a.appointment_type,
                COUNT(*) AS total,
                SUM(c.id IS NULL) AS open_without_closure,
                SUM(c.id IS NOT NULL AND c.effective_to < ?) AS closed_before_target,
                SUM(c.id IS NOT NULL AND c.effective_to = ?) AS already_target_date,
                SUM(c.id IS NOT NULL AND c.effective_to > ?) AS closed_after_target
             FROM arpa_division_appointment a
             LEFT JOIN arpa_division_appointment_closure c
               ON c.appointment_id=a.id
             WHERE a.effective_from BETWEEN ? AND ?
               AND a.appointment_type IN (
                    'ACTING',
                    'DUTY_COVERING',
                    'ATTEND_TO_DUTY'
               )
             GROUP BY a.appointment_type
             ORDER BY a.appointment_type"
        );

        $stmt->execute([
            self::TARGET_END_DATE,
            self::TARGET_END_DATE,
            self::TARGET_END_DATE,
            self::START_DATE,
            self::TARGET_END_DATE,
        ]);

        $out = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['appointment_type']] = [
                'total' => (int)$row['total'],
                'open_without_closure' => (int)$row['open_without_closure'],
                'closed_before_target' => (int)$row['closed_before_target'],
                'already_target_date' => (int)$row['already_target_date'],
                'closed_after_target' => (int)$row['closed_after_target'],
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function endReasonState(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id,system_key,name_en,service_terminating,active
             FROM arpa_appointment_end_reason
             WHERE system_key=?
             LIMIT 1"
        );

        $stmt->execute([self::END_REASON_KEY]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'exists' => false,
                'system_key' => self::END_REASON_KEY,
                'migration_required' => true,
            ];
        }

        return [
            'exists' => true,
            'id' => (string)$row['id'],
            'system_key' => (string)$row['system_key'],
            'name_en' => (string)$row['name_en'],
            'service_terminating' => (int)$row['service_terminating'],
            'active' => (int)$row['active'],
            'migration_required' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function requireEndReason(): array
    {
        $reason = $this->endReasonState();

        if (
            !$reason['exists']
            || (int)$reason['service_terminating'] !== 0
            || (int)$reason['active'] !== 1
        ) {
            throw new DomainException(
                'Migration 045 must provide an active non-service-terminating END_OF_APPOINTMENT_PERIOD reason.'
            );
        }

        return $reason;
    }

    private function assertExecutor(string $userId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM system_user u
             JOIN user_account_role uar
               ON uar.user_id=u.id
              AND uar.active=1
              AND uar.approval_status='APPROVED'
              AND uar.effective_from<=CURRENT_DATE()
              AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE())
             JOIN application_role r
               ON r.id=uar.role_id
              AND r.role_code='SYSTEM_ADMIN'
              AND r.active=1
              AND r.approval_status='APPROVED'
             WHERE u.id=?
               AND u.enabled=1
               AND u.account_status='ACTIVE'"
        );

        $stmt->execute([$userId]);

        if ((int)$stmt->fetchColumn() < 1) {
            throw new DomainException(
                'Execution requires an active SYSTEM_ADMIN.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function assertBackup(string $path): array
    {
        $real = realpath($path);

        if ($real === false || !is_file($real) || filesize($real) < 1024) {
            throw new DomainException(
                'Execution requires a confirmed non-empty MySQL backup.'
            );
        }

        if ((int)filemtime($real) < time() - 86400) {
            throw new DomainException(
                'Execution requires a MySQL backup less than 24 hours old.'
            );
        }

        $handle = fopen($real, 'rb');

        if ($handle === false) {
            throw new DomainException('Unable to inspect the backup file.');
        }

        $header = fread($handle, 64);
        fclose($handle);

        if (!is_string($header) || !str_starts_with($header, '-- MySQL dump')) {
            throw new DomainException(
                'Backup file does not have a valid MySQL dump header.'
            );
        }

        return [
            'path' => $real,
            'size' => filesize($real),
            'sha256' => hash_file('sha256', $real),
        ];
    }

    private function lockScope(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT id
             FROM arpa_division_appointment
             WHERE effective_from BETWEEN ? AND ?
               AND appointment_type IN (
                    'ACTING',
                    'DUTY_COVERING',
                    'ATTEND_TO_DUTY'
               )
             ORDER BY id
             FOR UPDATE"
        );

        $stmt->execute([
            self::START_DATE,
            self::TARGET_END_DATE,
        ]);

        $stmt->fetchAll();

        $stmt = $this->pdo->prepare(
            "SELECT c.id
             FROM arpa_division_appointment_closure c
             JOIN arpa_division_appointment a
               ON a.id=c.appointment_id
             WHERE a.effective_from BETWEEN ? AND ?
               AND a.appointment_type IN (
                    'ACTING',
                    'DUTY_COVERING',
                    'ATTEND_TO_DUTY'
               )
             ORDER BY c.id
             FOR UPDATE"
        );

        $stmt->execute([
            self::START_DATE,
            self::TARGET_END_DATE,
        ]);

        $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    private function changeRows(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                a.id,
                a.record_origin,
                a.request_id,
                a.officer_id,
                a.appointment_type,
                a.asc_location_id,
                a.arpa_division_location_id,
                a.effective_from,
                a.hierarchy_snapshot_json,
                a.origin_metadata_json,
                c.id AS closure_id,
                c.effective_to AS current_effective_to
             FROM arpa_division_appointment a
             LEFT JOIN arpa_division_appointment_closure c
               ON c.appointment_id=a.id
             WHERE a.effective_from BETWEEN ? AND ?
               AND a.appointment_type IN (
                    'ACTING',
                    'DUTY_COVERING',
                    'ATTEND_TO_DUTY'
               )
               AND (
                    c.id IS NULL
                    OR c.effective_to>?
               )
             ORDER BY a.id"
        );

        $stmt->execute([
            self::START_DATE,
            self::TARGET_END_DATE,
            self::TARGET_END_DATE,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function insertClosure(
        array $target,
        string $correctionId,
        string $endReasonId,
        string $actorUserId
    ): void {
        $metadata = $this->decode(
            (string)($target['origin_metadata_json'] ?? '')
        );

        $metadata['year_end_2025_closure_repair'] = [
            'correction_id' => $correctionId,
            'effective_to' => self::TARGET_END_DATE,
            'executed_by' => $actorUserId,
        ];

        $legacy = $target['record_origin'] === 'LEGACY_IMPORT';

        $stmt = $this->pdo->prepare(
            "INSERT INTO arpa_division_appointment_closure(
                id,
                record_origin,
                appointment_id,
                request_id,
                effective_to,
                end_reason_id,
                closure_kind,
                closure_source,
                data_correction_id,
                remarks,
                context_snapshot_json,
                approved_by,
                approved_at,
                approval_timestamp_provenance,
                origin_metadata_json
             ) VALUES(
                ?,?,?,?,?,?,
                'DIRECT',
                'DATA_ISSUE_CORRECTION',
                ?,?,
                ?,
                NULL,
                NULL,
                ?,
                ?
             )"
        );

        $stmt->execute([
            $this->uuid(),
            $target['record_origin'],
            $target['id'],
            $target['request_id'],
            self::TARGET_END_DATE,
            $endReasonId,
            $correctionId,
            'Controlled 2025 year-end closure of non-permanent ARPA appointment.',
            $target['hierarchy_snapshot_json'],
            $legacy
                ? 'UNAVAILABLE_FROM_LEGACY_SOURCE'
                : 'DATA_CORRECTION_RECORDED',
            $this->json($metadata),
        ]);
    }

    private function insertCorrectionLedger(
        array $target,
        string $correctionId,
        string $actorUserId,
        array $before,
        array $after
    ): void {
        $legacyRefs = null;

        if ($target['record_origin'] === 'LEGACY_IMPORT') {
            $metadata = $this->decode(
                (string)($target['origin_metadata_json'] ?? '')
            );

            $legacyRefs = $this->json([
                'appointment_id' => $target['id'],
                'request_id' => $target['request_id'],
                'source_references' =>
                    $metadata['source_references']
                    ?? $metadata['source_reference']
                    ?? null,
                'source_table' => $metadata['source_table'] ?? null,
                'source_row_id' => $metadata['source_row_id'] ?? null,
            ]);
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO arpa_appointment_data_correction(
                id,
                issue_row_key,
                issue_type,
                officer_id,
                appointment_id,
                request_id,
                related_appointment_ids_json,
                asc_location_id,
                corrected_by,
                correction_action,
                resolution_status,
                correction_reason,
                remarks,
                evidence_reference,
                before_json,
                after_json,
                record_origin,
                legacy_source_references_json
             ) VALUES(
                ?,?,?,?,?,?,?,?,?,?,
                'RESOLVED_BY_CORRECTION',
                ?,?,?,?,?,?,?
             )"
        );

        $stmt->execute([
            $correctionId,
            'NON_PERMANENT_2025_YEAR_END:' . $target['id'],
            'NON_PERMANENT_2025_YEAR_END',
            $target['officer_id'],
            $target['id'],
            $target['request_id'],
            $this->json([$target['id']]),
            $target['asc_location_id'],
            $actorUserId,
            'SET_EFFECTIVE_TO',
            'Non-permanent ARPA appointments starting in 2025 must not remain operational after 2025-12-31.',
            'Controlled nationwide year-end historical correction.',
            'Approved 2025 non-permanent ARPA appointment year-end rule.',
            $this->json($before),
            $this->json($after),
            $target['record_origin'],
            $legacyRefs,
        ]);
    }

    private function insertAudit(
        array $target,
        string $correctionId,
        string $actorUserId
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO audit_event(
                actor_user_id,
                action_key,
                target_type,
                target_id,
                details_json,
                severity,
                source_ip
             ) VALUES(
                ?,
                'arpa.appointment.2025-year-end-repair',
                'ARPA_APPOINTMENT_YEAR_END_REPAIR',
                ?,
                ?,
                'WARNING',
                'CLI'
             )"
        );

        $stmt->execute([
            $actorUserId,
            $correctionId,
            $this->json([
                'repair' => 'NON_PERMANENT_2025_YEAR_END',
                'appointment_id' => $target['id'],
                'appointment_type' => $target['appointment_type'],
                'previous_effective_to' => $target['current_effective_to'],
                'effective_to' => self::TARGET_END_DATE,
            ]),
        ]);
    }

    /** @return array<string,mixed> */
    private function snapshot(string $appointmentId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                a.id AS appointment_id,
                a.record_origin,
                a.request_id,
                a.officer_id,
                a.appointment_type,
                a.asc_location_id,
                a.arpa_division_location_id,
                a.effective_from,
                a.legacy_history_only,
                c.id AS closure_id,
                c.effective_to,
                c.end_reason_id,
                c.closure_kind,
                c.closure_source,
                c.data_correction_id,
                c.approval_timestamp_provenance
             FROM arpa_division_appointment a
             LEFT JOIN arpa_division_appointment_closure c
               ON c.appointment_id=a.id
             WHERE a.id=?"
        );

        $stmt->execute([$appointmentId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,string> */
    private function preservedClosureIds(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id
             FROM arpa_division_appointment_closure c
             JOIN arpa_division_appointment a
               ON a.id=c.appointment_id
             WHERE a.effective_from BETWEEN ? AND ?
               AND a.appointment_type IN (
                    'ACTING',
                    'DUTY_COVERING',
                    'ATTEND_TO_DUTY'
               )
               AND c.effective_to<=?
             ORDER BY c.id"
        );

        $stmt->execute([
            self::START_DATE,
            self::TARGET_END_DATE,
            self::TARGET_END_DATE,
        ]);

        $ids = array_map(
            'strval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );

        if (count($ids) !== 221) {
            throw new DomainException(
                'Expected exactly 221 existing closures to be preserved.'
            );
        }

        return $ids;
    }

    private function hashRowsByIds(string $table, array $ids): string
    {
        if ($ids === []) {
            return hash('sha256', '[]');
        }

        $allowed = [
            'arpa_division_appointment_closure',
        ];

        if (!in_array($table, $allowed, true)) {
            throw new DomainException('Unsupported protected table.');
        }

        sort($ids);

        $marks = implode(',', array_fill(0, count($ids), '?'));

        return $this->hashQuery(
            "SELECT * FROM {$table}
             WHERE id IN({$marks})
             ORDER BY id",
            $ids
        );
    }

    private function hashQuery(string $sql, array $params = []): string
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $context = hash_init('sha256');
        $rowCount = 0;

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $encoded = $this->json($row);

            hash_update(
                $context,
                strlen($encoded) . ':' . $encoded . "\n"
            );

            $rowCount++;
        }

        hash_update(
            $context,
            '#rows=' . $rowCount
        );

        return hash_final($context);
    }

    private function count(string $table): int
    {
        $allowed = [
            'arpa_division_appointment_closure',
            'arpa_appointment_data_correction',
            'audit_event',
        ];

        if (!in_array($table, $allowed, true)) {
            throw new DomainException('Unsupported count table.');
        }

        return (int)$this->pdo
            ->query("SELECT COUNT(*) FROM {$table}")
            ->fetchColumn();
    }

    private function uuid(): string
    {
        return (string)$this->pdo
            ->query('SELECT UUID()')
            ->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );
    }
}
