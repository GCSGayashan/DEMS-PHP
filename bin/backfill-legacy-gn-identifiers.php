<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\LegacyDatabase;
use App\Services\LegacyLocation\LegacyGnIdentifierBackfillService;

require dirname(__DIR__).'/bootstrap.php';
$options=getopt('',['dry-run','execute','help']);
if(isset($options['help'])){echo "Usage: php bin/backfill-legacy-gn-identifiers.php --dry-run|--execute\n";exit(0);}
$dryRun=array_key_exists('dry-run',$options);$execute=array_key_exists('execute',$options);
if($dryRun===$execute){fwrite(STDERR,"Choose exactly one of --dry-run or --execute.\n");exit(2);}
try{
    $summary=(new LegacyGnIdentifierBackfillService(LegacyDatabase::pdo(),Database::pdo(),$dryRun))->run();
    echo "Legacy GN identifier backfill ({$summary['mode']})\n";
    foreach($summary as $key=>$value)if($key!=='mode')echo ucwords(str_replace('_',' ',$key)).": {$value}\n";
    exit($summary['true_blockers']===0?0:1);
}catch(Throwable $e){fwrite(STDERR,'Legacy GN identifier backfill FAILED: '.$e->getMessage().PHP_EOL);exit(1);}
