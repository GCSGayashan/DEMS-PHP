<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\LegacyDatabase;
use App\Services\LegacyLocation\LegacyGnByIdRepairService;

require dirname(__DIR__) . '/bootstrap.php';

date_default_timezone_set(
    (string)config('app.timezone', 'UTC')
);

$options = getopt(
    '',
    [
        'dry-run',
        'execute',
        'batch-size:',
        'help',
    ]
);

if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php bin/repair-legacy-gn-by-id.php --dry-run [--batch-size=500]\n";
    echo "  php bin/repair-legacy-gn-by-id.php --execute [--batch-size=500]\n";
    exit(0);
}

$dryRun = array_key_exists(
    'dry-run',
    $options
);

$execute = array_key_exists(
    'execute',
    $options
);

if ($dryRun === $execute) {
    fwrite(
        STDERR,
        "Choose exactly one of --dry-run or --execute.\n"
    );

    exit(2);
}

$batchSize = (string)(
    $options['batch-size'] ?? '500'
);

if (
    !ctype_digit($batchSize)
    || (int)$batchSize < 1
    || (int)$batchSize > 5000
) {
    fwrite(
        STDERR,
        "--batch-size must be between 1 and 5000.\n"
    );

    exit(2);
}

try {
    $summary = (
        new LegacyGnByIdRepairService(
            LegacyDatabase::pdo(),
            Database::pdo(),
            $dryRun,
            (int)$batchSize,
        )
    )->run();

    echo "Legacy GN by-ID repair ({$summary['mode']})\n";

    echo "Baseline date: {$summary['baseline_date']}\n";

    echo "Source GN records: {$summary['source_gn_count']}\n";

    echo "Current GN Locations: {$summary['current_gn_count']}\n";

    echo "Legacy references: {$summary['legacy_reference_count']}\n";

    echo "Distinct referenced GN Locations: {$summary['distinct_referenced_gn_count']}\n";

    echo "Target GNs with multiple references: {$summary['duplicate_target_gn_count']}\n";

    echo "References requiring separation: {$summary['references_requiring_separation']}\n";

    echo "GN Locations requiring creation: {$summary['new_gn_locations_required']}\n";

    echo "Retained GN Locations requiring correction: {$summary['retained_gn_locations_to_correct']}\n";

    echo "Expected final GN count: {$summary['expected_final_gn_count']}\n";

    echo "Expected ASC->GN relationships: {$summary['expected_asc_gn_relationships']}\n";

    echo "Expected ARPA->GN relationships: {$summary['expected_arpa_gn_relationships']}\n";

    echo "Legacy ARPA/ASC mismatches preserved as warnings: {$summary['legacy_arpa_asc_mismatch_count']}\n";

    echo "Warning gnd_ids: "
        . implode(
            ',',
            $summary['legacy_arpa_asc_mismatch_gnd_ids']
        )
        . "\n";

    echo "Validation errors: {$summary['errors']}\n";

    if ($summary['mode'] === 'EXECUTE') {
        echo "GN Locations created: {$summary['new_gn_locations_created']}\n";

        echo "GN Locations synchronized: {$summary['gn_locations_corrected']}\n";

        echo "ASC->GN relationships created: {$summary['asc_gn_relationships_created']}\n";

        echo "ARPA->GN relationships created: {$summary['arpa_gn_relationships_created']}\n";

        echo "Final GN count: {$summary['final_gn_count']}\n";

        echo "Final distinct referenced GN count: {$summary['final_distinct_referenced_gn_count']}\n";

        echo "Remaining duplicate references: {$summary['remaining_duplicate_references']}\n";

        echo "Final correct gnd_lcode count: {$summary['final_correct_lcode_count']}\n";

        echo "Final ASC->GN mapping count: {$summary['final_asc_gn_relationship_count']}\n";

        echo "Final ARPA->GN mapping count: {$summary['final_arpa_gn_relationship_count']}\n";

    }

    if ($summary['error_messages'] !== []) {
        echo "Errors:\n";

        foreach (
            $summary['error_messages']
            as $message
        ) {
            echo "  - {$message}\n";
        }
    }

    echo "Report: {$summary['report_path']}\n";

    exit(
        $summary['errors'] === 0
            ? 0
            : 1
    );
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'Legacy GN by-ID repair FAILED: '
        . $exception->getMessage()
        . PHP_EOL
    );

    exit(1);
}
