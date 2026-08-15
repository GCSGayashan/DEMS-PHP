<?php
declare(strict_types=1);

use App\Core\{Database,LegacyDatabase};
use App\Services\LegacyAppointment\LegacyArpaAppointmentPreviewService;

require dirname(__DIR__).'/bootstrap.php';
$options=getopt('',['refresh','help']);if(isset($options['help'])){echo "Usage: php bin/refresh-legacy-arpa-appointment-preview.php --refresh\nBuilds a derived read-only preview index. It never migrates appointments or changes the legacy database.\n";exit(0);}if(!isset($options['refresh'])){fwrite(STDERR,"Specify --refresh.\n");exit(2);}
try{$r=(new LegacyArpaAppointmentPreviewService(LegacyDatabase::pdo(),Database::pdo()))->refreshIndex();echo "Legacy ARPA appointment preview refreshed.\nReconciled business records: {$r['count']}\nAppointments migrated: 0\nSubject assignments migrated: 0\nSithamu periods migrated: 0\n";exit(0);}catch(Throwable $e){fwrite(STDERR,'Preview refresh failed: '.$e->getMessage()."\n");exit(1);}
