<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\LegacyDatabase;
use App\Services\LegacyOfficer\HistoricalArpaOfficerExtensionService;

require dirname(__DIR__).'/bootstrap.php';
date_default_timezone_set((string)config('app.timezone','UTC'));
$o=getopt('',['dry-run','execute','batch-size:','help']);
if(isset($o['help'])){echo "Usage: php bin/migrate-historical-arpa-officers.php --dry-run|--execute [--batch-size=500]\nOfficer Master extension only; never creates appointments, assignments, users, roles, or scopes.\n";exit(0);}
$dry=isset($o['dry-run']);$execute=isset($o['execute']);if($dry===$execute){fwrite(STDERR,"Choose exactly one of --dry-run or --execute.\n");exit(2);}$batch=$o['batch-size']??'500';if(!is_string($batch)||!ctype_digit($batch)||(int)$batch<1||(int)$batch>10000){fwrite(STDERR,"Invalid batch size.\n");exit(2);}
try{$cfg=config('legacy_database');$s=(new HistoricalArpaOfficerExtensionService(LegacyDatabase::pdo(),Database::pdo(),$dry,(int)$batch,is_string($cfg['officer_effective_from']??null)?$cfg['officer_effective_from']:null))->run();
    echo "Historical ARPA Officer extension {$s['status']} ({$s['mode']})\n";
    $labels=['Stable extension population'=>'historical_extension_population','Extension already mapped'=>'historical_extension_mapped','Extension unmapped'=>'historical_extension_unmapped','Would create'=>'would_create','Would attach reference'=>'would_update','Created'=>'created','References attached'=>'updated','Invalid NIC'=>'invalid_nic','NULL/missing NIC'=>'missing_nic','Duplicate NIC'=>'duplicate_nic','Class I'=>'class_i','Class II'=>'class_ii','Class III'=>'class_iii','Class NULL Select'=>'class_null_select','Unknown grades'=>'unknown_grades','Service permanent'=>'service_permanent','Service not permanent'=>'service_not_permanent','Service permanency unknown'=>'service_permanency_unknown','Legacy ACTIVE'=>'legacy_active','Legacy INACTIVE'=>'legacy_inactive','Warnings'=>'warnings','Errors'=>'errors','True blockers'=>'true_blockers'];foreach($labels as $label=>$key)echo "{$label}: {$s[$key]}\n";
    echo "Number category: {$s['number_category']} / {$s['number_category_code']}\nOut-of-scope unchanged: ".($s['out_of_scope_counts']['unchanged']?'YES':'NO')."\nReport: {$s['report_path']}\n";exit($s['true_blockers']>0?3:0);
}catch(Throwable $e){fwrite(STDERR,'Historical Officer extension failed: '.$e->getMessage()."\n");exit(1);}
