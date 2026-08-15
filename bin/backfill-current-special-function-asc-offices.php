<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Services\LegacyOfficer\LegacySpecialFunctionOfficeBackfillService;

$options = getopt('', ['dry-run', 'execute', 'as-of:', 'help']);
if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php bin/backfill-current-special-function-asc-offices.php --dry-run [--as-of=YYYY-MM-DD]\n";
    echo "  php bin/backfill-current-special-function-asc-offices.php --execute [--as-of=YYYY-MM-DD]\n";
    exit(0);
}

$dryRun = isset($options['dry-run']);
$execute = isset($options['execute']);
if ($dryRun === $execute) {
    fwrite(STDERR, "Specify exactly one of --dry-run or --execute.\n");
    exit(2);
}

$asOf = (string) ($options['as-of'] ?? date('Y-m-d'));
$service = new LegacySpecialFunctionOfficeBackfillService(Database::pdo(), $asOf);

try {
    if ($execute) {
        $result = $service->execute();
        echo "CURRENT SPECIAL-FUNCTION OFFICER -> ASC OFFICE BACKFILL EXECUTED\n";
        echo "Run ID: {$result['run_id']}\n";
        echo "Created: {$result['created']}\n";
        echo "Primary Offices synchronized: {$result['primary_synchronized']}\n";
        echo "Post-execution Would create: {$result['postflight']['projection']['would_create']}\n";
        echo "Post-execution Would update: {$result['postflight']['projection']['would_update']}\n";
        echo "Post-execution blockers: {$result['postflight']['projection']['true_execution_blockers']}\n";
        echo "Post-execution report: {$result['postflight']['reports']['json']}\n";
        exit(0);
    }

    $report = $service->dryRun();
    echo "CURRENT SPECIAL-FUNCTION OFFICER -> ASC OFFICE BACKFILL (DRY-RUN / ZERO DATABASE WRITES)\n";
    echo "As of: {$report['as_of']}\n";
    echo "Current special-function records examined: {$report['source']['current_special_function_records_examined']}\n";
    echo "Confirmed ASC decisions: {$report['source']['confirmed_asc_decisions']}\n";
    echo "Unresolved ASC decisions: {$report['source']['unresolved_asc_decisions']}\n";
    echo "PRESERVE_HISTORY_ONLY / ended records excluded: {$report['source']['preserve_history_only_excluded']}\n";
    echo "Distinct Officers: {$report['source']['distinct_officers']}\n";
    echo "Distinct Officer + ASC pairs: {$report['source']['distinct_officer_asc_pairs']}\n";
    echo "Already assigned: {$report['existing']['already_assigned']}\n";
    echo "Would create: {$report['projection']['would_create']}\n";
    echo "Would update: {$report['projection']['would_update']}\n";
    echo "Existing primary review: {$report['existing']['existing_primary_review']}\n";
    echo "Multiple-ASC conflicts: {$report['blocker_breakdown']['multiple_asc_conflicts']}\n";
    echo "Missing target Officer: {$report['blocker_breakdown']['missing_target_officer']}\n";
    echo "Missing target ASC: {$report['blocker_breakdown']['missing_target_asc']}\n";
    echo "Missing target ASC Office: {$report['blocker_breakdown']['missing_target_asc_office']}\n";
    echo "Unresolved confirmed-record Office blockers: {$report['blocker_breakdown']['unresolved_confirmed_record_office_blockers']}\n";
    echo "True blockers: {$report['projection']['true_execution_blockers']}\n";

    echo "\nBY FUNCTION\n";
    foreach ($report['by_function'] as $function => $counts) {
        echo sprintf(
            "%s | records %d | confirmed %d | unresolved %d | officers %d | pairs %d | assigned %d | create %d | blockers %d\n",
            $function,
            $counts['records_examined'],
            $counts['confirmed_asc_decisions'],
            $counts['unresolved_asc_decisions'],
            $counts['distinct_officers'],
            $counts['distinct_officer_asc_pairs'],
            $counts['already_assigned'],
            $counts['would_create'],
            $counts['blockers'],
        );
    }

    echo "\nBY ASC\n";
    foreach ($report['by_asc'] as $asc) {
        echo sprintf(
            "%s | %s | records %d | officers %d | assigned %d | create %d | primary review %d\n",
            $asc['asc_dad_number'],
            $asc['asc_name'],
            $asc['records'],
            $asc['distinct_officers'],
            $asc['already_assigned'],
            $asc['would_create'],
            $asc['existing_primary_review'],
        );
    }

    echo "\nTarget database unchanged: " . ($report['zero_write_verification']['target_unchanged'] ? 'YES' : 'NO') . "\n";
    echo "JSON: {$report['reports']['json']}\n";
    echo "By function CSV: {$report['reports']['by_function_csv']}\n";
    echo "By ASC CSV: {$report['reports']['by_asc_csv']}\n";
    echo "Blockers CSV: {$report['reports']['blockers_csv']}\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Special-function Office backfill failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
