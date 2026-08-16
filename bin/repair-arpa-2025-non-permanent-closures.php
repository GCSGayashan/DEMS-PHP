<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\Arpa2025NonPermanentClosureRepairService;

require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', [
    'dry-run',
    'execute',
    'actor-user-id:',
    'backup:',
]);

$dryRun = isset($options['dry-run']);
$execute = isset($options['execute']);

if ($dryRun === $execute) {
    fwrite(
        STDERR,
        "Specify exactly one of --dry-run or --execute.\n"
    );
    exit(2);
}

$service = new Arpa2025NonPermanentClosureRepairService(
    Database::pdo()
);

if ($dryRun) {
    $result = $service->dryRun();

    echo "ARPA 2025 non-permanent appointment closure repair (DRY RUN)\n";
    echo "================================================================\n";
    echo "State                     : {$result['state']}\n";
    echo "Start window              : 2025-01-01 to 2025-12-31\n";
    echo "Target year-end date      : 2025-12-31\n";
    echo "Permanent appointments    : NEVER MODIFY\n";
    echo "Earlier closures          : PRESERVE EXISTING DATE\n\n";

    $s = $result['summary'];

    echo "Non-permanent 2025        : {$s['non_permanent_2025']}\n";
    echo "Permanent 2025            : {$s['permanent_2025']}\n";
    echo "Open / need new closure   : {$s['open_without_closure']}\n";
    echo "Ended before target       : {$s['closed_before_target']} (PRESERVE)\n";
    echo "Already target date       : {$s['already_target_date']} (PRESERVE)\n";
    echo "Ended after target        : {$s['closed_after_target']}\n";
    echo "Rows requiring change     : {$s['rows_requiring_change']}\n";
    echo "Invalid date ranges       : {$s['invalid_date_ranges']}\n";
    echo "Earlier closure outside 2025: {$s['earlier_closures_outside_2025']}\n";

    echo "\nEnd reason\n";
    echo "----------\n";
    echo $result['end_reason']['exists']
        ? "END_OF_APPOINTMENT_PERIOD : PRESENT\n"
        : "END_OF_APPOINTMENT_PERIOD : NOT YET PRESENT\n";

    echo "\nValidation errors: " . count($result['errors']) . "\n";

    foreach ($result['errors'] as $error) {
        echo " - {$error}\n";
    }

    exit($result['errors'] === [] ? 0 : 1);
}

$actor = trim((string)($options['actor-user-id'] ?? ''));
$backup = trim((string)($options['backup'] ?? ''));

if ($actor === '' || $backup === '') {
    fwrite(
        STDERR,
        "Execution requires --actor-user-id=<uuid> and --backup=<mysql-dump-path>.\n"
    );
    exit(2);
}

try {
    $result = $service->execute($actor, $backup);

    echo "ARPA 2025 non-permanent appointment closure repair (EXECUTE)\n";
    echo "================================================================\n";
    echo "State                     : {$result['state']}\n";
    echo "New closures created      : {$result['created_closures']}\n";
    echo "Later closures corrected  : {$result['corrected_later_closures']}\n";
    echo "Correction ledger rows    : {$result['correction_ledger_rows']}\n";
    echo "Audit rows                 : {$result['audit_rows']}\n";
    echo "Existing closures preserved: {$result['preserved_existing_closures']}\n";
    echo "Backup SHA-256            : {$result['backup']['sha256']}\n";

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}
