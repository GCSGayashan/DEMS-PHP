<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\LegacyDatabase;
use App\Services\LegacyOfficer\LegacyArpaOfficerMigrationService;

require dirname(__DIR__) . '/bootstrap.php';
date_default_timezone_set((string)config('app.timezone', 'UTC'));

function legacyOfficerUsage(): void
{
    echo <<<'TXT'
DEMS one-time AgrarianAdmin ARPA Officer Master migration

Usage:
  php bin/migrate-legacy-arpa-officers.php --dry-run [--batch-size=500]
  php bin/migrate-legacy-arpa-officers.php --execute [--batch-size=500]

This command migrates Officer master profiles only. It never creates Officer
Appointments, assignments, offices, system users, roles, permissions, or scopes.
Dry-run performs no target database writes and allocates no DAD numbers.
TXT;
    echo PHP_EOL;
}

$options = getopt('', ['dry-run', 'execute', 'batch-size:', 'help']);
if (isset($options['help'])) {
    legacyOfficerUsage();
    exit(0);
}
$dryRun = array_key_exists('dry-run', $options);
$execute = array_key_exists('execute', $options);
if ($dryRun === $execute) {
    fwrite(STDERR, "Choose exactly one of --dry-run or --execute.\n\n");
    legacyOfficerUsage();
    exit(2);
}
$batchRaw = $options['batch-size'] ?? '500';
if (!is_string($batchRaw) || !ctype_digit($batchRaw) || (int)$batchRaw < 1 || (int)$batchRaw > 10000) {
    fwrite(STDERR, "--batch-size must be an integer between 1 and 10000.\n");
    exit(2);
}

try {
    $legacyConfig = config('legacy_database');
    $service = new LegacyArpaOfficerMigrationService(
        LegacyDatabase::pdo(),
        Database::pdo(),
        $dryRun,
        (int)$batchRaw,
        is_string($legacyConfig['officer_effective_from'] ?? null) ? $legacyConfig['officer_effective_from'] : null
    );
    $summary = $service->run();

    $labels = [
        'Source ARPA appointment rows' => 'source_appointment_rows',
        'Distinct source officers' => 'distinct_source_officers',
        'Matched source officer masters' => 'matched_source_officer_masters',
        'Existing legacy references' => 'existing_legacy_references',
        'Already migrated' => 'already_migrated',
        'Would create' => 'would_create',
        'Would update' => 'would_update',
        'Created' => 'created',
        'Updated' => 'updated',
        'Skipped' => 'skipped',
        'Missing NIC' => 'missing_nic',
        'Duplicate NIC' => 'duplicate_nic',
        'Invalid NIC' => 'invalid_nic',
        'Safely cleaned NIC fields' => 'safely_cleaned_nic',
        'Invalid NIC fields nulled' => 'invalid_nic_fields_nulled',
        'Missing NIC fields nulled' => 'missing_nic_fields_nulled',
        'Duplicate NIC fields nulled' => 'duplicate_nic_fields_nulled',
        'Missing DOB' => 'missing_dob',
        'Invalid/zero DOB' => 'invalid_dob',
        'Invalid gender' => 'invalid_gender',
        'Invalid gender fields nulled' => 'invalid_gender_fields_nulled',
        'Missing name' => 'missing_name',
        'Missing name with initials' => 'missing_name_with_initials',
        'Missing full name' => 'missing_full_name',
        'Missing address' => 'missing_address',
        'Missing addresses nulled' => 'missing_address_fields_nulled',
        'Missing phone' => 'missing_phone',
        'Missing email' => 'missing_email',
        'Missing emails nulled' => 'missing_email_fields_nulled',
        'Invalid email' => 'invalid_email',
        'Invalid emails nulled' => 'invalid_email_fields_nulled',
        'Duplicate/shared email officers' => 'duplicate_email',
        'Shared emails nulled' => 'shared_email_fields_nulled',
        'Missing initial appointment date' => 'missing_initial_appointment_date',
        'Invalid/zero initial appointment date' => 'invalid_initial_appointment_date',
        'Missing/invalid initial appointment dates nulled' => 'initial_appointment_date_fields_nulled',
        'Legacy ACTIVE count' => 'legacy_active',
        'Legacy INACTIVE count' => 'legacy_inactive',
        'Class I' => 'class_i',
        'Class II' => 'class_ii',
        'Class III' => 'class_iii',
        'Class NULL due to Select' => 'class_null_select',
        'Unknown grades' => 'unknown_grades',
        'Warnings' => 'warnings',
        'Errors' => 'errors',
    ];
    echo "Legacy ARPA Officer Master migration {$summary['status']} ({$summary['mode']})\n";
    echo "Run ID: {$summary['run_id']}\n";
    echo "Number category: {$summary['number_category']} / {$summary['number_category_code']}\n";
    foreach ($labels as $label => $key) {
        echo $label . ': ' . $summary[$key] . PHP_EOL;
    }
    $invalidGenders = $summary['invalid_gender_values'];
    echo 'Invalid gender values: ' . ($invalidGenders === [] ? 'none' : json_encode($invalidGenders, JSON_UNESCAPED_UNICODE)) . PHP_EOL;
    echo 'Out-of-scope tables unchanged: ' . ($summary['out_of_scope_counts']['unchanged'] ? 'YES' : 'NO') . PHP_EOL;
    foreach ($summary['out_of_scope_counts']['after'] as $table => $count) {
        echo "  {$table}: {$count}\n";
    }
    echo "Report: {$summary['report_path']}\n";
    exit($summary['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Legacy ARPA Officer Master migration FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
