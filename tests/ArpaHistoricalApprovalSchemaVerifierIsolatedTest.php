<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('ARPA_HISTORICAL_RELEASE_FIXTURE_ONLY',true);
require __DIR__.'/ArpaHistoricalReleaseSecurityIsolatedTest.php';

use App\Core\{Auth,Database};
use App\Services\{ArpaHistoricalApprovalSchemaVerifier,ArpaHistoricalApprovalRegistrationService,ArpaHistoricalAppointmentApprovalService,UserContextService};

final class SchemaVerifierApplicationPdo extends PDO
{
    public ?Closure $beforeEvent=null;
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        if($this->beforeEvent!==null&&str_starts_with($query,'INSERT INTO arpa_appointment_historical_approval_event')){$hook=$this->beforeEvent;$this->beforeEvent=null;$hook();}
        return parent::prepare($query,$options);
    }
}

final class ArpaHistoricalApprovalSchemaVerifierIsolatedTest
{
    private const ADMIN='00000000-0000-4000-8000-000000000003';
    private PDO $root;private SchemaVerifierApplicationPdo $app;private string $db;private string $dir;private array $sources;private array $files=[];private array $users=[];private array $dml;private string $privateFile;private string $verifierSecret;private int $assertions=0;
    public function run():void
    {
        $c=config('database');
        if(!in_array($c['host'],['127.0.0.1','localhost'],true)||!str_starts_with($c['database'],'dems_test_')||is_file(BASE_PATH.'/.env'))throw new RuntimeException('Use isolated local process configuration, without an application .env.');
        $suffix=bin2hex(random_bytes(5));$this->db='dems_test_hist_schema_'.$suffix;
        $server=self::connect(null);$prop=new ReflectionProperty(Database::class,'pdo');$old=$prop->getValue();$session=$_SESSION??[];$made=false;
        $this->dir=sys_get_temp_dir().'/dems_schema_verifier_'.$suffix;if(!mkdir($this->dir,0700))throw new RuntimeException('Private test directory unavailable.');
        try{
            $server->exec('CREATE DATABASE `'.$this->db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$made=true;$this->root=self::connect($this->db);
            if($this->root->query('SELECT DATABASE()')->fetchColumn()!==$this->db)throw new RuntimeException('Incorrect fixture database before writes.');$prop->setValue(null,$this->root);
            $this->sources=ArpaHistoricalReleaseSecurityIsolatedTest::fixture($this->root);$s=$this->sources[0];
            $this->root->prepare("UPDATE arpa_division_appointment_request SET workflow_status='ASC_APPROVED',requested_effective_from='2020-10-15' WHERE id=?")->execute([$s['request']]);
            $this->root->prepare("UPDATE arpa_division_appointment SET effective_from='2020-10-15' WHERE id=?")->execute([$s['appointment']]);
            $this->root->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_source,data_correction_id,context_snapshot_json,approval_timestamp_provenance) VALUES(UUID(),'LEGACY_IMPORT',?,?,'2025-01-20','18000000-0000-4000-8000-000000000001','DATA_ISSUE_CORRECTION',?,'{}','UNAVAILABLE_FROM_LEGACY_SOURCE')")->execute([$s['appointment'],$s['request'],$s['correction']]);
            $dmlUser='dems_dml_'.$suffix;$metaUser='dems_meta_'.$suffix;$dmlPassword=bin2hex(random_bytes(20));$this->verifierSecret='verifier-canary-'.bin2hex(random_bytes(20));
            foreach([[$dmlUser,$dmlPassword],[$metaUser,$this->verifierSecret]] as [$user,$password]){$server->exec('CREATE USER '.$server->quote($user)."@'127.0.0.1' IDENTIFIED BY ".$server->quote($password));$this->users[]=$user;}
            $server->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON `'.$this->db.'`.* TO '.$server->quote($dmlUser)."@'127.0.0.1'");
            foreach(['case','event'] as $table)$server->exec('GRANT SELECT ON `'.$this->db.'`.arpa_appointment_historical_approval_'.$table.' TO '.$server->quote($metaUser)."@'127.0.0.1'");
            $server->exec('GRANT TRIGGER ON `'.$this->db.'`.arpa_appointment_historical_approval_event TO '.$server->quote($metaUser)."@'127.0.0.1'");
            $this->dml=['host'=>'127.0.0.1','port'=>$c['port'],'database'=>$this->db,'username'=>$dmlUser,'password'=>$dmlPassword];
            $this->app=new SchemaVerifierApplicationPdo('mysql:host=127.0.0.1;port='.$c['port'].';dbname='.$this->db.';charset=utf8mb4',$dmlUser,$dmlPassword,self::attributes());$prop->setValue(null,$this->app);
            $cfg=array_replace($this->dml,['username'=>$metaUser,'password'=>$this->verifierSecret]);$this->privateFile=$this->configFile('verifier.json',$cfg,true);
            $this->admin();$this->verification($cfg);$this->definitions();$this->registration();$this->wrongServer();
            echo 'ArpaHistoricalApprovalSchemaVerifierIsolatedTest: '.$this->assertions.' assertions passed; MySQL '.$server->getAttribute(PDO::ATTR_SERVER_VERSION)."; DML/metadata identities and disposable databases removed.\n";
        }finally{
            $prop->setValue(null,$old);$_SESSION=$session;Auth::forgetRequestCache();
            if(isset($this->app)&&$this->app->inTransaction())$this->app->rollBack();
            if($made)$server->exec('DROP DATABASE `'.$this->db.'`');
            foreach($this->users as $user)$server->exec('DROP USER '.$server->quote($user)."@'127.0.0.1'");
            foreach($this->files as $file)if(is_file($file))unlink($file);rmdir($this->dir);
        }
    }
    private function verification(array $cfg):void
    {
        $before=$this->state();$this->same(0,(int)$this->app->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()")->fetchColumn(),'DML account genuinely cannot inspect trigger metadata');
        $this->fails(fn()=>(new ArpaHistoricalApprovalRegistrationService($this->app))->manifest(self::ADMIN),503,'invisible metadata fails closed');
        $this->fails(fn()=>ArpaHistoricalApprovalSchemaVerifier::fromProtectedConfig(BASE_PATH.'/README.md'),503,'deployment file rejected');
        $unsafe=$this->configFile('unsafe.json',['dummy'=>'not credentials'],false);try{ArpaHistoricalApprovalSchemaVerifier::fromProtectedConfig($unsafe);throw new RuntimeException('Unprotected file was accepted.');}catch(DomainException $e){$this->same(503,$e->getCode(),'unprotected configuration rejected');$this->same(true,str_contains($e->getMessage(),PHP_OS_FAMILY==='Windows'?'ACL':'private to'),'file permissions fail before JSON parsing or connection attempts');}
        $bad=$this->configFile('failed.json',array_replace($cfg,['password'=>'invalid-private-verifier-password']),true);$this->fails(fn()=>ArpaHistoricalApprovalSchemaVerifier::fromProtectedConfig($bad),503,'connection failure is controlled and redacted');
        $verifier=ArpaHistoricalApprovalSchemaVerifier::fromProtectedConfig($this->privateFile);$service=new ArpaHistoricalApprovalRegistrationService($this->app,$verifier);$m=$this->manifest($service);
        $this->app->beginTransaction();try{$verifier->verify($this->app,true);$connection=(int)$this->app->query('SELECT CONNECTION_ID()')->fetchColumn();$q=$this->root->prepare("SELECT COUNT(DISTINCT OBJECT_NAME) FROM performance_schema.metadata_locks WHERE OBJECT_SCHEMA=? AND OBJECT_NAME IN('arpa_appointment_historical_approval_case','arpa_appointment_historical_approval_event') AND LOCK_TYPE='SHARED_WRITE' AND LOCK_STATUS='GRANTED' AND OWNER_THREAD_ID=(SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID=?)");$q->execute([$this->db,$connection]);$this->same(2,(int)$q->fetchColumn(),'apply verification actually holds write-compatible metadata locks on both tables');$q=$this->root->prepare("SELECT COUNT(*) FROM performance_schema.data_locks WHERE OBJECT_SCHEMA=? AND LOCK_TYPE='RECORD' AND THREAD_ID=(SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID=?)");$q->execute([$this->db,$connection]);$this->same(0,(int)$q->fetchColumn(),'zero-row apply verification locks no appointment/case/event records');}finally{$this->app->rollBack();}
        $this->same(64,strlen($m['schema_fingerprint']),'verified schema is bound into manifest');$this->same('ELIGIBLE',$m['candidates'][0]['classification'],'DML data access with private metadata verification succeeds');
        $this->same(false,str_contains(json_encode($m),$this->verifierSecret),'manifest contains no verifier password');$this->same(false,in_array($this->verifierSecret,getenv(),true),'verifier password never enters environment');$this->same($before,$this->state(),'all dry-runs and failed configuration checks leave data unchanged');
        $other=$this->db.'_other';$this->root->exec('CREATE DATABASE `'.$other.'`');try{$wrong=new ArpaHistoricalApprovalRegistrationService($this->app,new ArpaHistoricalApprovalSchemaVerifier(self::connect($other)));$this->fails(fn()=>$this->manifest($wrong),503,'actual wrong-database connection rejected');}finally{$this->root->exec('DROP DATABASE `'.$other.'`');}
        $engine=$this->db.'_engine';$this->root->exec('CREATE DATABASE `'.$engine.'`');try{$p=self::connect($engine);$p->exec('CREATE TABLE arpa_appointment_historical_approval_case(id INT PRIMARY KEY) ENGINE=MyISAM; CREATE TABLE arpa_appointment_historical_approval_event(id INT PRIMARY KEY) ENGINE=InnoDB');$p->beginTransaction();try{$this->fails(fn()=>(new ArpaHistoricalApprovalSchemaVerifier())->verify($p),503,'actual non-InnoDB table rejected');}finally{$p->rollBack();}}finally{$this->root->exec('DROP DATABASE `'.$engine.'`');}
        $this->context(1);$this->fails(fn()=>$this->manifest($service),403,'ASC actor cannot use privileged schema verification to register');$this->admin();
        unset($_SESSION['access_context']);Auth::forgetRequestCache();$this->fails(fn()=>$this->manifest($service),403,'canonical identity without context rejected');$this->admin();
        [$exit,$body,$err]=$this->cli([]);$this->same(1,$exit,'actual DML CLI fails without metadata authority');$this->same(false,str_contains($body.$err,$this->verifierSecret),'failure does not reveal verifier credentials');
        [$exit,$body,$err]=$this->cli(['--schema-verifier-config='.$this->privateFile]);$this->same(0,$exit,'actual CLI accepts protected metadata connection');$fromCli=json_decode($body,true,512,JSON_THROW_ON_ERROR);$this->same('DRY_RUN',$fromCli['mode'],'private verifier does not change default dry-run');$this->same($m['manifest_hash'],$fromCli['manifest_hash'],'CLI and application-DML service agree on schema fingerprint');
        $this->same(false,str_contains($body.$err,$this->verifierSecret),'successful CLI does not reveal credentials');
        $remote=$this->configFile('remote.json',array_replace($cfg,['host'=>'example.invalid']),true);$this->fails(fn()=>ArpaHistoricalApprovalSchemaVerifier::fromProtectedConfig($remote),503,'remote verifier without certificate validation rejected before connection');
        // The web canonicalization adapter is intentionally not given this verifier.
        $this->fails(fn()=>(new ArpaHistoricalAppointmentApprovalService($this->app))->registerCanonicalCorrectionInTransaction($this->sources[0]['correction'],self::ADMIN,'DIRECT'),409,'automatic adapter still requires its own transaction');
        $this->app->beginTransaction();try{$this->fails(fn()=>(new ArpaHistoricalAppointmentApprovalService($this->app))->registerCanonicalCorrectionInTransaction($this->sources[0]['correction'],self::ADMIN,'DIRECT'),503,'automatic registration remains fail-closed with hidden metadata');}finally{$this->app->rollBack();}
    }
    private function definitions():void
    {
        $name='trg_hist_approval_event_no_delete';$service=new ArpaHistoricalApprovalRegistrationService($this->app,ArpaHistoricalApprovalSchemaVerifier::fromProtectedConfig($this->privateFile));
        foreach([null,"SIGNAL SQLSTATE '01000' SET MESSAGE_TEXT='Historical approval events are append-only'","BEGIN IF 1=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical approval events are append-only'; END IF; END","SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Changed message'",'SET @audit_bypass=1'] as $body){
            $this->root->exec('DROP TRIGGER '.$name);try{if($body!==null)$this->root->exec('CREATE TRIGGER '.$name.' BEFORE DELETE ON arpa_appointment_historical_approval_event FOR EACH ROW '.$body);$this->fails(fn()=>$this->manifest($service),503,'missing/modified unconditional protection rejected');}finally{$this->root->exec('DROP TRIGGER IF EXISTS '.$name);$this->restore();}
        }
        foreach([['AFTER','DELETE','arpa_appointment_historical_approval_event'],['BEFORE','UPDATE','arpa_appointment_historical_approval_event'],['BEFORE','DELETE','arpa_appointment_historical_approval_case']] as [$timing,$operation,$table]){$this->root->exec('DROP TRIGGER '.$name);try{$this->root->exec('CREATE TRIGGER '.$name.' '.$timing.' '.$operation.' ON '.$table." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical approval events are append-only'");$this->fails(fn()=>$this->manifest($service),503,'wrong timing/operation/table rejected');}finally{$this->root->exec('DROP TRIGGER '.$name);$this->restore();}}
        $m=$this->manifest($service);$before=$this->state();$this->root->exec('DROP TRIGGER '.$name);try{$this->fails(fn()=>$this->apply($service,$m),503,'trigger removed between dry-run/apply prevents writes');$this->same($before,$this->state(),'missing-trigger apply rolls back all data');}finally{$this->restore();}
        // Same behavior but changed SQL mode is still a changed schema requiring a fresh manifest.
        $m=$this->manifest($service);$old=$this->root->query('SELECT @@sql_mode')->fetchColumn();$this->root->exec("SET SESSION sql_mode='ANSI_QUOTES'");$this->root->exec('DROP TRIGGER '.$name);$this->restore();$this->root->exec('SET SESSION sql_mode='.$this->root->quote($old));$this->fails(fn()=>$this->apply($service,$m),409,'verified metadata changes invalidate reviewed schema fingerprint');
    }
    private function registration():void
    {
        $service=new ArpaHistoricalApprovalRegistrationService($this->app,ArpaHistoricalApprovalSchemaVerifier::fromProtectedConfig($this->privateFile));$m=$this->manifest($service);$before=$this->state();
        $broken=ArpaHistoricalApprovalSchemaVerifier::fromProtectedConfig($this->privateFile);$metadata=(new ReflectionProperty($broken,'metadata'))->getValue($broken);$this->same($this->db,$metadata->query('SELECT DATABASE()')->fetchColumn(),'only the isolated metadata session can be interrupted');$connection=(int)$metadata->query('SELECT CONNECTION_ID()')->fetchColumn();$brokenService=new ArpaHistoricalApprovalRegistrationService($this->app,$broken);$brokenManifest=$this->manifest($brokenService);$this->app->beforeEvent=function()use($connection):void{$this->root->exec('KILL CONNECTION '.$connection);};$this->fails(fn()=>$this->apply($brokenService,$brokenManifest),503,'metadata loss during writes fails final verification');$this->same($before,$this->state(),'loss of verifier after case insertion rolls back all application writes');
        $this->root->exec("CREATE TRIGGER fail_schema_registration BEFORE INSERT ON arpa_appointment_historical_approval_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic event rollback'");
        try{$this->fails(fn()=>$this->apply($service,$m),null,'event insertion failure rolls back case',true);}finally{$this->root->exec('DROP TRIGGER fail_schema_registration');}$this->same($before,$this->state(),'application transaction rolls back despite separate metadata connection');
        $this->app->beforeEvent=function():void{foreach(['drop','create','alter'] as $kind){[$exit,$out]=$this->ddl($kind);$this->same(0,$exit,'DDL worker terminates normally');$this->same('LOCK_TIMEOUT',$out,'concurrent '.$kind.' is blocked inside actual apply transaction');}};
        $result=$this->apply($service,$m);$this->same(1,$result['registered_count'],'DML-only application connection creates the Draft');$id=$result['registered'][0]['case_id'];$this->same('DRAFT',$result['registered'][0]['workflow_status'],'no submission/approval');
        $expected=$before;unset($expected['arpa_appointment_historical_approval_case'],$expected['arpa_appointment_historical_approval_event']);$after=$this->state();unset($after['arpa_appointment_historical_approval_case'],$after['arpa_appointment_historical_approval_event']);$this->same($expected,$after,'only case and event data changed');
        $s=$this->app->prepare('SELECT COUNT(*) FROM arpa_appointment_historical_approval_event WHERE approval_case_id=?');$s->execute([$id]);$this->same(1,(int)$s->fetchColumn(),'one append-only registration event');
        $this->fails(fn()=>$this->apply($service,$m),409,'old confirmation cannot replay');$repeat=$this->manifest($service);$this->same(0,$this->apply($service,$repeat)['registered_count'],'fresh repeat is idempotent');
        $this->pendingDdl($service);
        foreach(['UPDATE arpa_appointment_historical_approval_event SET reason=reason','DELETE FROM arpa_appointment_historical_approval_event'] as $sql)$this->fails(fn()=>$this->app->exec($sql),null,'DML account cannot change audit history',true);
        [$exit,$out]=$this->ddl('drop');$this->same(0,$exit,'post-commit DDL worker completes');$this->same('DDL_OK',$out,'metadata lock is released only after apply completes');try{$this->fails(fn()=>$this->manifest($service),503,'subsequent operation detects post-commit trigger removal');}finally{$this->restore();}
        $source=$this->sources[2];$fresh=$service->manifest(self::ADMIN,false,$source['request'],$source['appointment']);$original=$this->state();
        [$exit,$body,$err]=$this->cli(['--apply','--database='.$fresh['database']['database_name'],'--server-uuid='.$fresh['database']['server_uuid'],'--manifest-hash='.$fresh['manifest_hash'],'--confirm='.$fresh['confirmation'],'--reason=Synthetic protected CLI apply','--evidence-reference=Synthetic register','--schema-verifier-config='.$this->privateFile],$source);
        $this->same(0,$exit,'actual DML-only CLI apply with protected verifier succeeds');$result=json_decode($body,true,512,JSON_THROW_ON_ERROR);$this->same(1,$result['registered_count'],'actual CLI writes exactly one case');$this->same('DRAFT',$result['registered'][0]['workflow_status'],'actual CLI remains Draft-only');$this->same(false,str_contains($body.$err,$this->verifierSecret),'CLI apply outputs contain no verifier secret');$after=$this->state();unset($original['arpa_appointment_historical_approval_case'],$original['arpa_appointment_historical_approval_event'],$after['arpa_appointment_historical_approval_case'],$after['arpa_appointment_historical_approval_event']);$this->same($original,$after,'actual privileged-metadata CLI changes no original business data');
        $this->same(0,(int)$this->app->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()")->fetchColumn(),'application account never gained TRIGGER');
    }
    private function pendingDdl(ArpaHistoricalApprovalRegistrationService $service):void
    {
        $source=$this->sources[1];$m=$service->manifest(self::ADMIN,false,$source['request'],$source['appointment']);$before=$this->state();$worker=null;$outcome='';
        $this->app->beforeEvent=function()use(&$worker):void{
            $worker=$this->startDdl('drop',8);$deadline=microtime(true)+2;$pending=false;
            do{$q=$this->root->prepare("SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE OBJECT_SCHEMA=? AND OBJECT_NAME='arpa_appointment_historical_approval_event' AND LOCK_STATUS='PENDING'");$q->execute([$this->db]);$pending=(int)$q->fetchColumn()>0;if(!$pending)usleep(20000);}while(!$pending&&microtime(true)<$deadline);
            $this->same(true,$pending,'real concurrent trigger DDL is waiting while apply holds metadata locks');$this->same(true,$this->app->inTransaction(),'waiting DDL does not implicitly commit application work');
        };
        try{
            try{$result=$service->apply(self::ADMIN,$m['manifest_hash'],$m['confirmation'],['reason'=>'Synthetic pending DDL','evidence_reference'=>'Synthetic evidence'],$source['request'],$source['appointment']);$this->same(1,$result['registered_count'],'protected apply finishes before pending DDL');$outcome='COMMITTED under pinned schema';}
            catch(DomainException|PDOException $e){if(!($e instanceof DomainException&&$e->getCode()===503)&&!($e instanceof PDOException&&in_array($e->errorInfo[1]??null,[1205,1213],true)))throw $e;$this->same($before,$this->state(),'pending DDL causes safe rollback, never unverified partial writes');$outcome='ROLLED BACK safely';}
            $this->same(true,is_array($worker),'pending DDL worker actually started');[$exit,$out]=$this->finishDdl($worker);$worker=null;$this->same(0,$exit,'pending DDL worker completes');$this->same('DDL_OK',$out,'pending trigger removal completes only when application transaction ends');
            $this->fails(fn()=>$service->manifest(self::ADMIN,false,$source['request'],$source['appointment']),503,'next operation cannot reuse schema evidence after concurrent DDL');
            echo 'Concurrent pending DDL: '.$outcome."; trigger removal completed after transaction release.\n";
        }finally{if(is_array($worker))$this->finishDdl($worker);$this->root->exec('DROP TRIGGER IF EXISTS trg_hist_approval_event_no_delete');$this->restore();}
    }
    private function wrongServer():void
    {
        $port=getenv('DEMS_SCHEMA_TEST_OTHER_PORT');if(!$port||!ctype_digit($port))throw new RuntimeException('Supply a separately verified disposable MySQL port for wrong-server coverage.');
        $other=self::connect(null,(int)$port);$datadir=str_replace('\\','/',(string)$other->query('SELECT @@datadir')->fetchColumn());if(!str_contains($datadir,'dems_schema_verify_mysql80_'))throw new RuntimeException('Wrong-server fixture is not the isolated test server.');
        $this->same(false,$other->query('SELECT @@server_uuid')->fetchColumn()===$this->root->query('SELECT @@server_uuid')->fetchColumn(),'second disposable server has a genuinely different UUID');
        $other->exec('CREATE DATABASE `'.$this->db.'`');try{$metadata=self::connect($this->db,(int)$port);$service=new ArpaHistoricalApprovalRegistrationService($this->app,new ArpaHistoricalApprovalSchemaVerifier($metadata));$this->fails(fn()=>$this->manifest($service),503,'actual wrong-server/port metadata connection rejected');}finally{$other->exec('DROP DATABASE `'.$this->db.'`');}
    }
    private function manifest(ArpaHistoricalApprovalRegistrationService $service):array{return $service->manifest(self::ADMIN,false,$this->sources[0]['request'],$this->sources[0]['appointment']);}
    private function apply(ArpaHistoricalApprovalRegistrationService $service,array $m):array{return $service->apply(self::ADMIN,$m['manifest_hash'],$m['confirmation'],['reason'=>'Synthetic registration','evidence_reference'=>'Synthetic verified register'],$this->sources[0]['request'],$this->sources[0]['appointment']);}
    private function restore():void{$this->root->exec("CREATE TRIGGER trg_hist_approval_event_no_delete BEFORE DELETE ON arpa_appointment_historical_approval_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical approval events are append-only'");}
    private function context(int $n):void{$actor='00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT);$f=new ArpaHistoricalAppointmentApprovalIsolatedTest();(new ReflectionProperty($f,'pdo'))->setValue($f,$this->app);(new ReflectionMethod($f,'context'))->invoke($f,$actor);}
    private function admin():void{$_SESSION=['user_id'=>self::ADMIN,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();(new UserContextService($this->app))->select(self::ADMIN,'release-system-assignment',null);Auth::forgetRequestCache();}
    private function state():array{$out=[];foreach($this->root->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$out[$table]=$this->root->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetchAll();return $out;}
    private function cli(array $args,?array $source=null):array{$source??=$this->sources[0];$env=getenv();foreach($this->dml as $key=>$value)$env['DB_'.($key==='username'?'USERNAME':strtoupper($key))]=(string)$value;$pipes=[];$p=proc_open(array_merge([PHP_BINARY,'-d','xdebug.mode=off','-d','xdebug.log=',BASE_PATH.'/bin/register-arpa-historical-approvals.php','--operator='.self::ADMIN,'--role-assignment=release-system-assignment','--request-id='.$source['request'],'--appointment-id='.$source['appointment']],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH,$env);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];}
    private function ddl(string $kind):array{return $this->finishDdl($this->startDdl($kind));}
    private function startDdl(string $kind,int $wait=1):array{$pipes=[];$p=proc_open([PHP_BINARY,'-d','xdebug.mode=off','-d','xdebug.log=',__FILE__,'--ddl',$this->db,$kind,(string)$wait],[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);return [$p,$pipes];}
    private function finishDdl(array $worker):array{[$p,$pipes]=$worker;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if($err!=='')throw new RuntimeException('Unexpected DDL worker error.');return [proc_close($p),$out];}
    public static function worker(array $args):void
    {
        $db=$args[2]??'';if(!preg_match('/^dems_test_hist_schema_[a-f0-9]{10}$/D',$db)||!in_array($args[4]??'1',['1','8'],true))throw new RuntimeException('Unsafe DDL fixture target.');$p=self::connect($db);$p->exec('SET SESSION lock_wait_timeout='.($args[4]??'1'));
        $sql=match($args[3]??''){'drop'=>'DROP TRIGGER trg_hist_approval_event_no_delete','create'=>"CREATE TRIGGER synthetic_ddl BEFORE UPDATE ON arpa_appointment_historical_approval_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic DDL'",'alter'=>"ALTER TABLE arpa_appointment_historical_approval_case COMMENT='Synthetic DDL'",default=>throw new RuntimeException('Unknown DDL test.')};
        try{$p->exec($sql);echo 'DDL_OK';}catch(PDOException $e){if(($e->errorInfo[1]??null)!==1205)throw $e;echo 'LOCK_TIMEOUT';}
    }
    private function configFile(string $name,array $config,bool $private):string
    {
        $file=$this->dir.'/'.$name;$this->files[]=$file;file_put_contents($file,json_encode($config,JSON_THROW_ON_ERROR));
        if(PHP_OS_FAMILY!=='Windows'){chmod($file,$private?0600:0644);return $file;}
        $path=base64_encode($file);$everyone=$private?'':"\$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.SecurityIdentifier]::new('S-1-1-0'),'Read','Allow'))";
        $script="\$p=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('$path')); \$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User; \$acl=[IO.File]::GetAccessControl(\$p); \$acl.SetAccessRuleProtection(\$true,\$false); \$acl.SetAccessRule([Security.AccessControl.FileSystemAccessRule]::new(\$sid,'FullControl','Allow')); $everyone; [IO.File]::SetAccessControl(\$p,\$acl)";
        $pipes=[];$p=proc_open(['powershell.exe','-NoProfile','-NonInteractive','-Command','-'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],$script."\n");fclose($pipes[0]);stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0||$err!=='')throw new RuntimeException('Cannot protect isolated verifier configuration.');return $file;
    }
    private static function attributes():array{return [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true];}
    private static function connect(?string $db,?int $port=null):PDO{$c=config('database');return new PDO('mysql:host=127.0.0.1;port='.($port??$c['port']).($db===null?'':';dbname='.$db).';charset=utf8mb4',$c['username'],$c['password'],self::attributes());}
    private function same(mixed $expected,mixed $actual,string $label):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($label);}
    private function fails(callable $run,?int $code,string $label,bool $pdo=false):void{$this->assertions++;try{$run();}catch(Throwable $e){if(!$e instanceof DomainException&&!($pdo&&$e instanceof PDOException))throw new RuntimeException($label.' raised an unexpected exception type.');if($code!==null&&$e->getCode()!==$code)throw new RuntimeException($label.' returned unexpected status.');if(isset($this->verifierSecret)&&str_contains($e->getMessage(),$this->verifierSecret))throw new RuntimeException('Credential redaction failed.');return;}throw new RuntimeException($label.' was not rejected.');}
}
try{if(($argv[1]??'')==='--ddl')ArpaHistoricalApprovalSchemaVerifierIsolatedTest::worker($argv);else(new ArpaHistoricalApprovalSchemaVerifierIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,'Schema verifier isolated test failed: '.get_class($e).'; '.$e->getMessage()."\n");exit(1);}
