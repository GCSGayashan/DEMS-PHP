<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Core\Csrf;

if(($argv[1]??'')==='--child'){
    [$session,$post]=json_decode(base64_decode($argv[2]),true,512,JSON_THROW_ON_ERROR);$_SESSION=$session;$_POST=$post;http_response_code(200);
    register_shutdown_function(static fn()=>print('\nSTATUS='.http_response_code()));Csrf::validate();echo 'MUTATION_REACHED';exit;
}
$assertions=0;
$same=static function($e,$a,$m)use(&$assertions){$assertions++;if($e!==$a)throw new RuntimeException($m);};
try{
    foreach([[[],[]], [['_csrf'=>null],['_csrf'=>null]], [['_csrf'=>''],['_csrf'=>'']], [['_csrf'=>'valid'],[]], [[],['_csrf'=>'valid']], [['_csrf'=>'valid'],['_csrf'=>'wrong']], [['_csrf'=>['x']],['_csrf'=>['x']]], [['_csrf'=>true],['_csrf'=>true]], [['_csrf'=>123],['_csrf'=>123]], [['_csrf'=>'valid'],['_csrf'=>['valid']]]] as [$session,$post]){
        $pipes=[];$p=proc_open([PHP_BINARY,'-d','xdebug.mode=off',__FILE__,'--child',base64_encode(json_encode([$session,$post]))],[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$same(0,proc_close($p),'controlled validation exit');$same('',trim($err),'malformed tokens produce no PHP warning');$same(true,str_contains($out,'STATUS=419'),'invalid token returns 419');$same(false,str_contains($out,'MUTATION_REACHED'),'invalid token stops mutation');
    }
    $_SESSION=['_csrf'=>'opaque-valid-token'];$_POST=['_csrf'=>'opaque-valid-token'];Csrf::validate();$same('opaque-valid-token',Csrf::token(),'valid existing token retained');
    foreach([null,'',['bad'],true,123] as $invalid){$_SESSION=['_csrf'=>$invalid];$token=Csrf::token();$same(64,strlen($token),'invalid stored token regenerated');$same(true,ctype_xdigit($token),'cryptographic token is hexadecimal');$same($token,Csrf::token(),'generated token stable within session');}
    echo 'CsrfSecurityTest: '.$assertions." assertions passed; no database accessed.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
