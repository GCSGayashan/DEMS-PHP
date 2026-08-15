<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\LegacyDatabase;
use App\Services\LegacyLocation\LegacyGnMigrationRepairService;

require dirname(__DIR__) . '/bootstrap.php';
date_default_timezone_set((string)config('app.timezone', 'UTC'));

$options = getopt('', ['dry-run', 'execute', 'batch-size:', 'help']);
if (isset($options['help'])) {
    echo "Usage:\n  php bin/repair-legacy-gn-migration.php --dry-run [--batch-size=500]\n  php bin/repair-legacy-gn-migration.php --execute [--batch-size=500]\n";
    exit(0);
}

$dryRun = array_key_exists('dry-run', $options);
$execute = array_key_exists('execute', $options);
if ($dryRun === $execute) {
    fwrite(STDERR, "Choose exactly one of --dry-run or --execute.\n");
    exit(2);
}
$batchSize = (string)($options['batch-size'] ?? '500');
if (!ctype_digit($batchSize) || (int)$batchSize < 1 || (int)$batchSize > 5000) {
    fwrite(STDERR, "--batch-size must be between 1 and 5000.\n");
    exit(2);
}

try {
    $legacyConfig = config('legacy_database');
    $summary = (new LegacyGnMigrationRepairService(
        LegacyDatabase::pdo(),
        Database::pdo(),
        $dryRun,
        (int)$batchSize,
        (string)($legacyConfig['effective_from'] ?? date('Y-m-d'))
    ))->run();

    echo "Legacy GN migration repair ({$summary['mode']})\n";
    echo "Source GN records: {$summary['source_gn_count']}\n";
    echo "Current GN Locations: {$summary['current_gn_count']}\n";
    echo "Legacy references: {$summary['legacy_reference_count']}\n";
    echo "Distinct referenced GN Locations: {$summary['distinct_referenced_gn_count']}\n";
    echo "Target GNs with multiple references: {$summary['duplicate_target_gn_count']}\n";
    echo "References requiring separation: {$summary['references_requiring_separation']}\n";
    echo "GN Locations requiring creation: {$summary['new_gn_locations_required']}\n";
    echo "GN Locations created: {$summary['new_gn_locations_created']}\n";
    echo "New DAD numbers allocated: {$summary['new_dad_numbers_allocated']}\n";
    echo "Remaining duplicate references: {$summary['remaining_duplicate_references']}\n";
    echo "Errors: {$summary['errors']}\n";
    echo "District without Province: {$summary['relationship_validation']['district_without_province']}\n";
    echo "ASC without District: {$summary['relationship_validation']['asc_without_district']}\n";
    echo "ARPA without ASC: {$summary['relationship_validation']['arpa_without_asc']}\n";
    echo "Report: {$summary['report_path']}\n";
    exit($summary['errors'] === 0 ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Legacy GN migration repair FAILED: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
