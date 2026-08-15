<?php
declare(strict_types=1);

use App\Core\{Database, LegacyDatabase};
use App\Services\LegacyAppointment\FinalLegacyArpaMigrationAuditService;

require dirname(__DIR__).'/bootstrap.php';

$options = getopt('', ['read-only']);
if (!array_key_exists('read-only', $options)) {
    fwrite(STDERR, "This command is audit-only. Use --read-only.\n");
    exit(2);
}

$report = (new FinalLegacyArpaMigrationAuditService(Database::pdo(), LegacyDatabase::pdo()))->audit(true);

echo "FINAL LEGACY ARPA APPOINTMENT MIGRATION AUDIT\n";
echo "Mode: READ ONLY\n\n";
echo "Raw source rows: {$report['raw_source']['combined']}\n";
echo "Reconciled business records: {$report['reconciliation']['business_records']}\n";
echo "Migrated business records: {$report['target_distribution']['migrated_business_records']}\n";
echo "Manual review business records: {$report['manual_review']['count']}\n\n";
foreach ($report['accounting_equations'] as $key => $equation) {
    echo "Equation {$key}: ".($equation['pass'] ? 'PASS' : 'FAIL')." - {$equation['expression']}\n";
}
echo "\n";
foreach ($report['statuses'] as $key => $value) {
    echo strtoupper(str_replace('_', ' ', $key)).": {$value}\n";
}
echo "\nReports:\n";
foreach ($report['report_paths'] as $path) echo $path."\n";
