<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Core\Database;
use App\Services\OfficeStructureService;

$execute=in_array('--execute',$argv,true);$dry=in_array('--dry-run',$argv,true);
if ($execute===$dry) { fwrite(STDERR,"Use exactly one of --dry-run or --execute.\n");exit(2); }
$service=new OfficeStructureService(Database::pdo());
if (!$execute) {
    $report=$service->inspect();
    echo "Office structure dry-run (zero writes)\n";
    foreach ($report as $key=>$value) echo str_replace('_',' ',ucwords($key,'_')).": {$value}\n";
    exit(0);
}
$report=$service->execute();
echo "Office structure execution\nCreated: ".count(array_filter($report['created'],fn($r)=>$r['created']))."\nErrors: ".count($report['errors'])."\n";
foreach ($report['after'] as $key=>$value) echo str_replace('_',' ',ucwords($key,'_')).": {$value}\n";
exit($report['errors']===[]?0:1);
