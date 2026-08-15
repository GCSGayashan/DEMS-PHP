<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\LegacyDatabase;
use App\Services\LegacyAppointment\HistoricalArpaAppointmentDiagnosticService;

require dirname(__DIR__).'/bootstrap.php';
date_default_timezone_set((string)config('app.timezone','UTC'));
$options=getopt('',['dry-run','execute','help']);
if(isset($options['help'])){echo "Usage: php bin/diagnose-legacy-arpa-appointments.php --dry-run\nThis phase is diagnostic-only; --execute is intentionally rejected.\n";exit(0);}
if(isset($options['execute'])){fwrite(STDERR,"Execution is not implemented or authorized in this diagnostic phase.\n");exit(2);}
if(!isset($options['dry-run'])){fwrite(STDERR,"Specify --dry-run.\n");exit(2);}
try{
    $s=(new HistoricalArpaAppointmentDiagnosticService(LegacyDatabase::pdo(),Database::pdo()))->run();
    echo "Historical ARPA Appointment Diagnostic (READ-ONLY)\n";
    foreach($s['source'] as $k=>$v)echo str_replace('_',' ',ucwords($k,'_')).": {$v}\n";
    echo "\nOFFICER COVERAGE\n";foreach($s['officer_coverage'] as $type=>$v)echo "{$type}: old={$v['old_distinct']}, 2026={$v['2026_distinct']}, union={$v['union_distinct']}, mapped={$v['mapped_target']}, missing={$v['missing_target']}, missing-but-in-tbl_officer={$v['missing_but_exists_in_tbl_officer']}\n";
    echo "\nTYPE COUNTS (raw rows across both sources)\n";foreach($s['type_counts'] as $k=>$v)echo "{$k}: {$v}\n";
    echo "TYPE COUNTS (reconciled business records)\n";foreach($s['history_and_projection']['canonical_type_counts'] as $k=>$v)echo "{$k}: {$v}\n";
    echo "\nLOCATION COVERAGE\n";foreach($s['location_coverage'] as $type=>$counts)echo $type.': '.implode(', ',array_map(fn($k,$v)=>"{$k}={$v}",array_keys($counts),$counts))."\n";
    echo "\nDEDUPLICATION (business reconciliations)\n";foreach($s['deduplication'] as $k=>$v)echo "{$k}: {$v}\n";
    echo "\nWORKFLOW\nDistinct actors: {$s['workflow']['distinct_actors']}\nResolved: {$s['workflow']['resolved_actors']}\nUnresolved: {$s['workflow']['unresolved_actors']}\nCoverage: {$s['workflow']['coverage_percent']}%\n";
    foreach($s['workflow']['stages'] as $stage=>$v)echo "{$stage}: rows={$v['rows_with_user']}, distinct={$v['distinct_users']}, resolved={$v['resolved_users']}, unresolved={$v['unresolved_users']}, timestamps={$v['rows_with_timestamp']}\n";
    echo "Flag combinations:\n";foreach($s['workflow']['flag_combinations'] as $k=>$v)echo "  {$k}: {$v}\n";
    echo "\nSERVICE PERMANENCY RECONSTRUCTION\n";foreach($s['service_permanency_reconstruction'] as $k=>$v)echo "{$k}: {$v}\n";
    echo "\nSITHAMU SUB-DESIGNATION IDS\n";foreach($s['sithamu_sub_designation_ids'] as $k=>$v)echo "{$k}: {$v}\n";
    $h=$s['history_and_projection'];echo "\nHISTORY / PROJECTED TARGET\nEnded: {$h['ended']}\nOpen: {$h['open']}\nMapped reasons: {$h['mapped_reasons']}\nUnmapped reasons: {$h['unmapped_reasons']}\nEnd date without reason: {$h['end_date_without_reason']}\nReason without end date: {$h['reason_without_end_date']}\nRequests to create: {$h['requests_to_create']}\nWorkflow/request only: {$h['workflow_request_only']}\nFinally approved operational: {$h['finally_approved_operational']}\nEnded operational: {$h['ended_operational']}\nRejected/incomplete: {$h['rejected_or_incomplete']}\nOperational ARPA appointments: {$h['operational_arpa_to_create']}\nSubject assignments: {$h['subject_assignments_to_create']}\nBank subject assignments: {$h['subject_assignments_by_kind']['AGRARIAN_BANK']}\nSales Shop subject assignments: {$h['subject_assignments_by_kind']['SALES_SHOP']}\nSithamu subject assignments: {$h['subject_assignments_by_kind']['SITHAMU']}\nSithamu periods: {$h['sithamu_periods_to_create']}\nManual-review records: {$h['records_requiring_manual_review']}\nCurrent records prevented: {$h['records_prevented_from_activation']}\nTrue blocker records: {$h['true_execution_blocker_records']}\nTrue blocker reasons: ".json_encode($h['true_blocker_reasons'],JSON_UNESCAPED_SLASHES)."\nReconciliation blocker rows: {$s['reconciliation_issue_rows']}\nGlobal target-schema blockers: {$s['global_schema_blockers']}\nTotal true execution blocker records: {$s['true_execution_blockers']}\n";
    echo 'Current active projected: '.implode(', ',array_map(fn($k,$v)=>"{$k}={$v}",array_keys($h['current_active']),$h['current_active']))."\n";
    echo 'Historical exceptions: '.json_encode($h['legacy_exceptions'],JSON_UNESCAPED_SLASHES)."\nCurrent active conflicts: ".json_encode($h['current_active_conflicts'],JSON_UNESCAPED_SLASHES)."\n";
    echo "Target-schema incompatibilities:\n";foreach($s['target_schema_incompatibilities'] as $k=>$v)echo "  {$k}: {$v}\n";
    echo "\nZero-write source: ".($s['zero_write_verification']['source_unchanged']?'YES':'NO')."\nZero-write target: ".($s['zero_write_verification']['target_unchanged']?'YES':'NO')."\nCSV: {$s['csv_report_path']}\nJSON: {$s['json_report_path']}\nSpecial ASC review: {$s['special_asc_review_report_path']}\nCurrent conflicts: {$s['current_conflict_report_path']}\nMissing ARPA location: {$s['missing_arpa_location_report_path']}\n";
    exit(0);
}catch(Throwable $e){fwrite(STDERR,'Diagnostic failed: '.$e->getMessage()."\n");exit(1);}
