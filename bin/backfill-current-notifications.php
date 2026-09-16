<?php
declare(strict_types=1);
use App\Core\Database;
use App\Services\NotificationBackfillService;
require dirname(__DIR__).'/bootstrap.php';
$execute=in_array('--execute',$argv,true);$report=(new NotificationBackfillService(Database::pdo()))->run($execute);
echo ($execute?'EXECUTE':'DRY-RUN')." current pending notification discovery\n";
foreach($report as $key=>$value)echo str_pad($key,28).": {$value}\n";
