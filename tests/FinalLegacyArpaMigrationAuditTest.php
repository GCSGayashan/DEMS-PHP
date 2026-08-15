<?php
declare(strict_types=1);

use App\Core\{Database, LegacyDatabase};
use App\Services\LegacyAppointment\FinalLegacyArpaMigrationAuditService;

require dirname(__DIR__).'/bootstrap.php';

final class FinalLegacyArpaMigrationAuditTest
{
    private int $assertions = 0;

    public function run(): int
    {
        $target = Database::pdo();
        $source = LegacyDatabase::pdo();
        $before = $this->state($target, $source);
        $directory = sys_get_temp_dir().'/dems-final-arpa-audit-'.bin2hex(random_bytes(4));
        $report = (new FinalLegacyArpaMigrationAuditService($target, $source, $directory))->audit(true);

        $this->same(10128, $report['raw_source']['tables']['tbl_officer_apoint'], 'old source count');
        $this->same(9775, $report['raw_source']['tables']['tbl_officer_apoint_2026'], '2026 source count');
        $this->same(19903, $report['raw_source']['combined'], 'combined source count');
        $this->same(14779, $report['reconciliation']['business_records'], 'reconciled business count');
        $this->same(14775, $report['target_distribution']['migrated_business_records'], 'migrated business count');
        $this->same(4, $report['manual_review']['count'], 'manual review count');
        $this->same(6, $report['manual_review']['source_rows'], 'manual review source rows');
        $this->same(19903, $report['source_coverage']['exactly_one_reconciliation_mapping'], 'every source row maps exactly once');
        $this->same(0, $report['source_coverage']['zero_reconciliation_mappings'], 'no source row is unexplained');
        $this->same(0, $report['source_coverage']['more_than_one_reconciliation_mapping'], 'no source row has duplicate reconciliation');
        $this->same(19897, $report['source_coverage']['exactly_one_migrated_source_reference'], 'migrated source reference count');
        $this->same(6, $report['source_coverage']['manual_review_provenance_only'], 'manual records explain remaining source rows');
        $this->same(0, $report['source_coverage']['orphan_target_source_references'], 'no orphan source reference');
        $this->same(0, $report['source_coverage']['invalid_source_table_references'], 'no invalid source table reference');
        foreach ($report['accounting_equations'] as $key => $equation) $this->same(true, $equation['pass'], "accounting equation {$key}");
        foreach ($report['type_reconciliation']['rows'] as $row) $this->same(0, $row['difference'], "{$row['type']} balances");
        $this->same(0, $report['location_repair_validation']['request_location_mismatches'], 'request location repair remains complete');
        $this->same(0, $report['location_repair_validation']['operational_location_mismatches'], 'operational location repair remains complete');
        $this->same(0, $report['officer_integrity']['unmapped_officers'], 'all legacy officers resolve');
        $this->same(0, $report['officer_integrity']['ambiguous_officer_mappings'], 'no officer mapping is ambiguous');
        $this->same(0, $report['duplicates_and_orphans']['total_orphan_target_records'], 'no target records are orphaned');
        $this->same('COMPLETE', $report['statuses']['source_row_coverage'], 'source coverage verdict');
        $this->same('COMPLETE', $report['statuses']['business_record_coverage'], 'business coverage verdict');
        $this->same('COMPLETE EXCEPT REVIEW ITEMS', $report['statuses']['migration_coverage'], 'migration coverage verdict');
        $this->same('YES', $report['statuses']['all_legacy_appointment_data_accounted_for'], 'all source data is accounted for');
        $this->same('NO', $report['statuses']['all_legacy_business_records_operationally_migrated'], 'manual review records remain non-operational');
        $this->same(6, count($report['report_paths']), 'all requested reports are generated');
        foreach ($report['report_paths'] as $path) $this->same(true, is_file($path) && filesize($path) > 0, basename($path).' generated');
        $coveragePath = $report['report_paths']['source_coverage_csv'];
        $lineCount = 0;
        $h = fopen($coveragePath, 'rb');
        while ($h !== false && fgets($h) !== false) $lineCount++;
        if ($h !== false) fclose($h);
        $this->same(19904, $lineCount, 'coverage CSV contains one line per source row plus header');
        $this->same($before, $this->state($target, $source), 'audit leaves both databases unchanged');
        $cli = file_get_contents(BASE_PATH.'/bin/audit-final-legacy-arpa-migration.php');
        $this->same(false, str_contains($cli, '--execute'), 'audit CLI has no execute mode');
        $this->same(true, str_contains($cli, '--read-only'), 'audit CLI requires explicit read-only mode');

        foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
        if (is_dir($directory)) rmdir($directory);
        echo "FinalLegacyArpaMigrationAuditTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function state(PDO $target, PDO $source): array
    {
        return [
            'old' => (int)$source->query('SELECT COUNT(*) FROM tbl_officer_apoint')->fetchColumn(),
            'new' => (int)$source->query('SELECT COUNT(*) FROM tbl_officer_apoint_2026')->fetchColumn(),
            'business' => (int)$target->query('SELECT COUNT(*) FROM legacy_arpa_appointment_business_record')->fetchColumn(),
            'references' => (int)$target->query('SELECT COUNT(*) FROM legacy_arpa_appointment_source_reference')->fetchColumn(),
            'division_requests' => (int)$target->query('SELECT COUNT(*) FROM arpa_division_appointment_request')->fetchColumn(),
            'division_appointments' => (int)$target->query('SELECT COUNT(*) FROM arpa_division_appointment')->fetchColumn(),
            'subject_requests' => (int)$target->query('SELECT COUNT(*) FROM arpa_subject_assignment_request')->fetchColumn(),
            'subject_assignments' => (int)$target->query('SELECT COUNT(*) FROM arpa_subject_assignment')->fetchColumn(),
            'resolutions' => (int)$target->query('SELECT COUNT(*) FROM legacy_arpa_appointment_resolution')->fetchColumn(),
            'officers' => (int)$target->query('SELECT COUNT(*) FROM officer')->fetchColumn(),
            'users' => (int)$target->query('SELECT COUNT(*) FROM system_user')->fetchColumn(),
        ];
    }

    private function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) throw new RuntimeException($message.': expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

exit((new FinalLegacyArpaMigrationAuditTest())->run());
