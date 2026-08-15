<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$ok=true;
$checks=[];
$checks['PHP >= 8.2']=version_compare(PHP_VERSION,'8.2.0','>=');
$checks['PDO extension']=extension_loaded('pdo');
$checks['PDO MySQL extension']=extension_loaded('pdo_mysql');
$checks['Fileinfo extension']=extension_loaded('fileinfo');
$checks['OpenSSL extension']=extension_loaded('openssl');
$checks['.env exists']=is_file(BASE_PATH.'/.env');
$checks['storage writable']=is_writable(BASE_PATH.'/storage');

foreach($checks as $label=>$passed){printf("%-28s %s\n",$label,$passed?'OK':'FAIL');if(!$passed)$ok=false;}
if($checks['PDO MySQL extension'] && $checks['.env exists']){
    try{App\Core\Database::pdo()->query('SELECT 1');echo "Database connection          OK\n";}catch(Throwable $e){echo "Database connection          FAIL: {$e->getMessage()}\n";$ok=false;}
}
exit($ok?0:1);
