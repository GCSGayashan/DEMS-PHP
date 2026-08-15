<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\LegacyOfficer\LegacySpecialFunctionOfficeBackfillService;

require dirname(__DIR__) . '/bootstrap.php';

final class LegacySpecialFunctionOfficeBackfillTest
{
    private PDO $target;
    private int $assertions = 0;

    public function run(): int
    {
        $this->target = Database::pdo();
        $this->dryRunIsReadOnlyAndDecisionDriven();
        $this->schemaAndSafety();
        echo "LegacySpecialFunctionOfficeBackfillTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function dryRunIsReadOnlyAndDecisionDriven(): void
    {
        $before = $this->state();
        $report = (new LegacySpecialFunctionOfficeBackfillService(
            $this->target,
            date('Y-m-d'),
        ))->dryRun();

        $this->same(true, $report['source']['current_special_function_records_examined'] > 0, 'special records are examined');
        $this->same(true, $report['zero_write_verification']['target_unchanged'], 'dry-run performs no database writes');
        $this->same($before, $this->state(), 'all protected target tables remain unchanged');
        $this->same(
            $report['source']['confirmed_asc_decisions'] + $report['source']['unresolved_asc_decisions']
                + $report['source']['preserve_history_only_excluded'],
            $report['source']['current_special_function_records_examined'],
            'each current record is confirmed, unresolved, or excluded from current state',
        );

        $pairs = [];
        foreach ($report['proposals'] as $proposal) {
            $key = $proposal['target_officer_id'] . '|' . $proposal['office_id'];
            $this->same(false, isset($pairs[$key]), 'one proposal per Officer and ASC Office');
            $pairs[$key] = true;
            $this->same(true, count($proposal['reconciliation_decision_ids']) > 0, 'proposal records confirmed decision provenance');
        }
    }

    private function schemaAndSafety(): void
    {
        $service = file_get_contents(BASE_PATH . '/app/Services/LegacyOfficer/LegacySpecialFunctionOfficeBackfillService.php');
        $this->contains("resolution_status'] ?? null) !== 'CONFIRMED'", $service, 'only confirmed decisions qualify');
        $this->contains('selected_target_asc_id', $service, 'confirmed selected ASC is used');
        $this->contains('LEGACY_CURRENT_STATE_BACKFILL', $service, 'migration provenance is explicit');
        $this->same(false, str_contains($service, 'SELECT appoint_location_id'), 'legacy Officer location shortcut is excluded');
        $this->same(false, str_contains($service, 'LegacyDatabase'), 'legacy database is not connected');
        $this->same(false, str_contains($service, 'INSERT INTO arpa_division_appointment'), 'ARPA appointments are not migrated');
        $this->same(false, str_contains($service, 'INSERT INTO arpa_subject_assignment'), 'subject assignments are not migrated');
        $this->same(false, str_contains($service, 'arpa_officer_sub_designation_period('), 'Sithamu periods are not created');
        $this->same(false, str_contains($service, 'UPDATE system_user'), 'users are not activated or changed');
    }

    private function state(): array
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

    private function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException("{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    private function contains(string $needle, string $haystack, string $message): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException("{$message}: missing {$needle}");
        }
    }
}

exit((new LegacySpecialFunctionOfficeBackfillTest())->run());
