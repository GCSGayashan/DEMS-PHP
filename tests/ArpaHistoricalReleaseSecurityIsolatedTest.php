<?php
declare(strict_types=1);
define('ARPA_HISTORICAL_UI_FIXTURE_ONLY',true);
require __DIR__.'/ArpaHistoricalApprovalUiIsolatedTest.php';

use App\Core\{Auth,Database};
use App\Services\{ArpaAdministrativePolicy,ArpaHistoricalAppointmentApprovalService};

/** Actual routed context/CSRF checks and migration invariants; never uses working data. */
final class ArpaHistoricalReleaseSecurityIsolatedTest
{
    private PDO $pdo;
    private string $db;
    private int $assertions=0;
    public static function fixture(PDO $pdo):array
    {
        $call=static fn($o,$m,...$args)=>(new ReflectionMethod($o,$m))->invoke($o,...$args);
        $base=new ArpaAppointmentCorrectionAmendmentDraftTest();(new ReflectionProperty($base,'pdo'))->setValue($base,$pdo);
        $call($base,'createFixtureSchema');$pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/062_arpa_appointment_correction_amendment_foundation.sql'));
        $call($base,'insertFixtures');$call($base,'exerciseHistoricalPeerDecisions');
        $pdo->exec("INSERT INTO designation VALUES('historical-test-arpa','ARPA_OFFICER')");
        $fixture=new ArpaHistoricalAppointmentApprovalIsolatedTest();(new ReflectionProperty($fixture,'pdo'))->setValue($fixture,$pdo);
        $sources=[];for($i=0;$i<3;$i++)$sources[]=$call($fixture,'source');
        $ui=new ArpaHistoricalApprovalUiIsolatedTest();(new ReflectionProperty($ui,'pdo'))->setValue($ui,$pdo);$call($ui,'schema');
        $pdo->exec("ALTER TABLE designation ADD name_en VARCHAR(255);
            CREATE TABLE officer_class(id CHAR(36) PRIMARY KEY,name_en VARCHAR(255));
            CREATE TABLE officer_status(id CHAR(36) PRIMARY KEY,name_en VARCHAR(255));
            ALTER TABLE officer ADD class_id CHAR(36),ADD officer_status_id CHAR(36),ADD operational_status VARCHAR(30) DEFAULT 'ACTIVE';
            ALTER TABLE location ADD official_code VARCHAR(80);
            CREATE TABLE arpa_appointment_stage_review(id CHAR(36) PRIMARY KEY,entity_type VARCHAR(30),request_id CHAR(36),review_stage VARCHAR(30),updated_by CHAR(36));
            UPDATE system_user SET username='dems.admin' WHERE id='00000000-0000-4000-8000-000000000003';
            INSERT INTO application_role VALUES('release-system-role','SYSTEM_ADMIN','System Administrator','SYSTEM',1,'APPROVED');
            INSERT INTO application_role_permission VALUES('release-system-role','13000000-0000-4000-8000-000000000011');
            INSERT INTO user_account_role(id,user_id,role_id,active,approval_status,effective_from) VALUES('release-system-assignment','00000000-0000-4000-8000-000000000003','release-system-role',1,'APPROVED',CURRENT_DATE());");
        return $sources;
    }
    public function run():void
    {
        $c=config('database');if($c['host']!=='127.0.0.1'||!str_starts_with($c['database'],'dems_test_'))throw new RuntimeException('Isolated process configuration required.');
        $this->db='dems_test_hist_release_'.bin2hex(random_bytes(5));$server=new PDO("mysql:host=127.0.0.1;port={$c['port']};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $property=new ReflectionProperty(Database::class,'pdo');$old=$property->getValue();$session=$_SESSION??[];$created=false;
        try{
            $server->exec('CREATE DATABASE `'.$this->db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;$this->pdo=self::connect($this->db);$property->setValue(null,$this->pdo);
            $sources=self::fixture($this->pdo);$this->migration($sources[0]);$this->routes($sources);
            echo 'ArpaHistoricalReleaseSecurityIsolatedTest: '.$this->assertions.' assertions passed; MySQL '.$server->getAttribute(PDO::ATTR_SERVER_VERSION)."; disposable schema removed.\n";
        }finally{$property->setValue(null,$old);$_SESSION=$session;Auth::forgetRequestCache();if($created)$server->exec('DROP DATABASE `'.$this->db.'`');}
    }
    private function migration(array $source):void
    {
        $this->same(2,(int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='arpa_appointment_historical_approval_event'")->fetchColumn(),'both append-only triggers installed');
        $this->same(0,(int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'arpa_appointment_historical_approval_%' AND DELETE_RULE='CASCADE'")->fetchColumn(),'no audit cascade deletion');
        foreach(['uq_hist_approval_registration','uq_hist_approval_active','idx_hist_approval_queue','idx_hist_approval_officer'] as $name){$s=$this->pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='arpa_appointment_historical_approval_case' AND INDEX_NAME=?");$s->execute([$name]);$this->same(true,(int)$s->fetchColumn()>0,'index '.$name);}
        $this->same(true,str_contains((string)$this->pdo->query("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='arpa_appointment_historical_approval_case' AND COLUMN_NAME='active_appointment_id'")->fetchColumn(),'STORED GENERATED'),'generated active identity');
        $this->context(1);$service=new ArpaHistoricalAppointmentApprovalService($this->pdo);$case=$service->register($source['correction'],['reason'=>'Synthetic migration test','evidence_reference'=>'Synthetic evidence'],self::actor(1));$id=$case['case']['id'];
        foreach(["UPDATE arpa_appointment_historical_approval_event SET reason='changed' WHERE approval_case_id='$id'","DELETE FROM arpa_appointment_historical_approval_event WHERE approval_case_id='$id'","UPDATE arpa_appointment_historical_approval_case SET baseline_hash='bad' WHERE id='$id'","UPDATE arpa_appointment_historical_approval_case SET version=-1 WHERE id='$id'","UPDATE arpa_appointment_historical_approval_case SET reason='' WHERE id='$id'"] as $sql){try{$this->pdo->exec($sql);throw new RuntimeException('Expected database invariant rejection.');}catch(PDOException $e){$this->same(true,in_array($e->getCode(),['45000','HY000'],true),'database invariant enforced');}}
        $before=$this->pdo->query("SELECT * FROM arpa_appointment_historical_approval_case WHERE id='$id'")->fetch();
        $s=$this->pdo->prepare('INSERT INTO arpa_appointment_historical_approval_case(id,request_id,appointment_id,source_correction_id,officer_id,asc_location_id,baseline_json,baseline_hash,reason,evidence_reference,created_by) SELECT UUID(),request_id,appointment_id,source_correction_id,officer_id,asc_location_id,baseline_json,baseline_hash,reason,evidence_reference,created_by FROM arpa_appointment_historical_approval_case WHERE id=?');
        try{$s->execute([$id]);throw new RuntimeException('Duplicate case accepted.');}catch(PDOException $e){$this->same('23000',$e->getCode(),'database registration uniqueness');}
        $this->same($before,$this->pdo->query("SELECT * FROM arpa_appointment_historical_approval_case WHERE id='$id'")->fetch(),'rejected writes leave case unchanged');
    }
    private function routes(array $sources):void
    {
        $own=$sources[0]['request'];$foreign=$sources[1]['request'];$deleted=$sources[2]['request'];
        $this->pdo->prepare("UPDATE arpa_division_appointment_request SET asc_location_id='10000000-0000-4000-8000-000000000002' WHERE id=?")->execute([$foreign]);
        $this->pdo->prepare("UPDATE arpa_division_appointment_request SET deleted_at=NOW(),delete_reason='Synthetic exclusion' WHERE id=?")->execute([$deleted]);
        $this->context(3,'ASC_ADMIN');$this->same(false,ArpaAdministrativePolicy::isCanonicalSystemAdministrator(),'canonical identity in ASC context cannot bypass scope');
        $this->context(3,'SYSTEM_ADMIN');$this->same(true,ArpaAdministrativePolicy::isCanonicalSystemAdministrator(),'canonical active SYSTEM context authorized');
        foreach([[$own,3,'ASC_ADMIN',200],[$foreign,3,'ASC_ADMIN',403],[$deleted,3,'ASC_ADMIN',403],[$foreign,3,'SYSTEM_ADMIN',200],[$deleted,3,'SYSTEM_ADMIN',200],[$foreign,1,'ASC_SUBJECT_OFFICER',403],[$own,1,'ASC_SUBJECT_OFFICER',200]] as [$id,$actor,$role,$expected]){[$status,$body]=$this->route('GET','/hr/arpa-appointments/requests/division/'.$id,[],$actor,$role);$this->same($expected,$status,'actual generic detail context: '.$role.' '.$id);$this->same(false,str_contains($body,'Fatal error')||str_contains($body,'SQLSTATE['),'controlled detail response');}
        $this->context(1);$source=$sources[0];$service=new ArpaHistoricalAppointmentApprovalService($this->pdo);$case=$service->register($source['correction'],['reason'=>'Synthetic','evidence_reference'=>'Synthetic'],self::actor(1));$id=$case['case']['id'];
        $before=$this->pdo->query('SELECT * FROM arpa_division_appointment_request ORDER BY id')->fetchAll();
        foreach([['/hr/arpa-appointments/historical-approvals/'.$id.'/submit',1,'ASC_SUBJECT_OFFICER'],['/hr/arpa-appointments/legacy-requests/'.$deleted.'/exclude',3,'SYSTEM_ADMIN']] as [$path,$actor,$role]){[$status]=$this->route('POST',$path,['expected_version'=>'0'],$actor,$role,'missing-both');$this->same(419,$status,'actual POST denies missing session and submitted CSRF');}
        $this->same($before,$this->pdo->query('SELECT * FROM arpa_division_appointment_request ORDER BY id')->fetchAll(),'denied POST cannot mutate requests');
    }
    private function route(string $method,string $path,array $post,int $actor,string $role,string $csrf='valid'):array
    {
        $pipes=[];$p=proc_open([PHP_BINARY,'-d','xdebug.mode=off','-d','display_errors=0',__FILE__,'--route',$this->db,$method,$path,base64_encode(json_encode($post)),(string)$actor,$role,$csrf],[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException('Routed fixture failed: '.$err);if(!preg_match('/\nSTATUS=(\d+)$/',$out,$m))throw new RuntimeException('Missing route result.');return [(int)$m[1],substr($out,0,-strlen($m[0]))];
    }
    public static function child(array $args):void
    {
        (new ReflectionProperty(Database::class,'pdo'))->setValue(null,self::connect($args[2]));$test=new self();$test->pdo=Database::pdo();$test->context((int)$args[6],$args[7]);$_SESSION['_csrf']='release-fixture-token';$_POST=json_decode(base64_decode($args[5]),true);$_POST['_csrf']='release-fixture-token';if($args[8]==='missing-both')unset($_SESSION['_csrf'],$_POST['_csrf']);$_SERVER['REQUEST_METHOD']=$args[3];$_SERVER['REQUEST_URI']=$args[4];http_response_code(200);register_shutdown_function(static fn()=>print("\nSTATUS=".http_response_code()));$router=new App\Core\Router();require BASE_PATH.'/routes/web.php';$router->dispatch($args[3],$args[4]);
    }
    private function context(int $n,?string $role=null):void{$f=new ArpaHistoricalAppointmentApprovalIsolatedTest();(new ReflectionProperty($f,'pdo'))->setValue($f,$this->pdo);(new ReflectionMethod($f,'context'))->invoke($f,self::actor($n),$role);}
    private static function actor(int $n):string{return '00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT);}
    private static function connect(string $db):PDO{if(!preg_match('/^dems_test_hist_release_[a-f0-9]{10}$/D',$db))throw new RuntimeException('Unsafe release fixture database.');$c=config('database');$p=new PDO("mysql:host={$c['host']};port={$c['port']};dbname=$db;charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);if($p->query('SELECT DATABASE()')->fetchColumn()!==$db)throw new RuntimeException('Wrong fixture database.');return $p;}
    private function same(mixed $e,mixed $a,string $m):void{$this->assertions++;if($e!==$a)throw new RuntimeException($m.'; expected '.var_export($e,true).', got '.var_export($a,true));}
}
if(!defined('ARPA_HISTORICAL_RELEASE_FIXTURE_ONLY'))try{if(($argv[1]??'')==='--route')ArpaHistoricalReleaseSecurityIsolatedTest::child($argv);else(new ArpaHistoricalReleaseSecurityIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
