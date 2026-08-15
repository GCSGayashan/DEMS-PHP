<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\LegacyDatabase;
use App\Services\LegacyLocation\LegacyLocationMigrationService;

require dirname(__DIR__) . '/bootstrap.php';
date_default_timezone_set((string)config('app.timezone', 'UTC'));

function usage(): void
{
    echo <<<'TXT'
DEMS one-time AgrarianAdmin Location migration

Usage:
  php bin/migrate-legacy-locations.php --dry-run [--type=TYPE] [--batch-size=500]
  php bin/migrate-legacy-locations.php --execute [--type=TYPE] [--batch-size=500]

TYPE is one of: province, district, asc, arpa, gn

Dry-run reads both databases and writes only a migration-run audit row and report files.
Execute writes approved Locations, Province-to-District, District-to-ASC and
ASC-to-ARPA relationships, legacy references, and issues. GN records are
independent Locations in this phase. DS and ARPA-to-GN work is deferred.
TXT;
    echo PHP_EOL;
}

$options = getopt('', ['dry-run', 'execute', 'type:', 'batch-size:', 'help']);
if (isset($options['help'])) {
    usage();
    exit(0);
}

$dryRun = array_key_exists('dry-run', $options);
$execute = array_key_exists('execute', $options);
if ($dryRun === $execute) {
    fwrite(STDERR, "Choose exactly one of --dry-run or --execute.\n\n");
    usage();
    exit(2);
}

$type = isset($options['type']) ? strtolower(trim((string)$options['type'])) : null;
$batchSizeRaw = $options['batch-size'] ?? '500';
if (!is_string($batchSizeRaw) || !ctype_digit($batchSizeRaw)) {
    fwrite(STDERR, "--batch-size must be a positive integer.\n");
    exit(2);
}
$batchSize = (int)$batchSizeRaw;

try {
    $legacyConfig = config('legacy_database');
    $service = new LegacyLocationMigrationService(
        LegacyDatabase::pdo(),
        Database::pdo(),
        $dryRun,
        $type,
        $batchSize,
        is_string($legacyConfig['effective_from'] ?? null) ? $legacyConfig['effective_from'] : null
    );
    $summary = $service->run();

    echo "Legacy Location migration {$summary['status']} ({$summary['mode']})\n";
    echo "Run ID: {$summary['run_id']}\n";
    echo "Source records: {$summary['source_records']}\n";
    echo "Matched existing: {$summary['matched_existing']}\n";
    if ($dryRun) {
        echo "Would create: {$summary['would_create']}\n";
        echo "Relationships would create: {$summary['relationships_would_create']}\n";
    } else {
        echo "Created new: {$summary['created_new']}\n";
        echo "Relationships created: {$summary['relationships_created']}\n";
    }
    echo "Skipped: {$summary['skipped']}\n";
    echo "Warnings: {$summary['warnings']}\n";
    echo "Errors: {$summary['errors']}\n";
    echo "Report: {$summary['report_path']}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Legacy Location migration FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
