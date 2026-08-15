<?php
declare(strict_types=1);

use App\Core\{Database,LegacyDatabase};
use App\Services\LegacyAppointment\LegacyArpaReconciliationService;

require dirname(__DIR__).'/bootstrap.php';
$options=getopt('',['refresh','help']);if(isset($options['help'])){echo "Usage: php bin/sync-legacy-arpa-reconciliation.php --refresh\nRefreshes review items only. It never migrates appointments or changes the legacy database.\n";exit(0);}if(!isset($options['refresh'])){fwrite(STDERR,"Specify --refresh.\n");exit(2);}
try{$result=(new LegacyArpaReconciliationService(LegacyDatabase::pdo(),Database::pdo()))->refresh();echo "Legacy ARPA reconciliation queues refreshed.\nSpecial ASC items: {$result['special']}\nMissing ARPA location items: {$result['missing_arpa']}\nCurrent conflict items: {$result['conflicts']}\nTotal review items: {$result['items']}\nAppointments inserted: 0\nSubject assignments inserted: 0\n";exit(0);}catch(Throwable $e){fwrite(STDERR,'Refresh failed: '.$e->getMessage()."\n");exit(1);}
