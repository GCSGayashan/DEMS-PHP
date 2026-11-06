<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

// OS/DB administrators invoke this trusted CLI; no web session or reviewer is impersonated.
$flags=['help','dry-run','apply'];
$values=['operator','role-assignment','scope-assignment','manifest-hash','confirm','reason','evidence-reference','database','server-uuid'];
$options=[];
try{
    foreach(array_slice($argv,1) as $argument){
        if(!preg_match('/^--([a-z-]+)(?:=(.*))?$/sD',$argument,$m)||isset($options[$m[1]]))throw new DomainException('Use unique --option=value arguments.');
        $key=$m[1];
        if(in_array($key,$flags,true)&&!isset($m[2]))$options[$key]=true;
        elseif(in_array($key,$values,true)&&isset($m[2])&&$m[2]!=='')$options[$key]=$m[2];
        else throw new DomainException('Unsupported or incomplete command option.');
    }
    if(isset($options['help'])){
        echo "Dry-run (default; SELECT only):\n  php bin/register-arpa-historical-approvals.php --operator=<canonical-admin-id> --role-assignment=<active-system-role-assignment-id>\n\n";
        echo "Apply the reviewed manifest (DRAFT only):\n  Add --apply --database=<verified-db> --server-uuid=<verified-server-uuid> --manifest-hash=<dry-run-hash> --confirm=\"<exact dry-run confirmation>\" --reason=\"<reason>\" --evidence-reference=\"<evidence>\"\n";
        exit(0);
    }
    if(isset($options['apply'],$options['dry-run']))throw new DomainException('--apply and --dry-run are mutually exclusive.');
    foreach(['operator','role-assignment'] as $required)if(!isset($options[$required]))throw new DomainException('Supply --operator and --role-assignment from the authorized canonical administrator.');
    if(isset($options['apply']))foreach(['database','server-uuid','manifest-hash','confirm','reason','evidence-reference'] as $required)if(!isset($options[$required]))throw new DomainException('Apply requires target identity, reviewed manifest, explicit confirmation, reason and evidence reference.');
    require dirname(__DIR__).'/bootstrap.php';
    $pdo=\App\Core\Database::pdo();$actor=$options['operator'];
    $_SESSION=['user_id'=>$actor,'authenticated_at'=>time(),'last_activity_at'=>time()];
    \App\Core\Auth::forgetRequestCache();
    (new \App\Services\UserContextService($pdo))->select($actor,$options['role-assignment'],$options['scope-assignment']??null);
    \App\Core\Auth::forgetRequestCache();
    $service=new \App\Services\ArpaHistoricalApprovalRegistrationService($pdo);
    $manifest=$service->manifest($actor);
    if(isset($options['apply'])){
        if(!hash_equals($manifest['database']['database_name'],$options['database'])||!hash_equals($manifest['database']['server_uuid'],$options['server-uuid']))throw new DomainException('Connected database/server differs from the explicitly confirmed target. No writes were attempted.');
        $result=$service->apply($actor,$options['manifest-hash'],$options['confirm'],['reason'=>$options['reason'],'evidence_reference'=>$options['evidence-reference']]);
    }else $result=['mode'=>'DRY_RUN']+$manifest;
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){
    if(!$e instanceof DomainException)error_log('Historical registration failure: '.get_class($e));
    $message=$e instanceof DomainException?$e->getMessage():'Registration failed; inspect protected server logs. No database errors or connection details are printed.';
    fwrite(STDERR,$message."\n");exit(1);
}
