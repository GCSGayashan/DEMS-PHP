<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Core\Database;
use App\Services\ArpaAscApprovedOperationalBackfillService;

$options=getopt('',['preview','execute','limit:','help']);
if(isset($options['help'])){echo "Usage:\n  php bin/backfill-arpa-asc-approved-operational.php --preview [--limit=100]\n  php bin/backfill-arpa-asc-approved-operational.php --execute [--limit=100]\n";exit(0);}
$preview=isset($options['preview']);$execute=isset($options['execute']);
if($preview===$execute){fwrite(STDERR,"Specify exactly one of --preview or --execute.\n");exit(2);}
$limit=filter_var($options['limit']??100,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>ArpaAscApprovedOperationalBackfillService::MAX_LIMIT]]);
if($limit===false){fwrite(STDERR,"--limit must be between 1 and ".ArpaAscApprovedOperationalBackfillService::MAX_LIMIT.".\n");exit(2);}
try{
    $result=(new ArpaAscApprovedOperationalBackfillService(Database::pdo()))->run($execute,(int)$limit);
    echo "ASC-approved Operational ARPA Backfill ({$result['mode']})\nRun ID: {$result['run_id']}\nBatch limit: {$result['limit']}\n";
    foreach($result['summary'] as $key=>$value)echo ucwords(str_replace('_',' ',$key)).": {$value}\n";
    echo "\nDETAILS\n";
    foreach($result['details'] as $row)echo implode(' | ',[(string)$row['request_id'],(string)$row['request_type'],(string)$row['workflow_status'],(string)$row['officer_number'],(string)$row['result'],(string)$row['reason']])."\n";
    exit($result['summary']['failed']>0?1:0);
}catch(Throwable $e){fwrite(STDERR,'Backfill failed: '.$e->getMessage()."\n");exit(1);}
