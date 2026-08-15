<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\LegacyAppointment\LegacyArpaLocationRepairService;

require dirname(__DIR__).'/bootstrap.php';

$options = getopt('', ['dry-run', 'execute', 'executor:']);
$dryRun = array_key_exists('dry-run', $options);
$execute = array_key_exists('execute', $options);
if ($dryRun === $execute) {
    fwrite(STDERR, "Use exactly one of --dry-run or --execute.\n");
    exit(2);
}

$service = new LegacyArpaLocationRepairService(Database::pdo());
if ($execute) {
    $executor = trim((string)($options['executor'] ?? ''));
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $executor) !== 1) {
        fwrite(STDERR, "--execute requires --executor=<authorized-system-user-uuid>.\n");
        exit(2);
    }
    $result = $service->execute($executor);
    echo "Legacy ARPA location repair: {$result['status']}\n";
    echo 'Run ID: '.($result['run_id'] ?? 'none')."\n";
    if (isset($result['counts'])) {
        foreach ($result['counts'] as $key => $value) {
            echo ucwords(str_replace('_', ' ', $key)).": {$value}\n";
        }
    }
    exit(0);
}

$result = $service->dryRun();
echo "LEGACY ARPA APPOINTMENT LOCATION REPAIR - DRY RUN ONLY\n\n";
foreach (['request_layer' => 'REQUEST LAYER', 'operational_layer' => 'OPERATIONAL LAYER', 'validation' => 'VALIDATION', 'snapshots' => 'SNAPSHOTS', 'integrity' => 'INTEGRITY'] as $key => $heading) {
    echo $heading."\n";
    foreach ($result[$key] as $name => $value) {
        echo ucwords(str_replace('_', ' ', $name)).': '.(is_bool($value) ? ($value ? 'YES' : 'NO') : $value)."\n";
    }
    echo "\n";
}
echo "Manual Review: {$result['manual_review']}\n\n";
foreach (['before', 'after'] as $state) {
    echo strtoupper($state)." COLLISION / ISSUE PROJECTION\n";
    foreach ($result['collision_projection'][$state] as $name => $value) {
        echo ucwords(str_replace('_', ' ', $name)).": {$value}\n";
    }
    echo "\n";
}
echo "WEWAGEDARA\n";
echo json_encode($result['wewagedara'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";
foreach ($result['report_paths'] as $format => $path) {
    echo strtoupper($format).' Report: '.$path."\n";
}
