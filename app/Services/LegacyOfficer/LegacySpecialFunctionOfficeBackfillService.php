<?php
declare(strict_types=1);

namespace App\Services\LegacyOfficer;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Backfills current Officer -> ASC Office membership using confirmed DEMS
 * reconciliation decisions only. The legacy HR database is never queried.
 */
final class LegacySpecialFunctionOfficeBackfillService
{
    private const FUNCTIONS = ['AGRARIAN_BANK', 'SALES_SHOP', 'SITHAMU'];
    private const SOURCE_SYSTEM = 'AGRARIANADMIN_HR_RECONCILED';
    private const SOURCE_TABLE = 'legacy_arpa_reconciliation_item';

    public function __construct(
        private readonly PDO $target,
        private readonly string $asOf,
    ) {
    }

    public function dryRun(): array
    {
        $this->assertDate($this->asOf);
        $before = $this->targetState();
        $report = $this->analyse();
        $report['zero_write_verification'] = [
            'target_unchanged' => $before === $this->targetState(),
        ];
        $report['reports'] = $this->writeReports($report);

        return $report;
    }

    public function execute(): array
    {
        $preflight = $this->dryRun();
        if ((int) $preflight['projection']['true_execution_blockers'] !== 0) {
            throw new RuntimeException(sprintf(
                'Execution refused: %d true blocker(s) remain. Resolve them in Legacy Migration Review and run the dry-run again.',
                $preflight['projection']['true_execution_blockers'],
            ));
        }

        $this->target->beginTransaction();
        try {
            $this->lockInputs();
            $lockedReport = $this->analyse();
            if ((int) $lockedReport['projection']['true_execution_blockers'] !== 0) {
                throw new RuntimeException('Execution refused because reconciliation or Office state changed after preflight.');
            }

            $runId = $this->uuid();
            $run = $this->target->prepare(
                "INSERT INTO legacy_arpa_office_backfill_run
                 (id,source_system,source_table,as_of_date,status,qualifying_source_rows,
                  distinct_legacy_officers,distinct_target_officers,distinct_ascs)
                 VALUES(?,?,?,?,'RUNNING',?,?,?,?)"
            );
            $run->execute([
                $runId,
                self::SOURCE_SYSTEM,
                self::SOURCE_TABLE,
                $this->asOf,
                $lockedReport['source']['current_special_function_records_examined'],
                $lockedReport['source']['distinct_legacy_officers'],
                $lockedReport['source']['distinct_officers'],
                $lockedReport['source']['distinct_confirmed_ascs'],
            ]);

            $created = 0;
            $primarySynchronized = 0;
            foreach ($lockedReport['proposals'] as $proposal) {
                $officer = $this->lockedOfficer($proposal['target_officer_id']);
                if ($officer === null) {
                    throw new RuntimeException('A target Officer disappeared after preflight.');
                }

                if ($this->hasCurrentAssignment($proposal['target_officer_id'], $proposal['office_id'], true)) {
                    continue;
                }

                $hasPrimary = $this->hasCurrentPrimary($proposal['target_officer_id'], true)
                    || !empty($officer['primary_office_id']);
                $isPrimary = !$hasPrimary;
                $assignmentId = $this->uuid();
                $evidence = [
                    'record_origin' => 'LEGACY_CURRENT_STATE_BACKFILL',
                    'as_of' => $this->asOf,
                    'effective_date_provenance' => 'CURRENT_STATE_BACKFILL_AS_OF_NOT_HISTORICAL_JOINING_DATE',
                    'reconciled_special_function_records' => $proposal['reconciled_business_keys'],
                    'reconciliation_decision_ids' => $proposal['reconciliation_decision_ids'],
                    'reconciliation_item_ids' => $proposal['reconciliation_item_ids'],
                    'confirmed_asc_location_id' => $proposal['target_asc_id'],
                    'legacy_officer_ids' => $proposal['legacy_officer_ids'],
                    'target_officer_id' => $proposal['target_officer_id'],
                    'subject_function_types' => $proposal['functions'],
                    'source_references' => $proposal['source_references'],
                ];

                $insert = $this->target->prepare(
                    "INSERT INTO officer_office_assignment
                     (id,officer_id,office_id,effective_from,is_primary,active,record_origin,
                      source_system,source_table,source_evidence_json,legacy_backfill_run_id,
                      approval_status,reason,official_reference,remarks,approved_at,
                      approval_timestamp_provenance,version)
                     VALUES(?,?,?,?,?,1,'LEGACY_CURRENT_STATE_BACKFILL',?,?,?,?,
                            'APPROVED',?,?,?,NULL,'UNAVAILABLE_CURRENT_STATE_BACKFILL',0)"
                );
                $insert->execute([
                    $assignmentId,
                    $proposal['target_officer_id'],
                    $proposal['office_id'],
                    $this->asOf,
                    $isPrimary ? 1 : 0,
                    self::SOURCE_SYSTEM,
                    self::SOURCE_TABLE,
                    $this->json($evidence),
                    $runId,
                    'Current ASC Office membership confirmed by approved Legacy Migration Review decisions.',
                    'LEGACY_SPECIAL_FUNCTION_ASC_RECONCILIATION',
                    'No creator, approver, or historical Office joining date was inferred from legacy data.',
                ]);

                $state = $this->target->prepare('SELECT * FROM officer_office_assignment WHERE id=?');
                $state->execute([$assignmentId]);
                $audit = $this->target->prepare(
                    "INSERT INTO officer_office_assignment_audit
                     (assignment_id,action_key,new_state_json,reason,actor_user_id)
                     VALUES(?,'LEGACY_CURRENT_STATE_BACKFILLED',?,?,NULL)"
                );
                $audit->execute([
                    $assignmentId,
                    $this->json($state->fetch()),
                    'Controlled current-state backfill from confirmed reconciliation decisions; no actor identity was fabricated.',
                ]);

                if ($isPrimary) {
                    $sync = $this->target->prepare(
                        'UPDATE officer SET primary_office_id=? WHERE id=? AND primary_office_id IS NULL'
                    );
                    $sync->execute([$proposal['office_id'], $proposal['target_officer_id']]);
                    $primarySynchronized += $sync->rowCount();
                }
                $created++;
            }

            $complete = $this->target->prepare(
                "UPDATE legacy_arpa_office_backfill_run
                 SET status='COMPLETED',created_assignments=?,synchronized_primary_offices=?,completed_at=NOW()
                 WHERE id=?"
            );
            $complete->execute([$created, $primarySynchronized, $runId]);
            $this->target->commit();

            return [
                'run_id' => $runId,
                'created' => $created,
                'primary_synchronized' => $primarySynchronized,
                'preflight' => $preflight,
                'postflight' => $this->dryRun(),
            ];
        } catch (Throwable $exception) {
            if ($this->target->inTransaction()) {
                $this->target->rollBack();
            }
            throw $exception;
        }
    }

    private function analyse(): array
    {
        $rows = $this->currentSpecialFunctionRows();
        $offices = $this->activeAscOffices();
        $assignments = $this->currentAssignments();
        $officers = [];
        $legacyOfficers = [];
        $confirmedAscs = [];
        $excludedHistory = 0;
        $blockers = [];
        $validByOfficer = [];

        $byFunction = [];
        foreach (self::FUNCTIONS as $function) {
            $byFunction[$function] = $this->emptyBreakdown();
        }

        foreach ($rows as $row) {
            $key = (string) $row['reconciled_business_key'];
            $function = (string) $row['subject_kind'];
            $byFunction[$function]['records_examined']++;
            $byFunction[$function]['officers'][(string) $row['officer_id']] = true;
            $officers[(string) $row['officer_id']] = true;
            $legacyOfficers[(string) $row['legacy_officer_id']] = true;

            if ($this->preserveHistoryOnly($row)) {
                $excludedHistory++;
                $byFunction[$function]['preserve_history_only']++;
                continue;
            }

            if (!$this->currentlyEffective($row)) {
                $excludedHistory++;
                $byFunction[$function]['ended_or_not_current']++;
                continue;
            }

            if (!empty($row['current_conflict']) && !$this->currentConflictActivated($row)) {
                $this->block($blockers, $key, 'UNRESOLVED_CURRENT_CLASSIFICATION', $row);
                $byFunction[$function]['blockers']++;
                continue;
            }

            if (($row['resolution_status'] ?? null) !== 'CONFIRMED') {
                $this->block($blockers, $key, 'UNRESOLVED_ASC_DECISION', $row);
                $byFunction[$function]['unresolved_asc_decisions']++;
                $byFunction[$function]['blockers']++;
                continue;
            }

            $byFunction[$function]['confirmed_asc_decisions']++;
            $ascId = trim((string) ($row['selected_target_asc_id'] ?? ''));
            if ($ascId === '') {
                $this->block($blockers, $key, 'MISSING_TARGET_ASC', $row);
                $byFunction[$function]['missing_target_asc']++;
                $byFunction[$function]['blockers']++;
                continue;
            }
            if (empty($row['target_officer_exists'])) {
                $this->block($blockers, $key, 'MISSING_TARGET_OFFICER', $row);
                $byFunction[$function]['missing_target_officer']++;
                $byFunction[$function]['blockers']++;
                continue;
            }
            if (empty($row['asc_location_exists'])) {
                $this->block($blockers, $key, 'MISSING_OR_INACTIVE_TARGET_ASC', $row);
                $byFunction[$function]['missing_target_asc']++;
                $byFunction[$function]['blockers']++;
                continue;
            }
            if (!isset($offices[$ascId])) {
                $this->block($blockers, $key, 'MISSING_ACTIVE_APPROVED_ASC_OFFICE', $row);
                $byFunction[$function]['missing_target_asc_office']++;
                $byFunction[$function]['blockers']++;
                continue;
            }

            $confirmedAscs[$ascId] = true;
            $pairKey = $row['officer_id'] . '|' . $ascId;
            $byFunction[$function]['pairs'][$pairKey] = true;
            $validByOfficer[(string) $row['officer_id']][$ascId][] = $row;
        }

        $proposals = [];
        $alreadyAssigned = [];
        $multipleAscConflicts = [];
        $byAsc = [];
        $existingPrimaryReview = 0;

        foreach ($validByOfficer as $officerId => $byOfficerAsc) {
            if (count($byOfficerAsc) > 1) {
                $ascIds = array_keys($byOfficerAsc);
                $multipleAscConflicts[] = [
                    'target_officer_id' => $officerId,
                    'target_asc_ids' => $ascIds,
                    'reason' => 'MULTIPLE_CONFIRMED_CURRENT_ASC_OFFICES',
                ];
                $blockers['officer:' . $officerId] = [
                    'record_key' => 'officer:' . $officerId,
                    'reason' => 'MULTIPLE_CONFIRMED_CURRENT_ASC_OFFICES',
                    'target_officer_id' => $officerId,
                    'target_asc_ids' => $ascIds,
                ];
                foreach ($byOfficerAsc as $ascRows) {
                    foreach ($ascRows as $row) {
                        $byFunction[$row['subject_kind']]['multiple_asc_conflicts']++;
                    }
                }
                continue;
            }

            $ascId = (string) array_key_first($byOfficerAsc);
            $supportingRows = $byOfficerAsc[$ascId];
            $office = $offices[$ascId];
            $current = $assignments[$officerId] ?? [];
            $same = array_values(array_filter(
                $current,
                static fn(array $assignment): bool => $assignment['office_id'] === $office['id'],
            ));
            $primary = array_values(array_filter(
                $current,
                static fn(array $assignment): bool => (int) $assignment['is_primary'] === 1,
            ));
            $officerPrimaryOffice = $supportingRows[0]['primary_office_id'] ?? null;
            $sameIsPrimary = array_filter(
                $same,
                static fn(array $assignment): bool => (int) $assignment['is_primary'] === 1,
            ) !== [] || $officerPrimaryOffice === $office['id'];
            $differentPrimaryExists = ($primary !== [] || !empty($officerPrimaryOffice)) && !$sameIsPrimary;

            $functions = [];
            $businessKeys = [];
            $decisionIds = [];
            $itemIds = [];
            $legacyIds = [];
            $sourceReferences = [];
            foreach ($supportingRows as $row) {
                $functions[$row['subject_kind']] = true;
                $businessKeys[$row['reconciled_business_key']] = true;
                $decisionIds[$row['resolution_id']] = true;
                $itemIds[$row['reconciliation_item_id']] = true;
                $legacyIds[$row['legacy_officer_id']] = true;
                foreach ($this->decodeList($row['source_references_json']) as $reference) {
                    $sourceReferences[(string) $reference] = true;
                }
            }

            $summaryRow = [
                'target_officer_id' => $officerId,
                'officer_dad_number' => $supportingRows[0]['officer_dad_number'],
                'legacy_officer_ids' => array_keys($legacyIds),
                'target_asc_id' => $ascId,
                'asc_dad_number' => $office['asc_dad_number'],
                'asc_name' => $office['asc_name'],
                'office_id' => $office['id'],
                'office_dad_number' => $office['office_dad_number'],
                'office_name' => $office['office_name'],
                'functions' => array_keys($functions),
                'reconciled_business_keys' => array_keys($businessKeys),
                'reconciliation_decision_ids' => array_keys($decisionIds),
                'reconciliation_item_ids' => array_keys($itemIds),
                'source_references' => array_keys($sourceReferences),
                'supporting_record_count' => count($supportingRows),
                'primary_status' => $differentPrimaryExists
                    ? 'EXISTING_PRIMARY_REVIEW'
                    : ($sameIsPrimary ? 'EXISTING_PRIMARY_SAME_ASC' : 'SET_AS_PRIMARY'),
            ];

            $byAsc[$ascId] ??= [
                'asc_dad_number' => $office['asc_dad_number'],
                'asc_name' => $office['asc_name'],
                'office_dad_number' => $office['office_dad_number'],
                'records' => 0,
                'officers' => [],
                'already_assigned' => 0,
                'would_create' => 0,
                'existing_primary_review' => 0,
            ];
            $byAsc[$ascId]['records'] += count($supportingRows);
            $byAsc[$ascId]['officers'][$officerId] = true;
            if ($differentPrimaryExists) {
                $existingPrimaryReview++;
                $byAsc[$ascId]['existing_primary_review']++;
                foreach ($functions as $function => $_) {
                    $byFunction[$function]['existing_primary_review']++;
                }
            }

            if ($same !== []) {
                $summaryRow['status'] = 'ALREADY_ASSIGNED';
                $alreadyAssigned[] = $summaryRow;
                $byAsc[$ascId]['already_assigned']++;
                foreach ($functions as $function => $_) {
                    $byFunction[$function]['already_assigned']++;
                }
                continue;
            }

            $summaryRow['status'] = 'WOULD_CREATE';
            $proposals[] = $summaryRow;
            $byAsc[$ascId]['would_create']++;
            foreach ($functions as $function => $_) {
                $byFunction[$function]['would_create']++;
            }
        }

        foreach ($byFunction as &$function) {
            $function['distinct_officers'] = count($function['officers']);
            $function['distinct_officer_asc_pairs'] = count($function['pairs']);
            unset($function['officers'], $function['pairs']);
        }
        unset($function);

        foreach ($byAsc as &$asc) {
            $asc['distinct_officers'] = count($asc['officers']);
            unset($asc['officers']);
        }
        unset($asc);
        uasort($byAsc, static fn(array $a, array $b): int =>
            [$a['asc_dad_number'], $a['asc_name']] <=> [$b['asc_dad_number'], $b['asc_name']]
        );

        $confirmedRecords = array_sum(array_column($byFunction, 'confirmed_asc_decisions'));
        $unresolvedRecords = array_sum(array_column($byFunction, 'unresolved_asc_decisions'));
        $missingOfficer = array_sum(array_column($byFunction, 'missing_target_officer'));
        $missingAsc = array_sum(array_column($byFunction, 'missing_target_asc'));
        $missingOffice = array_sum(array_column($byFunction, 'missing_target_asc_office'));
        $pairCount = array_sum(array_column($byFunction, 'distinct_officer_asc_pairs'));
        $confirmedOfficeBlockers = $missingOfficer + $missingAsc + $missingOffice
            + count($multipleAscConflicts);

        return [
            'generated_at' => date(DATE_ATOM),
            'as_of' => $this->asOf,
            'source_policy' => 'CONFIRMED Legacy Migration Review decisions only; legacy appoint_location_id is not read',
            'source' => [
                'current_special_function_records_examined' => count($rows),
                'confirmed_asc_decisions' => $confirmedRecords,
                'unresolved_asc_decisions' => $unresolvedRecords,
                'preserve_history_only_excluded' => $excludedHistory,
                'distinct_legacy_officers' => count($legacyOfficers),
                'distinct_officers' => count($officers),
                'distinct_officer_asc_pairs' => $pairCount,
                'distinct_confirmed_ascs' => count($confirmedAscs),
            ],
            'existing' => [
                'already_assigned' => count($alreadyAssigned),
                'existing_primary_review' => $existingPrimaryReview,
            ],
            'blocker_breakdown' => [
                'unresolved_asc_decisions' => $unresolvedRecords,
                'multiple_asc_conflicts' => count($multipleAscConflicts),
                'missing_target_officer' => $missingOfficer,
                'missing_target_asc' => $missingAsc,
                'missing_target_asc_office' => $missingOffice,
                'unresolved_confirmed_record_office_blockers' => $confirmedOfficeBlockers,
            ],
            'projection' => [
                'distinct_office_assignments_required' => count($proposals),
                'would_create' => count($proposals),
                'would_update' => 0,
                'would_synchronize_primary_office' => count(array_filter(
                    $proposals,
                    static fn(array $proposal): bool => $proposal['primary_status'] === 'SET_AS_PRIMARY',
                )),
                'true_execution_blockers' => count($blockers),
            ],
            'by_function' => $byFunction,
            'by_asc' => array_values($byAsc),
            'proposals' => $proposals,
            'already_assigned' => $alreadyAssigned,
            'multiple_asc_conflicts' => $multipleAscConflicts,
            'blockers' => array_values($blockers),
        ];
    }

    private function currentSpecialFunctionRows(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::FUNCTIONS), '?'));
        $sql = "SELECT
                    p.reconciled_business_key,p.officer_id,p.legacy_officer_id,p.subject_kind,
                    p.effective_from,p.effective_to,p.current_classification,p.current_conflict,
                    p.source_references_json,
                    i.id reconciliation_item_id,i.primary_source_table,i.primary_source_record_id,
                    r.id resolution_id,r.resolution_status,r.resolution_type,
                    r.selected_target_asc_id,r.activation_decision,
                    ci.id conflict_item_id,cr.resolution_status conflict_resolution_status,
                    cr.activation_decision conflict_activation_decision,
                    o.id target_officer_exists,o.dad_number officer_dad_number,o.primary_office_id,
                    asc_location.id asc_location_exists
                FROM legacy_arpa_appointment_preview p
                LEFT JOIN legacy_arpa_reconciliation_item i
                  ON i.reconciled_business_key=p.reconciled_business_key
                 AND i.item_type='SPECIAL_ASC' AND i.active=1
                LEFT JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id
                LEFT JOIN legacy_arpa_reconciliation_item ci
                  ON ci.reconciled_business_key=p.reconciled_business_key
                 AND ci.item_type='CURRENT_CONFLICT' AND ci.active=1
                LEFT JOIN legacy_arpa_appointment_resolution cr ON cr.reconciliation_item_id=ci.id
                LEFT JOIN officer o ON o.id=p.officer_id
                LEFT JOIN location asc_location
                  ON asc_location.id=r.selected_target_asc_id
                 AND asc_location.operational_status='ACTIVE'
                 AND asc_location.approval_status='APPROVED'
                 AND asc_location.effective_from<=?
                 AND (asc_location.effective_to IS NULL OR asc_location.effective_to>=?)
                 AND EXISTS(
                     SELECT 1 FROM location_type lt
                     WHERE lt.id=asc_location.location_type_id AND lt.system_key='ASC'
                 )
                WHERE p.active=1
                  AND p.assignment_category='ASC_FUNCTION'
                  AND p.current_classification='CURRENT'
                  AND p.subject_kind IN ({$placeholders})
                ORDER BY p.subject_kind,p.officer_id,p.reconciled_business_key";
        $statement = $this->target->prepare($sql);
        $statement->execute([$this->asOf, $this->asOf, ...self::FUNCTIONS]);
        return $statement->fetchAll();
    }

    private function activeAscOffices(): array
    {
        $statement = $this->target->prepare(
            "SELECT o.id,o.dad_number office_dad_number,o.name_en office_name,
                    o.linked_location_id,l.dad_number asc_dad_number,l.name_en asc_name
             FROM office o
             JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE'
             JOIN location l ON l.id=o.linked_location_id
             JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC'
             WHERE o.operational_status='ACTIVE' AND o.approval_status='APPROVED'
               AND o.effective_from<=? AND (o.effective_to IS NULL OR o.effective_to>=?)
               AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED'
               AND l.effective_from<=? AND (l.effective_to IS NULL OR l.effective_to>=?)"
        );
        $statement->execute([$this->asOf, $this->asOf, $this->asOf, $this->asOf]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['linked_location_id']] = $row;
        }
        return $result;
    }

    private function currentAssignments(): array
    {
        $statement = $this->target->prepare(
            "SELECT a.officer_id,a.office_id,a.is_primary,o.dad_number office_dad_number,
                    o.name_en office_name,ot.system_key office_type_key
             FROM officer_office_assignment a
             JOIN office o ON o.id=a.office_id
             JOIN office_type ot ON ot.id=o.office_type_id
             WHERE a.active=1 AND a.approval_status='APPROVED'
               AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>=?)"
        );
        $statement->execute([$this->asOf, $this->asOf]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['officer_id']][] = $row;
        }
        return $result;
    }

    private function lockInputs(): void
    {
        $this->target->query(
            "SELECT i.id
             FROM legacy_arpa_reconciliation_item i
             JOIN legacy_arpa_appointment_preview p
               ON p.reconciled_business_key=i.reconciled_business_key
              AND p.active=1 AND p.assignment_category='ASC_FUNCTION'
              AND p.current_classification='CURRENT'
             LEFT JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id
             WHERE i.active=1 AND i.item_type IN('SPECIAL_ASC','CURRENT_CONFLICT')
             FOR UPDATE"
        )->fetchAll();
        $this->target->query('SELECT id FROM officer_office_assignment FOR UPDATE')->fetchAll();
    }

    private function lockedOfficer(string $officerId): ?array
    {
        $statement = $this->target->prepare('SELECT id,primary_office_id FROM officer WHERE id=? FOR UPDATE');
        $statement->execute([$officerId]);
        return $statement->fetch() ?: null;
    }

    private function hasCurrentAssignment(string $officerId, string $officeId, bool $lock): bool
    {
        $sql = "SELECT id FROM officer_office_assignment
                WHERE officer_id=? AND office_id=? AND active=1 AND approval_status='APPROVED'
                  AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?)
                LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
        $statement = $this->target->prepare($sql);
        $statement->execute([$officerId, $officeId, $this->asOf, $this->asOf]);
        return (bool) $statement->fetchColumn();
    }

    private function hasCurrentPrimary(string $officerId, bool $lock): bool
    {
        $sql = "SELECT id FROM officer_office_assignment
                WHERE officer_id=? AND is_primary=1 AND active=1 AND approval_status='APPROVED'
                  AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?)
                LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
        $statement = $this->target->prepare($sql);
        $statement->execute([$officerId, $this->asOf, $this->asOf]);
        return (bool) $statement->fetchColumn();
    }

    private function currentlyEffective(array $row): bool
    {
        if (empty($row['effective_from'])) {
            return false;
        }
        return $row['effective_from'] <= $this->asOf
            && (empty($row['effective_to']) || $row['effective_to'] >= $this->asOf);
    }

    private function preserveHistoryOnly(array $row): bool
    {
        return (($row['resolution_status'] ?? null) === 'CONFIRMED'
                && ($row['activation_decision'] ?? null) === 'PRESERVE_HISTORY_ONLY')
            || (($row['conflict_resolution_status'] ?? null) === 'CONFIRMED'
                && ($row['conflict_activation_decision'] ?? null) === 'PRESERVE_HISTORY_ONLY');
    }

    private function currentConflictActivated(array $row): bool
    {
        return ($row['conflict_resolution_status'] ?? null) === 'CONFIRMED'
            && ($row['conflict_activation_decision'] ?? null) === 'ACTIVATE_CURRENT';
    }

    private function block(array &$blockers, string $key, string $reason, array $row): void
    {
        $blockers[$key] = [
            'record_key' => $key,
            'reason' => $reason,
            'function' => $row['subject_kind'] ?? null,
            'legacy_officer_id' => $row['legacy_officer_id'] ?? null,
            'target_officer_id' => $row['officer_id'] ?? null,
            'reconciliation_item_id' => $row['reconciliation_item_id'] ?? null,
            'reconciliation_decision_id' => $row['resolution_id'] ?? null,
            'selected_target_asc_id' => $row['selected_target_asc_id'] ?? null,
        ];
    }

    private function emptyBreakdown(): array
    {
        return [
            'records_examined' => 0,
            'confirmed_asc_decisions' => 0,
            'unresolved_asc_decisions' => 0,
            'preserve_history_only' => 0,
            'ended_or_not_current' => 0,
            'missing_target_officer' => 0,
            'missing_target_asc' => 0,
            'missing_target_asc_office' => 0,
            'multiple_asc_conflicts' => 0,
            'already_assigned' => 0,
            'would_create' => 0,
            'existing_primary_review' => 0,
            'blockers' => 0,
            'officers' => [],
            'pairs' => [],
        ];
    }

    private function decodeList(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function writeReports(array $report): array
    {
        $directory = dirname(__DIR__, 3) . '/storage/reports';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the migration report directory.');
        }
        $stamp = date('Ymd-His');
        $base = $directory . '/special-function-office-backfill-dry-run-' . $stamp;
        $jsonPath = $base . '.json';
        $functionPath = $base . '-by-function.csv';
        $ascPath = $base . '-by-asc.csv';
        $blockerPath = $base . '-blockers.csv';
        file_put_contents(
            $jsonPath,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );

        $this->writeCsv($functionPath, [
            'function', 'records_examined', 'confirmed_asc_decisions', 'unresolved_asc_decisions',
            'distinct_officers', 'distinct_officer_asc_pairs', 'already_assigned', 'would_create',
            'existing_primary_review', 'blockers',
        ], array_map(static fn(string $function, array $counts): array => [
            $function,
            $counts['records_examined'],
            $counts['confirmed_asc_decisions'],
            $counts['unresolved_asc_decisions'],
            $counts['distinct_officers'],
            $counts['distinct_officer_asc_pairs'],
            $counts['already_assigned'],
            $counts['would_create'],
            $counts['existing_primary_review'] ?? 0,
            $counts['blockers'],
        ], array_keys($report['by_function']), array_values($report['by_function'])));

        $this->writeCsv($ascPath, [
            'asc_dad_number', 'asc_name', 'office_dad_number', 'records', 'distinct_officers',
            'already_assigned', 'would_create', 'existing_primary_review',
        ], array_map(static fn(array $asc): array => [
            $asc['asc_dad_number'], $asc['asc_name'], $asc['office_dad_number'], $asc['records'],
            $asc['distinct_officers'], $asc['already_assigned'], $asc['would_create'],
            $asc['existing_primary_review'],
        ], $report['by_asc']));

        $this->writeCsv($blockerPath, [
            'record_key', 'reason', 'function', 'legacy_officer_id', 'target_officer_id',
            'reconciliation_item_id', 'reconciliation_decision_id', 'selected_target_asc_id',
        ], array_map(static fn(array $blocker): array => [
            $blocker['record_key'] ?? null,
            $blocker['reason'] ?? null,
            $blocker['function'] ?? null,
            $blocker['legacy_officer_id'] ?? null,
            $blocker['target_officer_id'] ?? null,
            $blocker['reconciliation_item_id'] ?? null,
            $blocker['reconciliation_decision_id'] ?? null,
            $blocker['selected_target_asc_id'] ?? null,
        ], $report['blockers']));

        return [
            'json' => $jsonPath,
            'by_function_csv' => $functionPath,
            'by_asc_csv' => $ascPath,
            'blockers_csv' => $blockerPath,
        ];
    }

    private function writeCsv(string $path, array $header, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create CSV report: ' . $path);
        }
        fputcsv($handle, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);
    }

    private function targetState(): array
    {
        $result = [];
        foreach ([
            'officer_office_assignment',
            'officer_office_assignment_audit',
            'legacy_arpa_office_backfill_run',
            'arpa_division_appointment',
            'arpa_subject_assignment',
            'arpa_officer_sub_designation_period',
            'system_user',
        ] as $table) {
            $result[$table] = (int) $this->target->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }
        return $result;
    }

    private function assertDate(string $value): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('As-of date must be YYYY-MM-DD.');
        }
    }

    private function uuid(): string
    {
        return (string) $this->target->query('SELECT UUID()')->fetchColumn();
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
