<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\LegacyDatabase;
use App\Services\LegacyOfficer\LegacyArpaOfficerDesignationClassBackfillService;

require dirname(__DIR__) . '/bootstrap.php';
date_default_timezone_set((string)config('app.timezone', 'UTC'));

function designationClassBackfillUsage(): void
{
    echo <<<'TXT'
DEMS legacy ARPA Officer designation/class backfill

Usage:
  php bin/backfill-legacy-arpa-officer-designation-class.php --dry-run [--batch-size=500]
  php bin/backfill-legacy-arpa-officer-designation-class.php --execute [--batch-size=500]

This updates Officer Master primary designation and class only. It never
creates appointments, assignments, users, roles, permissions, or scopes.
Dry-run performs no database writes.
TXT;
    echo PHP_EOL;
}

$options = getopt('', ['dry-run', 'execute', 'batch-size:', 'help']);
if (isset($options['help'])) {
    designationClassBackfillUsage();
    exit(0);
}
$dryRun = array_key_exists('dry-run', $options);
$execute = array_key_exists('execute', $options);
if ($dryRun === $execute) {
    fwrite(STDERR, "Choose exactly one of --dry-run or --execute.\n\n");
    designationClassBackfillUsage();
    exit(2);
}
$batchRaw = $options['batch-size'] ?? '500';
if (!is_string($batchRaw) || !ctype_digit($batchRaw) || (int)$batchRaw < 1 || (int)$batchRaw > 10000) {
    fwrite(STDERR, "--batch-size must be an integer between 1 and 10000.\n");
    exit(2);
}

try {
    $summary = (new LegacyArpaOfficerDesignationClassBackfillService(
        LegacyDatabase::pdo(),
        Database::pdo(),
        $dryRun,
        (int)$batchRaw
    ))->run();

    echo "Legacy ARPA Officer designation/class backfill {$summary['status']} ({$summary['mode']})\n";
    echo 'Legacy references found: ' . $summary['legacy_references_found'] . PHP_EOL;
    echo 'Officers found: ' . $summary['officers_found'] . PHP_EOL;
    echo 'Designation target found: ' . $summary['designation_target_found'] . PHP_EOL;
    echo 'Designation: ' . $summary['designation']['system_key'] . ' / ' . $summary['designation']['name_en'] . PHP_EOL;
    echo 'Designation ID: ' . $summary['designation']['id'] . PHP_EOL;
    echo 'Designation DAD number: ' . $summary['designation']['dad_number'] . PHP_EOL;
    echo 'Would set designation: ' . $summary['would_set_designation'] . PHP_EOL;
    echo 'Already correct designation: ' . $summary['already_correct_designation'] . PHP_EOL;
    echo 'Would have designation after execution: ' . $summary['designation_after_execution'] . PHP_EOL;
    echo 'Class I: ' . $summary['class_i'] . PHP_EOL;
    echo 'Class II: ' . $summary['class_ii'] . PHP_EOL;
    echo 'Class III: ' . $summary['class_iii'] . PHP_EOL;
    echo 'Class NULL due to Select: ' . $summary['class_null_select'] . PHP_EOL;
    echo 'Unknown grades: ' . $summary['unknown_grades'] . PHP_EOL;
    echo 'Would update: ' . $summary['would_update'] . PHP_EOL;
    echo 'Updated: ' . $summary['updated'] . PHP_EOL;
    echo 'Skipped: ' . $summary['skipped'] . PHP_EOL;
    echo 'Warnings: ' . $summary['warnings'] . PHP_EOL;
    echo 'Errors: ' . $summary['errors'] . PHP_EOL;
    echo 'Out-of-scope/identity/numbering state unchanged: ' . ($summary['out_of_scope_unchanged'] ? 'YES' : 'NO') . PHP_EOL;
    echo 'Report: ' . $summary['report_path'] . PHP_EOL;
    exit($summary['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Legacy ARPA Officer designation/class backfill FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
