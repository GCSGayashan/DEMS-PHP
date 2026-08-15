<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Core\{Database,LegacyDatabase};
use App\Services\LegacyOfficer\LegacyArpaCurrentOfficeBackfillService;

$options=getopt('',['dry-run','execute','exclude-unresolved','as-of:','help']);
if(isset($options['help'])){echo "Usage:\n  php bin/backfill-current-arpa-asc-offices.php --dry-run [--as-of=YYYY-MM-DD]\n  php bin/backfill-current-arpa-asc-offices.php --execute [--exclude-unresolved] [--as-of=YYYY-MM-DD]\n";exit(0);}
$dry=isset($options['dry-run']);$execute=isset($options['execute']);if($dry===$execute){fwrite(STDERR,"Specify exactly one of --dry-run or --execute.\n");exit(2);}
$asOf=(string)($options['as-of']??date('Y-m-d'));$service=new LegacyArpaCurrentOfficeBackfillService(LegacyDatabase::pdo(),Database::pdo(),$asOf);
try{
    if($execute){$result=$service->execute(isset($options['exclude-unresolved']));echo "Execution run ID: {$result['run_id']}\nCreated: {$result['created']}\nPrimary Offices synchronized: {$result['primary_synchronized']}\n";exit(0);}
    $r=$service->dryRun();echo "Current ARPA Officer -> ASC Office Backfill (DRY-RUN / ZERO WRITES)\nAs of: {$r['as_of']}\n";
    echo "Total qualifying source appointment rows: {$r['source']['qualifying_appointment_rows']}\nDistinct legacy Officers: {$r['source']['distinct_legacy_officers']}\nDistinct target Officers: {$r['source']['distinct_target_officers']}\nDistinct ASCs: {$r['source']['distinct_ascs']}\n";
    foreach(['EXACT_ONE_ASC','MULTIPLE_ASC','NO_ASC','target_officer_unresolved','target_asc_unresolved','target_asc_office_unresolved'] as $k)echo str_replace('_',' ',ucwords(strtolower($k),'_')).": {$r['classification'][$k]}\n";
    echo "Already assigned: {$r['existing']['already_assigned']}\nWould create: {$r['projection']['would_create']}\nExisting primary review: {$r['existing']['existing_primary_review']}\nOther ASC Office assignment: {$r['existing']['assigned_to_another_asc_office']}\nOther concurrent Office assignment: {$r['existing']['with_other_concurrent_office_assignments']}\nManual review: {$r['projection']['manual_review']}\nTrue execution blockers: {$r['projection']['true_execution_blockers']}\n";
    echo "\nBY ASC\n";foreach($r['by_asc'] as $a)echo "{$a['asc_dad']} | {$a['asc_name']} | {$a['office_dad']} | {$a['distinct_officers']}\n";
    echo "\nKURUNEGALA DETAIL ({$r['reports']['kur']})\n";foreach($r['kurunegala'] as $p)echo "{$p['officer_dad']} | {$p['officer_name']} | legacy ".implode(',',$p['legacy_officer_ids'])." | rows {$p['supporting_row_count']} | ".implode(', ',$p['supporting_divisions'])." | {$p['primary_status']}\n";
    echo "\nSpecial functions excluded: ".json_encode($r['special_functions_excluded'],JSON_UNESCAPED_SLASHES)."\nSource unchanged: ".($r['zero_write_verification']['source_unchanged']?'YES':'NO')."\nTarget unchanged: ".($r['zero_write_verification']['target_unchanged']?'YES':'NO')."\nJSON: {$r['reports']['json']}\nBy ASC CSV: {$r['reports']['csv']}\n";exit(0);
}catch(Throwable $e){fwrite(STDERR,'Backfill failed: '.$e->getMessage()."\n");exit(1);}
