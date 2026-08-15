<?php
declare(strict_types=1);
ini_set('memory_limit','512M');
use App\Core\Database;
use App\Services\LegacyAppointment\LegacyArpaAppointmentMigrationService;
require dirname(__DIR__).'/bootstrap.php';
$options=getopt('', ['dry-run','execute']);if(!isset($options['dry-run'])&&!isset($options['execute'])){fwrite(STDERR,"Usage: php bin/migrate-legacy-arpa-appointments.php --dry-run|--execute\n");exit(2);}$execute=isset($options['execute']);$service=new LegacyArpaAppointmentMigrationService(Database::pdo());$r=$execute?$service->execute():$service->dryRun();if($execute){echo "Execution run ID: {$r['run_id']}\n";exit(0);}echo "FINAL LEGACY ARPA APPOINTMENT MIGRATION - DRY RUN ONLY\n";foreach($r['summary'] as $section=>$values){if(!is_array($values))continue;echo "\n".strtoupper(str_replace('_',' ',$section))."\n";foreach($values as $key=>$value)if(!is_array($value))echo str_replace('_',' ',ucwords($key,'_')).": {$value}\n";}echo "\nTrue execution blockers: {$r['true_execution_blockers']}\nWarnings: {$r['warnings']}\nReport: {$r['report_path']}\n";exit(0);
