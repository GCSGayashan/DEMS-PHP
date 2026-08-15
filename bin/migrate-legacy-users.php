<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\LegacyDatabase;
use App\Services\LegacyUser\LegacyUserMigrationService;

require dirname(__DIR__).'/bootstrap.php';
date_default_timezone_set((string)config('app.timezone','UTC'));

function legacyUserUsage(): void
{
    echo <<<'TXT'
DEMS legacy user identity/detail migration

Usage:
  php bin/migrate-legacy-users.php --dry-run [--batch-size=500]
  php bin/migrate-legacy-users.php --execute [--batch-size=500]

Imported identities are disabled historical accounts with no password, role,
permission, scope, or operational assignment. Legacy application subjects are
retained only as historical access metadata and never enter Subject Master.
TXT;
    echo PHP_EOL;
}

$options=getopt('',['dry-run','execute','batch-size:','help']);
if(isset($options['help'])){legacyUserUsage();exit(0);}
$dry=array_key_exists('dry-run',$options);$execute=array_key_exists('execute',$options);
if($dry===$execute){fwrite(STDERR,"Choose exactly one of --dry-run or --execute.\n\n");legacyUserUsage();exit(2);}
$batch=$options['batch-size']??'500';
if(!is_string($batch)||!ctype_digit($batch)||(int)$batch<1||(int)$batch>10000){fwrite(STDERR,"--batch-size must be an integer between 1 and 10000.\n");exit(2);}

try{
    $summary=(new LegacyUserMigrationService(LegacyDatabase::pdo(),Database::pdo(),$dry,(int)$batch))->run();
    echo "Legacy User migration {$summary['status']} ({$summary['mode']})\n";
    echo "Run ID: {$summary['run_id']}\n";
    $labels=[
        'Legacy tbl_user count'=>'legacy_user_count','Existing target users'=>'existing_target_users',
        'Existing legacy references'=>'existing_legacy_references','Already migrated/mapped'=>'already_migrated',
        'Matched existing users'=>'matched_existing_users','New users to create'=>'new_users_to_create',
        'Would update'=>'would_update','Created'=>'created','Legacy mappings created'=>'mappings_created',
        'Officer-linked users'=>'officer_linked_users','Users without officer link'=>'users_without_officer_link',
        'Ambiguous officer mappings'=>'ambiguous_officer_mappings','Missing officer mappings'=>'missing_officer_mappings',
        'Username collisions'=>'username_collisions','Missing usernames'=>'missing_usernames',
        'Legacy usernames used'=>'legacy_usernames_used','Generated technical usernames used'=>'generated_usernames_used',
        'Duplicate email values'=>'duplicate_email_users','Invalid/digest emails nulled'=>'invalid_emails','Missing emails'=>'missing_emails',
        'Target email users'=>'target_email_users','NULL target email users'=>'null_email_users','Missing/invalid phones'=>'missing_phones',
        'Missing required data'=>'missing_required_data','Manual-review users'=>'manual_review_users',
        'Invalid-source users'=>'invalid_source_users','Users that will remain disabled'=>'disabled_users',
        'Workflow user IDs referenced'=>'workflow_user_ids_referenced','Workflow user IDs found in tbl_user'=>'workflow_user_ids_found',
        'Workflow user IDs resolved'=>'workflow_user_ids_resolved','Workflow user IDs unresolved'=>'workflow_user_ids_unresolved',
        'Orphan user mapping IDs'=>'orphan_user_mapping_ids','Stale auxiliary mapping rows'=>'stale_auxiliary_mapping_rows',
        'Users without organization context'=>'users_without_organization_context','Historical organization context rows'=>'organization_context_rows',
        'Exactly mapped location contexts'=>'mapped_location_context_rows','Legacy access metadata rows'=>'legacy_access_metadata_rows',
        'Critical blockers'=>'critical_blockers','Warnings'=>'warnings','Errors'=>'errors',
    ];
    foreach($labels as $label=>$key)echo "{$label}: {$summary[$key]}\n";
    echo "Workflow coverage: {$summary['workflow_coverage_percent']}%\n";
    echo "Workflow field coverage:\n";
    foreach($summary['workflow_fields'] as $field){
        echo "  {$field['source_table']}.{$field['field']}: distinct={$field['distinct_non_null']}, found={$field['found_in_tbl_user']}, resolved={$field['resolved']}, unresolved={$field['unresolved']}\n";
    }
    echo 'Protected current users/roles/scopes/subjects/appointments unchanged: '.($summary['protected_state']['unchanged']?'YES':'NO').PHP_EOL;
    echo "CSV report: {$summary['report_path']}\nJSON report: {$summary['json_report_path']}\n";
    exit($summary['critical_blockers']>0?3:($summary['errors']>0?1:0));
}catch(Throwable $e){fwrite(STDERR,'Legacy User migration FAILED: '.$e->getMessage().PHP_EOL);exit(1);}
