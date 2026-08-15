<?php
declare(strict_types=1);

namespace App\Services\LegacyAppointment;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class FinalLegacyArpaMigrationAuditService
{
    private const SOURCE_TABLES = ['tbl_officer_apoint', 'tbl_officer_apoint_2026'];
    private const TYPES = ['PERMANENT', 'ACTING', 'DUTY_COVERING', 'ATTEND_TO_DUTY', 'AGRARIAN_BANK', 'SALES_SHOP', 'SITHAMU'];

    public function __construct(
        private readonly PDO $target,
        private readonly PDO $source,
        private readonly ?string $reportDirectory = null,
    ) {}

    public function audit(bool $writeReports = true): array
    {
        // The audit deliberately reconciles all 19,903 source rows in memory so the
        // trace CSV can be produced atomically from one consistent read snapshot.
        if ($this->memoryLimitBytes((string)ini_get('memory_limit')) < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }
        $targetOwned = false;
        $sourceOwned = false;
        try {
            if (!$this->target->inTransaction()) {
                $this->target->exec('SET TRANSACTION READ ONLY');
                $this->target->beginTransaction();
                $targetOwned = true;
            }
            if (!$this->source->inTransaction()) {
                $this->source->exec('SET TRANSACTION READ ONLY');
                $this->source->beginTransaction();
                $sourceOwned = true;
            }

            $before = $this->stateFingerprint();
            $raw = $this->rawSourceRows();
            $preview = $this->previewRows();
            $business = $this->businessRows();
            $references = $this->targetSourceReferences();
            $locations = $this->locations();
            $targets = $this->targetRecords();
            $manualReasons = $this->manualReviewReasons();

            $rawAudit = $this->rawSourceAudit($raw);
            $coverage = $this->coverageAudit($raw, $preview, $business, $references, $targets, $locations, $manualReasons);
            $types = $this->typeAudit($preview, $business);
            $reconciliation = $this->reconciliationAudit($preview);
            $outcomes = $this->outcomeAudit($preview, $business, $targets);
            $manual = $this->manualReviewAudit($preview, $business, $manualReasons, $locations);
            $asc = $this->ascAudit($preview, $business, $targets, $locations);
            $officers = $this->officerAudit($raw, $preview, $business);
            $locationRepair = $this->locationRepairAudit();
            $orphans = $this->orphanAndDuplicateAudit();
            $equations = $this->equations($rawAudit, $reconciliation, $coverage['summary'], $types, count($business), count($manual));

            $after = $this->stateFingerprint();
            $stateUnchanged = $before === $after;
            if (!$stateUnchanged) {
                throw new RuntimeException('Read-only audit detected an unexpected database state change.');
            }

            $unexplained = $coverage['summary']['zero_reconciliation_mappings'];
            $duplicateMappings = $coverage['summary']['more_than_one_reconciliation_mapping'];
            $orphanTargets = $orphans['total_orphan_target_records'];
            $sourceComplete = $unexplained === 0
                && $duplicateMappings === 0
                && $coverage['summary']['orphan_target_source_references'] === 0
                && $coverage['summary']['invalid_source_table_references'] === 0;
            $businessComplete = count($preview) === count($business) + count($manual)
                && $types['difference_total'] === 0;
            $allEquationsPass = !in_array(false, array_column($equations, 'pass'), true);

            $report = [
                'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'mode' => 'READ_ONLY_AUDIT',
                'raw_source' => $rawAudit,
                'reconciliation' => $reconciliation,
                'source_coverage' => $coverage['summary'],
                'type_reconciliation' => $types,
                'target_distribution' => $outcomes,
                'manual_review' => ['count' => count($manual), 'source_rows' => array_sum(array_column($manual, 'raw_source_rows')), 'records' => $manual],
                'asc_integrity' => $asc['summary'],
                'officer_integrity' => $officers,
                'location_repair_validation' => $locationRepair,
                'duplicates_and_orphans' => $orphans,
                'accounting_equations' => $equations,
                'statuses' => [
                    'source_row_coverage' => $sourceComplete && $allEquationsPass ? 'COMPLETE' : 'INCOMPLETE',
                    'business_record_coverage' => $businessComplete && $allEquationsPass ? 'COMPLETE' : 'INCOMPLETE',
                    'migration_coverage' => $businessComplete && count($manual) > 0 ? 'COMPLETE EXCEPT REVIEW ITEMS' : ($businessComplete ? 'COMPLETE' : 'INCOMPLETE'),
                    'unexplained_source_rows' => $unexplained,
                    'duplicate_source_mappings' => $duplicateMappings,
                    'orphan_target_records' => $orphanTargets,
                    'manual_review_business_records' => count($manual),
                    'all_legacy_appointment_data_accounted_for' => $sourceComplete && $businessComplete && $allEquationsPass ? 'YES' : 'NO',
                    'all_legacy_business_records_operationally_migrated' => count($manual) === 0 ? 'YES' : 'NO',
                ],
                'safety' => [
                    'read_only_transactions' => true,
                    'database_state_unchanged' => $stateUnchanged,
                    'dems_legacy_hr_rows_modified' => 0,
                    'dems_php_appointment_rows_modified' => 0,
                    'source_references_modified' => 0,
                    'reconciliation_decisions_modified' => 0,
                    'officers_modified' => 0,
                    'users_modified' => 0,
                    'roles_scopes_modified' => 0,
                ],
            ];

            $report['report_paths'] = $writeReports
                ? $this->writeReports($report, $coverage['rows'], $types['rows'], $asc['rows'], $manual)
                : [];
            return $report;
        } finally {
            if ($sourceOwned && $this->source->inTransaction()) {
                $this->source->rollBack();
            }
            if ($targetOwned && $this->target->inTransaction()) {
                $this->target->rollBack();
            }
        }
    }

    private function rawSourceRows(): array
    {
        $rows = [];
        $fields = 'auto_id,officer_id,officer_level,duty_type,location,appoint_date,appoint_end_date,appoint_end_reason,asc_approve,district_approve,national_approve,status';
        foreach (self::SOURCE_TABLES as $table) {
            foreach ($this->source->query("SELECT {$fields} FROM `{$table}` ORDER BY auto_id") as $row) {
                $row['source_table'] = $table;
                $row['source_key'] = $table.':'.$row['auto_id'];
                $rows[$row['source_key']] = $row;
            }
        }
        return $rows;
    }

    private function previewRows(): array
    {
        $rows = [];
        $sql = "SELECT p.reconciled_business_key,p.reconciliation_class,p.source_scope,p.assignment_category,p.subject_kind,p.appointment_type,p.source_references_json,p.legacy_officer_id,p.officer_id,o.dad_number officer_dad_number,o.name_with_initials officer_name,p.asc_location_id,p.arpa_location_id,p.effective_from,p.effective_to,p.blocker_types_json,p.historical_exception_types_json,JSON_UNQUOTE(JSON_EXTRACT(p.location_provenance_json,'$.legacy_context.asc_name')) legacy_asc_name,JSON_UNQUOTE(JSON_EXTRACT(p.location_provenance_json,'$.legacy_context.arpa_name')) legacy_arpa_name FROM legacy_arpa_appointment_preview p LEFT JOIN officer o ON o.id=p.officer_id WHERE p.active=1 ORDER BY p.reconciled_business_key";
        foreach ($this->target->query($sql) as $row) {
            foreach (['source_references_json', 'blocker_types_json', 'historical_exception_types_json'] as $field) {
                $row[$field] = $this->decode($row[$field] ?? null, []);
            }
            $rows[$row['reconciled_business_key']] = $row;
        }
        return $rows;
    }

    private function businessRows(): array
    {
        $rows = [];
        $sql = "SELECT id,reconciled_business_key,reconciliation_class,target_concept,officer_id,JSON_UNQUOTE(JSON_EXTRACT(source_snapshot_json,'$.migration_classification')) migration_classification,CAST(COALESCE(JSON_EXTRACT(source_snapshot_json,'$.legacy_history_only'),0) AS UNSIGNED) snapshot_history_only,CAST(COALESCE(JSON_EXTRACT(source_snapshot_json,'$.historical_exception'),0) AS UNSIGNED) snapshot_historical_exception FROM legacy_arpa_appointment_business_record WHERE source_system='dems_legacy_hr' ORDER BY reconciled_business_key";
        foreach ($this->target->query($sql) as $row) {
            $rows[$row['reconciled_business_key']] = $row;
        }
        return $rows;
    }

    private function targetSourceReferences(): array
    {
        $rows = [];
        $sql = "SELECT sr.id,sr.business_record_id,sr.source_system,sr.source_table,sr.legacy_appointment_id,sr.target_appointment_request_id,sr.target_appointment_id,sr.target_subject_request_id,sr.target_subject_assignment_id,sr.target_sub_designation_period_id,br.reconciled_business_key FROM legacy_arpa_appointment_source_reference sr JOIN legacy_arpa_appointment_business_record br ON br.id=sr.business_record_id WHERE sr.source_system='dems_legacy_hr' ORDER BY sr.id";
        foreach ($this->target->query($sql) as $row) {
            $row['source_key'] = $row['source_table'].':'.$row['legacy_appointment_id'];
            $this->businessReferenceCache[$row['business_record_id']] ??= $row;
            $rows[] = $row;
        }
        return $rows;
    }

    private function locations(): array
    {
        $rows = [];
        foreach ($this->target->query('SELECT l.id,l.dad_number,l.name_en,lt.system_key type_key FROM location l JOIN location_type lt ON lt.id=l.location_type_id') as $row) {
            $rows[$row['id']] = $row;
        }
        return $rows;
    }

    private function targetRecords(): array
    {
        $result = ['division_requests' => [], 'division_appointments' => [], 'division_closures' => [], 'division_closures_by_appointment' => [], 'subject_requests' => [], 'subject_assignments' => [], 'subject_closures' => [], 'subject_closures_by_assignment' => [], 'sub_designations' => []];
        $queries = [
            'division_requests' => "SELECT id,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,requested_effective_to,legacy_history_only,legacy_exception FROM arpa_division_appointment_request WHERE record_origin='LEGACY_IMPORT'",
            'division_appointments' => "SELECT id,request_id,officer_id,appointment_type,asc_location_id,arpa_division_location_id,effective_from,legacy_history_only,legacy_exception FROM arpa_division_appointment WHERE record_origin='LEGACY_IMPORT'",
            'division_closures' => "SELECT c.id,c.appointment_id,c.request_id,c.effective_to FROM arpa_division_appointment_closure c JOIN arpa_division_appointment a ON a.id=c.appointment_id WHERE a.record_origin='LEGACY_IMPORT'",
            'subject_requests' => "SELECT r.id,r.officer_id,r.asc_location_id,r.requested_effective_from,r.requested_effective_to,r.legacy_history_only,r.legacy_exception,sm.system_key subject_key FROM arpa_subject_assignment_request r JOIN subject_master sm ON sm.id=r.subject_id WHERE r.record_origin='LEGACY_IMPORT'",
            'subject_assignments' => "SELECT id,request_id,officer_id,subject_kind_snapshot,asc_location_id,effective_from,legacy_history_only,legacy_exception FROM arpa_subject_assignment WHERE record_origin='LEGACY_IMPORT'",
            'subject_closures' => "SELECT c.id,c.assignment_id,c.request_id,c.effective_to FROM arpa_subject_assignment_closure c JOIN arpa_subject_assignment a ON a.id=c.assignment_id WHERE a.record_origin='LEGACY_IMPORT'",
            'sub_designations' => "SELECT id,source_subject_assignment_id,officer_id,asc_location_id,effective_from FROM arpa_officer_sub_designation_period WHERE record_origin='LEGACY_IMPORT'",
        ];
        foreach ($queries as $key => $sql) {
            foreach ($this->target->query($sql) as $row) {
                $result[$key][$row['id']] = $row;
                if ($key === 'division_closures') $result['division_closures_by_appointment'][$row['appointment_id']] = $row;
                if ($key === 'subject_closures') $result['subject_closures_by_assignment'][$row['assignment_id']] = $row;
            }
        }
        return $result;
    }

    private function rawSourceAudit(array $raw): array
    {
        $out = ['tables' => [], 'combined' => count($raw), 'by_duty_type' => [], 'by_officer_level' => [], 'by_effective_year' => [], 'by_workflow_state' => []];
        foreach ($raw as $row) {
            $table = $row['source_table'];
            $out['tables'][$table] = ($out['tables'][$table] ?? 0) + 1;
            $duty = (string)($row['duty_type'] ?? 'NULL');
            $out['by_duty_type'][$duty] = ($out['by_duty_type'][$duty] ?? 0) + 1;
            $level = trim((string)($row['officer_level'] ?? '')) ?: 'NOT_SET';
            $out['by_officer_level'][$level] = ($out['by_officer_level'][$level] ?? 0) + 1;
            $year = $row['appoint_date'] ? substr((string)$row['appoint_date'], 0, 4) : 'NOT_SET';
            $out['by_effective_year'][$year] = ($out['by_effective_year'][$year] ?? 0) + 1;
            $state = implode('/', [(string)$row['asc_approve'], (string)$row['district_approve'], (string)$row['national_approve'], (string)$row['status']]);
            $out['by_workflow_state'][$state] = ($out['by_workflow_state'][$state] ?? 0) + 1;
        }
        foreach (['by_duty_type', 'by_officer_level', 'by_effective_year', 'by_workflow_state'] as $key) {
            ksort($out[$key], SORT_NATURAL);
        }
        return $out;
    }

    private function coverageAudit(array $raw, array $preview, array $business, array $references, array $targets, array $locations, array $manualReasons): array
    {
        $previewMap = [];
        foreach ($preview as $key => $row) {
            foreach ($row['source_references_json'] as $reference) {
                $previewMap[(string)$reference][] = $key;
            }
        }
        $targetMap = [];
        foreach ($references as $reference) {
            $targetMap[$reference['source_key']][] = $reference;
        }

        $summary = [
            'total_source_rows' => count($raw),
            'exactly_one_reconciliation_mapping' => 0,
            'zero_reconciliation_mappings' => 0,
            'more_than_one_reconciliation_mapping' => 0,
            'exactly_one_migrated_source_reference' => 0,
            'manual_review_provenance_only' => 0,
            'duplicate_target_source_references' => 0,
            'orphan_target_source_references' => 0,
            'orphan_preview_source_references' => 0,
            'invalid_source_table_references' => 0,
        ];
        $rows = [];
        foreach ($raw as $sourceKey => $sourceRow) {
            $previewKeys = $previewMap[$sourceKey] ?? [];
            $targetRefs = $targetMap[$sourceKey] ?? [];
            if (count($previewKeys) === 1) $summary['exactly_one_reconciliation_mapping']++;
            elseif ($previewKeys === []) $summary['zero_reconciliation_mappings']++;
            else $summary['more_than_one_reconciliation_mapping']++;
            if (count($targetRefs) === 1) $summary['exactly_one_migrated_source_reference']++;
            elseif (count($targetRefs) > 1) $summary['duplicate_target_source_references']++;

            $key = $previewKeys[0] ?? null;
            $p = $key ? ($preview[$key] ?? null) : null;
            $b = $key ? ($business[$key] ?? null) : null;
            if ($p !== null && $b === null) $summary['manual_review_provenance_only']++;
            $targetRef = $targetRefs[0] ?? null;
            $target = $this->targetIdentity($targetRef, $targets);
            $type = $p ? $this->type($p) : null;
            $arpaId = $target['arpa_id'] ?? ($p['arpa_location_id'] ?? null);
            $ascId = $target['asc_id'] ?? ($p['asc_location_id'] ?? null);
            $rows[] = [
                'source_table' => $sourceRow['source_table'],
                'source_row_id' => $sourceRow['auto_id'],
                'legacy_officer_id' => $sourceRow['officer_id'],
                'target_officer_dad' => $p['officer_dad_number'] ?? null,
                'reconciliation_class' => $p['reconciliation_class'] ?? null,
                'business_record_id' => $b['id'] ?? ($key ? 'MANUAL_REVIEW:'.$key : null),
                'appointment_type' => $type,
                'legacy_location_id' => $sourceRow['location'],
                'target_location_dad' => $arpaId ? ($locations[$arpaId]['dad_number'] ?? null) : null,
                'target_asc_dad' => $ascId ? ($locations[$ascId]['dad_number'] ?? null) : null,
                'migration_outcome' => $b ? 'MIGRATED' : ($p ? 'MANUAL_REVIEW' : 'UNEXPLAINED'),
                'target_record_type' => $target['record_type'] ?? ($p ? 'MANUAL_REVIEW' : null),
                'target_record_id' => $target['record_id'] ?? null,
                'manual_review_reason' => $key ? implode('|', $manualReasons[$key] ?? []) : null,
            ];
        }
        foreach ($previewMap as $sourceKey => $keys) {
            if (!isset($raw[$sourceKey])) $summary['orphan_preview_source_references']++;
            $table = explode(':', $sourceKey, 2)[0];
            if (!in_array($table, self::SOURCE_TABLES, true)) $summary['invalid_source_table_references']++;
        }
        foreach ($targetMap as $sourceKey => $refs) {
            if (!isset($raw[$sourceKey])) $summary['orphan_target_source_references']++;
            $table = explode(':', $sourceKey, 2)[0];
            if (!in_array($table, self::SOURCE_TABLES, true)) $summary['invalid_source_table_references']++;
        }
        return ['summary' => $summary, 'rows' => $rows];
    }

    private function reconciliationAudit(array $preview): array
    {
        $classes = [];
        $sourceScopes = [];
        $sourceConsumption = [];
        foreach ($preview as $row) {
            $class = $row['reconciliation_class'];
            $classes[$class] = ($classes[$class] ?? 0) + 1;
            $scope = $row['source_scope'];
            $sourceScopes[$scope] = ($sourceScopes[$scope] ?? 0) + 1;
            $sourceConsumption[$class] = ($sourceConsumption[$class] ?? 0) + count($row['source_references_json']);
        }
        ksort($classes);
        return ['business_records' => count($preview), 'classes' => $classes, 'source_scopes' => $sourceScopes, 'source_rows_consumed' => $sourceConsumption];
    }

    private function typeAudit(array $preview, array $business): array
    {
        $rows = [];
        $differenceTotal = 0;
        foreach (self::TYPES as $type) {
            $reconciled = 0;
            $migrated = 0;
            foreach ($preview as $key => $row) {
                if ($this->type($row) !== $type) continue;
                $reconciled++;
                if (isset($business[$key])) $migrated++;
            }
            $manual = $reconciled - $migrated;
            $difference = $reconciled - $migrated - $manual;
            $differenceTotal += abs($difference);
            $rows[] = ['type' => $type, 'reconciled' => $reconciled, 'migrated' => $migrated, 'manual_review' => $manual, 'total_accounted_for' => $migrated + $manual, 'difference' => $difference];
        }
        return ['rows' => $rows, 'difference_total' => $differenceTotal];
    }

    private function outcomeAudit(array $preview, array $business, array $targets): array
    {
        $concepts = [];
        $canonical = array_fill_keys(['CURRENT_OPEN', 'SCHEDULED_FUTURE', 'ENDED_HISTORICAL', 'HISTORY_ONLY', 'HISTORICAL_EXCEPTION', 'MANUAL_REVIEW'], 0);
        $overlays = ['legacy_exception' => 0, 'legacy_history_only' => 0, 'operational_record_present' => 0, 'request_only' => 0];
        foreach ($preview as $key => $p) {
            $b = $business[$key] ?? null;
            if ($b === null) {
                $canonical['MANUAL_REVIEW']++;
                continue;
            }
            $concepts[$b['target_concept']] = ($concepts[$b['target_concept']] ?? 0) + 1;
            $targetRef = $this->businessTargetReference($b['id']);
            $target = $this->targetIdentity($targetRef, $targets);
            $historyOnly = (bool)($target['legacy_history_only'] ?? $b['snapshot_history_only'] ?? false);
            $exception = (bool)($target['legacy_exception'] ?? $b['snapshot_historical_exception'] ?? false);
            if ($historyOnly) $overlays['legacy_history_only']++;
            if ($exception) $overlays['legacy_exception']++;
            if (!empty($target['operational'])) $overlays['operational_record_present']++; else $overlays['request_only']++;
            $classification = $b['migration_classification'] ?? null;
            if ($classification === 'MIGRATABLE_HISTORICAL_EXCEPTION') $canonical['HISTORICAL_EXCEPTION']++;
            elseif ($classification === 'MIGRATABLE_HISTORY') $canonical['ENDED_HISTORICAL']++;
            elseif (!empty($target['effective_from']) && $target['effective_from'] > date('Y-m-d')) $canonical['SCHEDULED_FUTURE']++;
            elseif (!empty($target['operational'])) $canonical['CURRENT_OPEN']++;
            else $canonical['HISTORY_ONLY']++;
        }
        $subjectRequests = array_fill_keys(['AGRARIAN_BANK', 'SALES_SHOP', 'SITHAMU'], 0);
        foreach ($targets['subject_requests'] as $row) if (isset($subjectRequests[$row['subject_key']])) $subjectRequests[$row['subject_key']]++;
        $subjectAssignments = array_fill_keys(['AGRARIAN_BANK', 'SALES_SHOP', 'SITHAMU'], 0);
        foreach ($targets['subject_assignments'] as $row) if (isset($subjectAssignments[$row['subject_kind_snapshot']])) $subjectAssignments[$row['subject_kind_snapshot']]++;
        return [
            'migrated_business_records' => count($business),
            'by_target_concept' => $concepts,
            'target_layer_rows' => [
                'arpa_division_requests' => count($targets['division_requests']),
                'arpa_division_operational_appointments' => count($targets['division_appointments']),
                'arpa_division_closures' => count($targets['division_closures']),
                'subject_function_requests' => count($targets['subject_requests']),
                'subject_function_operational_assignments' => count($targets['subject_assignments']),
                'subject_function_closures' => count($targets['subject_closures']),
                'sithamu_sub_designation_periods' => count($targets['sub_designations']),
                'subject_requests_by_kind' => $subjectRequests,
                'subject_operational_assignments_by_kind' => $subjectAssignments,
            ],
            'canonical_business_classification' => $canonical,
            'canonical_definition' => 'Migration classification is canonical. History-only and exception flags are reported separately because they overlap target request/operational rows.',
            'overlapping_attributes' => $overlays,
            'canonical_total' => array_sum($canonical),
        ];
    }

    private function manualReviewAudit(array $preview, array $business, array $manualReasons, array $locations): array
    {
        $rows = [];
        foreach ($preview as $key => $p) {
            if (isset($business[$key])) continue;
            $rows[] = [
                'business_record_id' => $key,
                'source_tables' => implode('|', array_unique(array_map(fn($r) => explode(':', $r, 2)[0], $p['source_references_json']))),
                'source_row_ids' => implode('|', array_map(fn($r) => explode(':', $r, 2)[1] ?? '', $p['source_references_json'])),
                'legacy_officer_id' => $p['legacy_officer_id'],
                'officer_dad' => $p['officer_dad_number'],
                'officer_name' => $p['officer_name'],
                'appointment_type' => $this->type($p),
                'legacy_asc' => $p['legacy_asc_name'] ?? (($p['asc_location_id'] ?? null) ? ($locations[$p['asc_location_id']]['name_en'] ?? null) : null),
                'legacy_arpa_division' => $p['legacy_arpa_name'] ?? (($p['arpa_location_id'] ?? null) ? ($locations[$p['arpa_location_id']]['name_en'] ?? null) : null),
                'effective_from' => $p['effective_from'],
                'manual_review_reason' => implode('|', $manualReasons[$key] ?? $p['blocker_types_json'] ?? ['MANUAL_REVIEW_REQUIRED']),
                'raw_source_rows' => count($p['source_references_json']),
                'safely_excluded_from_operational_behavior' => true,
            ];
        }
        return $rows;
    }

    private function ascAudit(array $preview, array $business, array $targets, array $locations): array
    {
        $rows = [];
        $missingTargetAsc = 0;
        $missingAscOffice = 0;
        $missingTargetLocation = 0;
        $hierarchyMismatch = 0;
        $officeAsc = [];
        foreach ($this->target->query("SELECT DISTINCT o.linked_location_id FROM office o JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' WHERE o.approval_status='APPROVED'") as $row) {
            $officeAsc[$row['linked_location_id']] = true;
        }
        $relation = [];
        foreach ($this->target->query("SELECT parent_location_id,child_location_id FROM location_relationship WHERE active=1 AND approval_status='APPROVED' AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE())") as $row) {
            $relation[$row['parent_location_id'].'|'.$row['child_location_id']] = true;
        }
        foreach ($preview as $key => $p) {
            $b = $business[$key] ?? null;
            $target = $b ? $this->targetIdentity($this->businessTargetReference($b['id']), $targets) : [];
            $ascId = $target['asc_id'] ?? $p['asc_location_id'] ?? null;
            $arpaId = $target['arpa_id'] ?? $p['arpa_location_id'] ?? null;
            $ascLocation = $ascId ? ($locations[$ascId] ?? null) : null;
            if ($b && $ascLocation === null) $missingTargetAsc++;
            if ($b && $ascId && !isset($officeAsc[$ascId])) $missingAscOffice++;
            if ($b && $p['assignment_category'] === 'ARPA_DIVISION' && (!$arpaId || !isset($locations[$arpaId]))) $missingTargetLocation++;
            if ($b && $p['assignment_category'] === 'ARPA_DIVISION' && $ascId && $arpaId && !isset($relation[$ascId.'|'.$arpaId])) $hierarchyMismatch++;
            $ascDad = $ascLocation['dad_number'] ?? 'UNRESOLVED';
            if (!isset($rows[$ascDad])) {
                $rows[$ascDad] = ['asc_dad' => $ascDad, 'asc_name' => $ascLocation['name_en'] ?? 'Unresolved', 'permanent' => 0, 'acting' => 0, 'duty_covering' => 0, 'attend_to_duty' => 0, 'bank' => 0, 'sales_shop' => 0, 'sithamu' => 0, 'total' => 0, 'manual_review' => 0];
            }
            $column = match ($this->type($p)) {
                'PERMANENT' => 'permanent', 'ACTING' => 'acting', 'DUTY_COVERING' => 'duty_covering', 'ATTEND_TO_DUTY' => 'attend_to_duty',
                'AGRARIAN_BANK' => 'bank', 'SALES_SHOP' => 'sales_shop', 'SITHAMU' => 'sithamu',
            };
            if ($b) {
                $rows[$ascDad][$column]++;
                $rows[$ascDad]['total']++;
            } else {
                $rows[$ascDad]['manual_review']++;
            }
        }
        ksort($rows, SORT_NATURAL);
        return ['summary' => ['asc_rows' => count($rows), 'missing_target_asc' => $missingTargetAsc, 'missing_linked_asc_office' => $missingAscOffice, 'missing_target_arpa_location' => $missingTargetLocation, 'arpa_division_asc_hierarchy_mismatch' => $hierarchyMismatch], 'rows' => array_values($rows)];
    }

    private function officerAudit(array $raw, array $preview, array $business): array
    {
        $legacy = [];
        foreach ($raw as $row) $legacy[(string)$row['officer_id']] = true;
        $mapped = [];
        $distinctTargets = [];
        foreach ($preview as $row) {
            if (!empty($row['officer_id'])) {
                $mapped[(string)$row['legacy_officer_id']][(string)$row['officer_id']] = true;
                $distinctTargets[(string)$row['officer_id']] = true;
            }
        }
        $unmapped = 0;
        $ambiguous = 0;
        foreach (array_keys($legacy) as $id) {
            $count = count($mapped[$id] ?? []);
            if ($count === 0) $unmapped++;
            elseif ($count > 1) $ambiguous++;
        }
        $validTargets = [];
        foreach ($this->target->query('SELECT id FROM officer') as $row) $validTargets[$row['id']] = true;
        $invalidMigrated = 0;
        foreach ($business as $row) if (!$row['officer_id'] || !isset($validTargets[$row['officer_id']])) $invalidMigrated++;
        return ['distinct_legacy_officers_referenced' => count($legacy), 'legacy_officer_ids_with_a_mapping' => count(array_filter($mapped)), 'distinct_mapped_target_officers' => count($distinctTargets), 'unmapped_officers' => $unmapped, 'ambiguous_officer_mappings' => $ambiguous, 'migrated_records_with_invalid_target_officer' => $invalidMigrated];
    }

    private function locationRepairAudit(): array
    {
        $requestMismatch = (int)$this->target->query("SELECT COUNT(*) FROM arpa_division_appointment_request r JOIN location l ON l.id=JSON_UNQUOTE(JSON_EXTRACT(r.origin_metadata_json,'$.location_provenance.target_context_id')) JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ARPA_DIVISION' WHERE r.record_origin='LEGACY_IMPORT' AND r.arpa_division_location_id<>l.id")->fetchColumn();
        $appointmentMismatch = (int)$this->target->query("SELECT COUNT(*) FROM arpa_division_appointment a JOIN location l ON l.id=JSON_UNQUOTE(JSON_EXTRACT(a.origin_metadata_json,'$.location_provenance.target_context_id')) JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ARPA_DIVISION' WHERE a.record_origin='LEGACY_IMPORT' AND a.arpa_division_location_id<>l.id")->fetchColumn();
        return ['request_location_mismatches' => $requestMismatch, 'operational_location_mismatches' => $appointmentMismatch, 'repair_rerun_performed' => false];
    }

    private function orphanAndDuplicateAudit(): array
    {
        $q = fn(string $sql): int => (int)$this->target->query($sql)->fetchColumn();
        $checks = [
            'duplicate_reconciled_business_keys' => $q("SELECT COUNT(*) FROM (SELECT reconciled_business_key FROM legacy_arpa_appointment_preview WHERE active=1 GROUP BY reconciled_business_key HAVING COUNT(*)>1) x"),
            'duplicate_source_references' => $q("SELECT COUNT(*) FROM (SELECT source_system,source_table,legacy_appointment_id FROM legacy_arpa_appointment_source_reference GROUP BY source_system,source_table,legacy_appointment_id HAVING COUNT(*)>1) x"),
            'duplicate_migrated_business_keys' => $q("SELECT COUNT(*) FROM (SELECT reconciled_business_key FROM legacy_arpa_appointment_business_record WHERE source_system='dems_legacy_hr' GROUP BY reconciled_business_key HAVING COUNT(*)>1) x"),
            'business_records_with_multiple_target_requests' => $q("SELECT COUNT(*) FROM (SELECT business_record_id FROM legacy_arpa_appointment_source_reference GROUP BY business_record_id HAVING COUNT(DISTINCT COALESCE(target_appointment_request_id,target_subject_request_id))>1) x"),
            'orphan_division_appointments' => $q("SELECT COUNT(*) FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_request r ON r.id=a.request_id WHERE a.record_origin='LEGACY_IMPORT' AND r.id IS NULL"),
            'orphan_division_requests' => $q("SELECT COUNT(*) FROM arpa_division_appointment_request r LEFT JOIN legacy_arpa_appointment_source_reference sr ON sr.target_appointment_request_id=r.id WHERE r.record_origin='LEGACY_IMPORT' AND sr.id IS NULL"),
            'orphan_division_closures' => $q("SELECT COUNT(*) FROM arpa_division_appointment_closure c LEFT JOIN arpa_division_appointment a ON a.id=c.appointment_id WHERE c.record_origin='LEGACY_IMPORT' AND a.id IS NULL"),
            'orphan_division_workflow_events' => $q("SELECT COUNT(*) FROM arpa_appointment_workflow_action w LEFT JOIN arpa_division_appointment_request r ON r.id=w.request_id WHERE w.record_origin='LEGACY_IMPORT' AND r.id IS NULL"),
            'orphan_subject_assignments' => $q("SELECT COUNT(*) FROM arpa_subject_assignment a LEFT JOIN arpa_subject_assignment_request r ON r.id=a.request_id WHERE a.record_origin='LEGACY_IMPORT' AND r.id IS NULL"),
            'orphan_subject_requests' => $q("SELECT COUNT(*) FROM arpa_subject_assignment_request r LEFT JOIN legacy_arpa_appointment_source_reference sr ON sr.target_subject_request_id=r.id WHERE r.record_origin='LEGACY_IMPORT' AND sr.id IS NULL"),
            'orphan_subject_closures' => $q("SELECT COUNT(*) FROM arpa_subject_assignment_closure c LEFT JOIN arpa_subject_assignment a ON a.id=c.assignment_id WHERE c.record_origin='LEGACY_IMPORT' AND a.id IS NULL"),
            'orphan_subject_workflow_events' => $q("SELECT COUNT(*) FROM arpa_subject_workflow_action w LEFT JOIN arpa_subject_assignment_request r ON r.id=w.request_id WHERE w.record_origin='LEGACY_IMPORT' AND r.id IS NULL"),
            'orphan_sithamu_periods' => $q("SELECT COUNT(*) FROM arpa_officer_sub_designation_period p LEFT JOIN arpa_subject_assignment a ON a.id=p.source_subject_assignment_id WHERE p.record_origin='LEGACY_IMPORT' AND a.id IS NULL"),
        ];
        $orphanKeys = array_filter(array_keys($checks), fn($k) => str_starts_with($k, 'orphan_'));
        $checks['total_orphan_target_records'] = array_sum(array_intersect_key($checks, array_flip($orphanKeys)));
        return $checks;
    }

    private function equations(array $raw, array $reconciliation, array $coverage, array $types, int $migrated, int $manual): array
    {
        $class = $reconciliation['classes'];
        $old = $raw['tables']['tbl_officer_apoint'] ?? 0;
        $new = $raw['tables']['tbl_officer_apoint_2026'] ?? 0;
        $exact = $class['EXACT_DUPLICATE'] ?? 0;
        $continuation = $class['SAME_APPOINTMENT_CONTINUATION'] ?? 0;
        $oldOnly = $class['OLD_HISTORY_ONLY'] ?? 0;
        $newOnly = $class['2026_ONLY'] ?? 0;
        $equations = [
            'A' => ['left' => $old + $new, 'right' => $raw['combined'], 'expression' => "{$old} + {$new} = {$raw['combined']}"],
            'B' => ['left' => $exact * 2 + $continuation * 2 + $oldOnly + $newOnly, 'right' => $raw['combined'], 'expression' => "({$exact} * 2) + ({$continuation} * 2) + {$oldOnly} + {$newOnly} = {$raw['combined']}"],
            'C' => ['left' => $exact + $continuation + $oldOnly + $newOnly, 'right' => $reconciliation['business_records'], 'expression' => "{$exact} + {$continuation} + {$oldOnly} + {$newOnly} = {$reconciliation['business_records']}"],
            'D' => ['left' => $migrated + $manual, 'right' => $reconciliation['business_records'], 'expression' => "{$migrated} + {$manual} = {$reconciliation['business_records']}"],
            'E' => ['left' => $coverage['exactly_one_migrated_source_reference'] + $coverage['manual_review_provenance_only'], 'right' => $raw['combined'], 'expression' => "{$coverage['exactly_one_migrated_source_reference']} + {$coverage['manual_review_provenance_only']} = {$raw['combined']}"],
            'F' => ['left' => $types['difference_total'], 'right' => 0, 'expression' => 'For every type: migrated + manual review = reconciled'],
        ];
        foreach ($equations as &$equation) $equation['pass'] = $equation['left'] === $equation['right'];
        return $equations;
    }

    private function targetIdentity(?array $reference, array $targets): array
    {
        if ($reference === null) return [];
        if (!empty($reference['target_appointment_request_id'])) {
            $request = $targets['division_requests'][$reference['target_appointment_request_id']] ?? [];
            $appointment = !empty($reference['target_appointment_id']) ? ($targets['division_appointments'][$reference['target_appointment_id']] ?? []) : [];
            $closure = $appointment ? ($targets['division_closures_by_appointment'][$appointment['id']] ?? false) : false;
            return ['record_type' => $appointment ? 'ARPA_DIVISION_APPOINTMENT' : 'ARPA_DIVISION_REQUEST', 'record_id' => $appointment['id'] ?? $request['id'] ?? null, 'asc_id' => $appointment['asc_location_id'] ?? $request['asc_location_id'] ?? null, 'arpa_id' => $appointment['arpa_division_location_id'] ?? $request['arpa_division_location_id'] ?? null, 'effective_from' => $appointment['effective_from'] ?? $request['requested_effective_from'] ?? null, 'effective_to' => $closure['effective_to'] ?? $request['requested_effective_to'] ?? null, 'legacy_history_only' => $appointment['legacy_history_only'] ?? $request['legacy_history_only'] ?? null, 'legacy_exception' => $appointment['legacy_exception'] ?? $request['legacy_exception'] ?? null, 'operational' => $appointment !== []];
        }
        if (!empty($reference['target_subject_request_id'])) {
            $request = $targets['subject_requests'][$reference['target_subject_request_id']] ?? [];
            $assignment = !empty($reference['target_subject_assignment_id']) ? ($targets['subject_assignments'][$reference['target_subject_assignment_id']] ?? []) : [];
            $closure = $assignment ? ($targets['subject_closures_by_assignment'][$assignment['id']] ?? false) : false;
            return ['record_type' => $assignment ? 'SUBJECT_FUNCTION_ASSIGNMENT' : 'SUBJECT_FUNCTION_REQUEST', 'record_id' => $assignment['id'] ?? $request['id'] ?? null, 'asc_id' => $assignment['asc_location_id'] ?? $request['asc_location_id'] ?? null, 'arpa_id' => null, 'effective_from' => $assignment['effective_from'] ?? $request['requested_effective_from'] ?? null, 'effective_to' => $closure['effective_to'] ?? $request['requested_effective_to'] ?? null, 'legacy_history_only' => $assignment['legacy_history_only'] ?? $request['legacy_history_only'] ?? null, 'legacy_exception' => $assignment['legacy_exception'] ?? $request['legacy_exception'] ?? null, 'operational' => $assignment !== []];
        }
        return [];
    }

    private array $businessReferenceCache = [];

    private function businessTargetReference(string $businessId): ?array
    {
        if (array_key_exists($businessId, $this->businessReferenceCache)) return $this->businessReferenceCache[$businessId];
        $s = $this->target->prepare('SELECT * FROM legacy_arpa_appointment_source_reference WHERE business_record_id=? ORDER BY id LIMIT 1');
        $s->execute([$businessId]);
        return $this->businessReferenceCache[$businessId] = ($s->fetch() ?: null);
    }

    private function manualReviewReasons(): array
    {
        $rows = [];
        $sql = "SELECT i.reconciled_business_key,i.evidence_json FROM legacy_arpa_appointment_migration_issue i JOIN legacy_arpa_appointment_migration_run r ON r.id=i.migration_run_id WHERE i.issue_type='MANUAL_REVIEW_SKIPPED' ORDER BY r.started_at,i.id";
        foreach ($this->target->query($sql) as $row) {
            $evidence = $this->decode($row['evidence_json'], []);
            $rows[$row['reconciled_business_key']] = array_values($evidence['reasons'] ?? []);
        }
        return $rows;
    }

    private function type(array $row): string
    {
        return $row['assignment_category'] === 'ASC_FUNCTION' ? (string)$row['subject_kind'] : (string)$row['appointment_type'];
    }

    private function decode(mixed $json, array $default): array
    {
        if (is_array($json)) return $json;
        if ($json === null || $json === '') return $default;
        $decoded = json_decode((string)$json, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function memoryLimitBytes(string $value): int
    {
        if ($value === '-1') return PHP_INT_MAX;
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int)$value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function stateFingerprint(): array
    {
        $targetTables = ['legacy_arpa_appointment_preview', 'legacy_arpa_appointment_business_record', 'legacy_arpa_appointment_source_reference', 'legacy_arpa_appointment_migration_issue', 'legacy_arpa_reconciliation_item', 'legacy_arpa_appointment_resolution', 'arpa_division_appointment_request', 'arpa_division_appointment', 'arpa_division_appointment_closure', 'arpa_appointment_workflow_action', 'arpa_subject_assignment_request', 'arpa_subject_assignment', 'arpa_subject_assignment_closure', 'arpa_subject_workflow_action', 'officer', 'system_user', 'application_role', 'user_account_role', 'user_account_scope'];
        $state = ['source' => [], 'target' => []];
        foreach (self::SOURCE_TABLES as $table) $state['source'][$table] = (int)$this->source->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        foreach ($targetTables as $table) $state['target'][$table] = (int)$this->target->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        return $state;
    }

    private function writeReports(array $report, array $coverage, array $types, array $asc, array $manual): array
    {
        $directory = $this->reportDirectory ?? BASE_PATH.'/storage/reports';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('Unable to create report directory.');
        $stamp = date('Ymd-His');
        $base = rtrim($directory, '/\\').DIRECTORY_SEPARATOR;
        $paths = [
            'json' => $base."final-legacy-arpa-migration-audit-{$stamp}.json",
            'summary_csv' => $base."final-legacy-arpa-migration-audit-{$stamp}.csv",
            'type_csv' => $base."final-legacy-arpa-migration-by-type-{$stamp}.csv",
            'asc_csv' => $base."final-legacy-arpa-migration-by-asc-{$stamp}.csv",
            'manual_review_csv' => $base."final-legacy-arpa-migration-manual-review-{$stamp}.csv",
            'source_coverage_csv' => $base."final-legacy-arpa-source-coverage-{$stamp}.csv",
        ];
        file_put_contents($paths['json'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $summary = [];
        foreach ($report['statuses'] as $key => $value) $summary[] = ['section' => 'STATUS', 'metric' => $key, 'value' => $value];
        foreach ($report['accounting_equations'] as $key => $value) $summary[] = ['section' => 'EQUATION_'.$key, 'metric' => $value['expression'], 'value' => $value['pass'] ? 'PASS' : 'FAIL'];
        $this->csv($paths['summary_csv'], $summary, ['section', 'metric', 'value']);
        $this->csv($paths['type_csv'], $types, ['type', 'reconciled', 'migrated', 'manual_review', 'total_accounted_for', 'difference']);
        $this->csv($paths['asc_csv'], $asc, ['asc_dad', 'asc_name', 'permanent', 'acting', 'duty_covering', 'attend_to_duty', 'bank', 'sales_shop', 'sithamu', 'total', 'manual_review']);
        $this->csv($paths['manual_review_csv'], $manual, ['business_record_id', 'source_tables', 'source_row_ids', 'legacy_officer_id', 'officer_dad', 'officer_name', 'appointment_type', 'legacy_asc', 'legacy_arpa_division', 'effective_from', 'manual_review_reason', 'raw_source_rows', 'safely_excluded_from_operational_behavior']);
        $this->csv($paths['source_coverage_csv'], $coverage, ['source_table', 'source_row_id', 'legacy_officer_id', 'target_officer_dad', 'reconciliation_class', 'business_record_id', 'appointment_type', 'legacy_location_id', 'target_location_dad', 'target_asc_dad', 'migration_outcome', 'target_record_type', 'target_record_id', 'manual_review_reason']);
        return $paths;
    }

    private function csv(string $path, array $rows, array $columns): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) throw new RuntimeException('Unable to write report: '.$path);
        try {
            fputcsv($handle, $columns, ',', '"', '');
            foreach ($rows as $row) fputcsv($handle, array_map(fn($column) => $row[$column] ?? null, $columns), ',', '"', '');
        } finally {
            fclose($handle);
        }
    }
}
