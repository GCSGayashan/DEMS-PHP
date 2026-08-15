<?php
declare(strict_types=1);

use App\Core\{Database,LegacyDatabase};
use App\Services\LegacyLocation\LocationBaselineEffectiveDateCorrectionService;

require dirname(__DIR__).'/bootstrap.php';

// The proposal CSV can contain tens of thousands of Location relationships.
ini_set('memory_limit','512M');

$options=getopt('', ['dry-run','execute','executor:','backup-file:']);
$dryRun=array_key_exists('dry-run',$options);
$execute=array_key_exists('execute',$options);
if($dryRun===$execute){
    fwrite(STDERR,"Use exactly one of --dry-run or --execute.\n");
    fwrite(STDERR,"Dry run: php bin/correct-location-baseline-effective-dates.php --dry-run\n");
    fwrite(STDERR,"Execute: php bin/correct-location-baseline-effective-dates.php --execute --executor=<authorized-system-user-uuid> --backup-file=<fresh-external-backup.sql>\n");
    exit(2);
}

try{
    $service=new LocationBaselineEffectiveDateCorrectionService(Database::pdo(),LegacyDatabase::pdo());
    if($execute){
        $executor=trim((string)($options['executor']??''));
        $backup=trim((string)($options['backup-file']??''));
        if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$executor)!==1||$backup===''){
            throw new InvalidArgumentException('--execute requires a valid --executor UUID and --backup-file path.');
        }
        $result=$service->execute($executor,$backup);
        echo "LOCATION BASELINE EFFECTIVE-DATE CORRECTION\n";
        echo "Status: {$result['status']}\n";
        echo 'Run ID: '.($result['run_id']??'none')."\n";
        echo 'Locations corrected: '.($result['locations_corrected']??0)."\n";
        echo 'Relationships corrected: '.($result['relationships_corrected']??0)."\n";
        echo 'Offices corrected: '.($result['offices_corrected']??0)."\n";
        exit(0);
    }

    $result=$service->dryRun();
    echo "LOCATION BASELINE EFFECTIVE-DATE CORRECTION - DRY RUN ONLY\n";
    echo "Authoritative business-effective date: {$result['baseline_date']}\n\n";
    foreach(['location_master'=>'LOCATION MASTER','location_relationships'=>'LOCATION RELATIONSHIPS'] as $key=>$heading){
        echo $heading."\n";
        foreach($result[$key] as $name=>$value){
            if($name==='by_type')continue;
            echo ucwords(str_replace('_',' ',$name)).": {$value}\n";
        }
        echo "By type:\n";
        foreach($result[$key]['by_type'] as $type=>$counts){
            $parts=[];
            foreach($counts as $name=>$value){if($name!=='name')$parts[]=str_replace('_',' ',$name).'='.$value;}
            echo "  {$type}: ".implode(', ',$parts)."\n";
        }
        echo "\n";
    }
    echo "HIERARCHY VALIDATION\n";
    foreach($result['hierarchy_validation']['checks'] as $type=>$check){
        echo "  {$type}: children={$check['children']}, missing={$check['missing']}, one parent={$check['one_parent']}, multiple parents={$check['multiple_parents']}\n";
    }
    echo "Type compatibility errors: {$result['hierarchy_validation']['type_compatibility_errors']}\n";
    echo "Required missing parents: {$result['hierarchy_validation']['missing_required_parents']}\n";
    echo "Required ambiguous parents: {$result['hierarchy_validation']['ambiguous_required_parents']}\n\n";
    echo "OFFICES\n";
    foreach($result['offices'] as $name=>$value){
        if(in_array($name,['by_type','old_to_new_examples'],true))continue;
        echo ucwords(str_replace('_',' ',$name)).": {$value}\n";
    }
    echo "By type:\n";
    foreach($result['offices']['by_type'] as $type=>$counts){
        echo "  {$type}: total={$counts['total']}, examined={$counts['examined']}, already correct={$counts['already_2024_01_05']}, would update={$counts['would_change']}, blockers={$counts['blockers']}\n";
    }
    echo "Examples:\n";
    foreach($result['offices']['old_to_new_examples'] as $change=>$count)echo "  {$change}: {$count}\n";
    echo "\nAPPOINTMENT HIERARCHY ISSUE PROJECTION - APPOINTMENTS ARE READ ONLY\n";
    foreach($result['appointment_hierarchy_issues'] as $name=>$value)echo ucwords(str_replace('_',' ',$name)).": {$value}\n";
    echo "\nBLOCKERS\nTotal: {$result['blockers']['total']}\n";
    foreach($result['blockers']['records'] as $blocker)echo '  '.json_encode($blocker,JSON_UNESCAPED_SLASHES)."\n";
    echo "\nDRY-RUN INTEGRITY\nTarget and legacy unchanged: ".($result['integrity']['dry_run_target_and_legacy_unchanged']?'YES':'NO')."\n";
    echo "Appointment rows changed: {$result['safety']['appointment_rows_changed']}\n";
    echo "Officer rows changed: {$result['safety']['officer_rows_changed']}\n";
    echo "User rows changed: {$result['safety']['user_rows_changed']}\n";
    echo "Role/scope rows changed: {$result['safety']['role_scope_rows_changed']}\n";
    echo "Legacy source rows changed: {$result['safety']['legacy_source_rows_changed']}\n";
    foreach($result['report_paths'] as $format=>$path)echo strtoupper($format)." report: {$path}\n";
}catch(Throwable $error){
    fwrite(STDERR,'Location baseline correction failed: '.$error->getMessage()."\n");
    exit(1);
}
