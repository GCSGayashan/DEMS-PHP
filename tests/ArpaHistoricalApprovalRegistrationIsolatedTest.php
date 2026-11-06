<?php
declare(strict_types=1);
define('ARPA_HISTORICAL_APPROVAL_FIXTURE_ONLY',true);
require __DIR__.'/ArpaHistoricalAppointmentApprovalIsolatedTest.php';

use App\Core\{Auth,Database};
use App\Services\{ArpaHistoricalAppointmentApprovalService,ArpaHistoricalApprovalRegistrationService,UserContextService};

final class ArpaHistoricalApprovalRegistrationIsolatedTest
{
    private PDO $pdo;private object $fixture;private string $db;private int $assertions=0;private array $sources=[];
    private const ADMIN='00000000-0000-4000-8000-000000000003';
    private const ROLE='historical-reg-system-context';
    public function run():void
    {
        $c=config('database');if(!in_array($c['host'],['localhost','127.0.0.1'],true)||!str_starts_with($c['database'],'dems_test_'))throw new RuntimeException('Use process-only isolated database settings.');
        if(is_file(BASE_PATH.'/.env'))throw new RuntimeException('Run command acceptance in an isolated checkout without a working .env.');
        $this->db='dems_test_hist_reg_'.bin2hex(random_bytes(5));$server=new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4',$c['host'],$c['port']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $prop=new ReflectionProperty(Database::class,'pdo');$old=$prop->getValue();$session=$_SESSION??[];$created=false;
        try{
            $server->exec('CREATE DATABASE `'.$this->db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
            $this->pdo=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port'],$this->db),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
            if($this->pdo->query('SELECT DATABASE()')->fetchColumn()!==$this->db)throw new RuntimeException('Wrong database before test writes.');$prop->setValue(null,$this->pdo);
            $base=new ArpaAppointmentCorrectionAmendmentDraftTest();(new ReflectionProperty($base,'pdo'))->setValue($base,$this->pdo);
            $this->invoke($base,'createFixtureSchema');$this->pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/062_arpa_appointment_correction_amendment_foundation.sql'));$this->invoke($base,'insertFixtures');$this->invoke($base,'exerciseHistoricalPeerDecisions');
            if(!(new \App\Services\ArpaHistoricalApprovalReadService($this->pdo))->available())$this->pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/067_arpa_historical_approval_foundation.sql'));
            $this->pdo->exec("INSERT INTO designation VALUES('historical-test-arpa','ARPA_OFFICER');CREATE TABLE IF NOT EXISTS arpa_appointment_workflow_action(id BIGINT AUTO_INCREMENT PRIMARY KEY,request_id CHAR(36),action VARCHAR(50),stage VARCHAR(50),user_id CHAR(36),action_at DATETIME,record_origin VARCHAR(40),previous_status VARCHAR(50),new_status VARCHAR(50));
                UPDATE system_user SET username='dems.admin' WHERE id='".self::ADMIN."';
                INSERT INTO application_role VALUES('hist-reg-system-role','SYSTEM_ADMIN','System Administrator','SYSTEM',1,'APPROVED');
                INSERT INTO application_role_permission SELECT 'hist-reg-system-role',id FROM application_permission WHERE permission_key='arpa.appointment.view';
                INSERT IGNORE INTO application_role_permission SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.permission_key='arpa.appointment.view' WHERE r.role_code='ASC_SUBJECT_OFFICER';
                INSERT INTO user_account_role VALUES('".self::ROLE."','".self::ADMIN."','hist-reg-system-role',1,'APPROVED','2020-01-01',NULL)");
            $this->fixture=new ArpaHistoricalAppointmentApprovalIsolatedTest();(new ReflectionProperty($this->fixture,'pdo'))->setValue($this->fixture,$this->pdo);
            $this->fixtures();$this->security();$this->safetyChecks();$this->registration();$this->compatibility();$this->concurrency();
            echo 'ArpaHistoricalApprovalRegistrationIsolatedTest: '.$this->assertions.' assertions passed; MySQL '.$server->getAttribute(PDO::ATTR_SERVER_VERSION)."; disposable schema removed.\n";
        }finally{$prop->setValue(null,$old);$_SESSION=$session;Auth::forgetRequestCache();if($created)$server->exec('DROP DATABASE `'.$this->db.'`');}
    }
    private function fixtures():void
    {
        $s=$this->source('valid-ended','PERMANENT',null,'2025-01-01','2025-12-31');$this->cloneCorrection($s['correction'],['correction_action'=>'SET_EFFECTIVE_TO']);
        $p=$this->source('foundation');$this->source('acting','ACTING',$p['officer'],'2025-01-01');
        $p=$this->source('duty-foundation');$this->source('duty','DUTY_COVERING',$p['officer'],'2025-01-01');
        $this->source('missing-foundation','ACTING',null,'2025-01-01');
        $s=$this->source('already');$this->owner();(new ArpaHistoricalAppointmentApprovalService($this->pdo))->register($s['correction'],$this->input(),'00000000-0000-4000-8000-000000000001');
        $s=$this->source('excluded');$this->pdo->prepare('UPDATE arpa_division_appointment_request SET deleted_at=NOW() WHERE id=?')->execute([$s['request']]);
        $s=$this->source('exclusion-audit');$this->pdo->prepare("INSERT INTO audit_event(action_key,target_type,target_id,details_json,severity) VALUES('arpa.appointment.workflow-request.admin-delete','ARPA_DIVISION_APPOINTMENT_REQUEST',?,'{}','WARNING')")->execute([$s['request']]);
        $s=$this->source('missing-correction');$this->pdo->prepare('DELETE FROM arpa_appointment_data_correction WHERE id=?')->execute([$s['correction']]);
        $s=$this->source('ambiguous');$this->cloneCorrection($s['correction']);
        $s=$this->source('replaced');$this->pdo->prepare("UPDATE arpa_appointment_data_correction SET correction_action='KEEP_AS_HISTORICAL_EXCEPTION',resolution_status='KEPT_HISTORICAL_EXCEPTION' WHERE id=?")->execute([$s['correction']]);
        $this->sources['replaced']['effective']=$this->cloneCorrection($s['correction'],['supersedes_correction_id'=>$s['correction'],'correction_action'=>'RESOLVE_CANONICAL_ASSIGNMENT','resolution_status'=>'RESOLVED_BY_CORRECTION']);
        $s=$this->source('approved-amendment');$m=$this->amendment($s);$replacement=$this->cloneCorrection($s['correction'],['supersedes_correction_id'=>$s['correction'],'amendment_request_id'=>$m]);$this->pdo->prepare("UPDATE arpa_appointment_correction_amendment_request SET workflow_status='NATIONAL_APPROVED',applied_correction_id=? WHERE id=?")->execute([$replacement,$m]);$this->sources['approved-amendment']['effective']=$replacement;
        $s=$this->source('withdrawn-amendment');$m=$this->amendment($s);$this->pdo->prepare("UPDATE arpa_appointment_correction_amendment_request SET workflow_status='WITHDRAWN' WHERE id=?")->execute([$m]);
        $s=$this->source('pending-amendment');$this->amendment($s);
        $s=$this->source('unapplied-lineage');$m=$this->amendment($s);$this->cloneCorrection($s['correction'],['supersedes_correction_id'=>$s['correction'],'amendment_request_id'=>$m]);
        $s=$this->source('cyclic-lineage');$successor=$this->cloneCorrection($s['correction'],['supersedes_correction_id'=>$s['correction']]);$this->pdo->prepare('UPDATE arpa_appointment_data_correction SET supersedes_correction_id=? WHERE id=?')->execute([$successor,$s['correction']]);
        $s=$this->source('missing-request');$this->pdo->prepare('UPDATE arpa_division_appointment SET request_id=? WHERE id=?')->execute([$this->uuid(),$s['appointment']]);
        $s=$this->source('wrong-officer');$this->pdo->prepare('UPDATE arpa_division_appointment_request SET officer_id=? WHERE id=?')->execute([$this->sources['valid-ended']['officer'],$s['request']]);
        $s=$this->source('missing-provenance');$this->pdo->prepare('DELETE FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=?')->execute([$s['request']]);
        $s=$this->source('conflicting-case');$this->owner();(new ArpaHistoricalAppointmentApprovalService($this->pdo))->register($s['correction'],$this->input(),'00000000-0000-4000-8000-000000000001');$this->cloneCorrection($s['correction'],['supersedes_correction_id'=>$s['correction']]);
        $s=$this->source('bad-closure','PERMANENT',null,'2025-01-01','2024-12-31');
        foreach(['native','history-only','approved','not-created'] as $name){$s=$this->source($name);$sql=match($name){'native'=>"UPDATE arpa_division_appointment SET record_origin='NATIVE' WHERE id=?",'history-only'=>"UPDATE arpa_division_appointment SET legacy_history_only=1 WHERE id=?",'approved'=>"UPDATE arpa_division_appointment SET approved_at=NOW() WHERE id=?",default=>"UPDATE arpa_division_appointment_request SET workflow_status='ASC_APPROVED' WHERE id=?"};$this->pdo->prepare($sql)->execute([$s[$name==='not-created'?'request':'appointment']]);}
        $this->admin();
    }
    private function security():void
    {
        $s=new ArpaHistoricalApprovalRegistrationService($this->pdo);$this->owner();$this->fails(fn()=>$s->manifest('00000000-0000-4000-8000-000000000001'),'ASC cannot use registration command service',403);
        $this->admin();$this->fails(fn()=>$s->manifest('00000000-0000-4000-8000-000000000001'),'forged actor denied',403);
        unset($_SESSION['access_context']);Auth::forgetRequestCache();$this->fails(fn()=>$s->manifest(self::ADMIN),'canonical identity without active System context denied',403);$this->admin();
        $this->pdo->beginTransaction();try{$this->pdo->exec("INSERT INTO user_account_role VALUES('noncanonical-system-context','00000000-0000-4000-8000-000000000011','hist-reg-system-role',1,'APPROVED','2020-01-01',NULL)");$_SESSION=['user_id'=>'00000000-0000-4000-8000-000000000011','authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();(new UserContextService($this->pdo))->select($_SESSION['user_id'],'noncanonical-system-context',null);Auth::forgetRequestCache();$this->fails(fn()=>$s->manifest($_SESSION['user_id']),'noncanonical System Administrator denied',403);}finally{$this->pdo->rollBack();$this->admin();}
        $this->pdo->beginTransaction();try{$this->pdo->exec("UPDATE user_account_role SET active=0 WHERE id='".self::ROLE."'");Auth::forgetRequestCache();$this->fails(fn()=>$s->manifest(self::ADMIN),'inactive System role denied',403);}finally{$this->pdo->rollBack();$this->admin();}
        $before=$this->state();[$exit]=$this->cli(['--apply']);$this->same(1,$exit,'incomplete apply rejected before writes');$this->same($before,$this->state(),'invalid CLI invocation read-only');
        [$exit]=$this->cli(['--unknown=true']);$this->same(1,$exit,'unknown option rejected');
    }
    private function registration():void
    {
        $s=new ArpaHistoricalApprovalRegistrationService($this->pdo);$before=$this->state();$manifest=$s->manifest(self::ADMIN);$this->same($before,$this->state(),'dry-run changes no records, cases or audit events');
        $this->same(22,$manifest['summary']['discovered'],'actual discovered count is not hard-coded to 39');
        $this->same(9,$manifest['summary']['ELIGIBLE'],'independent registration candidates');$this->same(1,$manifest['summary']['ALREADY_REGISTERED'],'existing case preserved');$this->same(12,$manifest['summary']['MANUAL_REVIEW'],'unsafe cases explicitly classified');
        $eligible=['valid-ended','foundation','acting','duty-foundation','duty','missing-foundation','replaced','approved-amendment','withdrawn-amendment'];
        foreach($this->sources as $name=>$source){if(in_array($name,['native','history-only','approved','not-created'],true))continue;$r=$this->candidate($manifest,$source['appointment']);$expected=in_array($name,$eligible,true)?'ELIGIBLE':($name==='already'?'ALREADY_REGISTERED':'MANUAL_REVIEW');$this->same($expected,$r['classification'],$name.' classification');$this->same(true,$r['reason']!=='',$name.' explicit reason');}
        $this->same($this->sources['replaced']['effective'],$this->candidate($manifest,$this->sources['replaced']['appointment'])['correction_id'],'explicit successor, not superseded KEEP, is selected');
        $this->same($this->sources['approved-amendment']['effective'],$this->candidate($manifest,$this->sources['approved-amendment']['appointment'])['correction_id'],'approved/applied amendment successor is selected');
        $this->same(2,count($this->candidate($manifest,$this->sources['ambiguous']['appointment'])['correction_decisions']),'ambiguous decision IDs remain visible in manifest');
        $this->same(2,count($this->candidate($manifest,$this->sources['valid-ended']['appointment'])['correction_decisions']),'unrelated effective date correction is retained, not silently superseded');
        [$exit,$out]=$this->cli([]);$this->same(0,$exit,'default command dry-run succeeds');$fromCli=json_decode($out,true,512,JSON_THROW_ON_ERROR);$this->same('DRY_RUN',$fromCli['mode'],'default mode is dry-run');$this->same($manifest['manifest_hash'],$fromCli['manifest_hash'],'CLI and service manifest agree');$this->same($before,$this->state(),'actual CLI dry-run is SELECT-only');
        $args=$this->applyArgs($manifest);[$exit]=$this->cli(array_replace($args,[1=>'--database=dems_php']));$this->same(1,$exit,'forged target identity denied');$this->same($before,$this->state(),'wrong-target command cannot mutate');
        $this->fails(fn()=>$s->apply(self::ADMIN,$manifest['manifest_hash'],'wrong confirmation',$this->input()),'exact confirmation required',409);$this->same($before,$this->state(),'confirmation failure is atomic');
        $this->fails(fn()=>$s->apply(self::ADMIN,$manifest['manifest_hash'],$manifest['confirmation'],['reason'=>'','evidence_reference'=>'proof']),'reason mandatory',422);
        $this->fails(fn()=>$s->apply(self::ADMIN,$manifest['manifest_hash'],$manifest['confirmation'],['reason'=>'Reason','evidence_reference'=>'']),'evidence mandatory',422);
        $this->pdo->prepare('UPDATE arpa_division_appointment_request SET version=version+1 WHERE id=?')->execute([$this->sources['acting']['request']]);
        $staleState=$this->state();$this->fails(fn()=>$s->apply(self::ADMIN,$manifest['manifest_hash'],$manifest['confirmation'],$this->input()),'changed source invalidates entire manifest',409);$this->same($staleState,$this->state(),'stale attempt registers nothing');
        $manifest=$s->manifest(self::ADMIN);$beforeSource=$this->operational();$caseCount=$this->count('arpa_appointment_historical_approval_case');$eventCount=$this->count('arpa_appointment_historical_approval_event');
        $this->pdo->exec("CREATE TRIGGER fail_registration_event BEFORE INSERT ON arpa_appointment_historical_approval_event FOR EACH ROW BEGIN IF (SELECT COUNT(*) FROM arpa_appointment_historical_approval_case WHERE created_by='".self::ADMIN."')>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic registration event failure'; END IF; END");
        try{$this->fails(fn()=>$s->apply(self::ADMIN,$manifest['manifest_hash'],$manifest['confirmation'],$this->input()),'event failure rolls back batch',null,true);}finally{$this->pdo->exec('DROP TRIGGER fail_registration_event');}
        $this->same($caseCount,$this->count('arpa_appointment_historical_approval_case'),'failed event leaves no orphan case');$this->same($eventCount,$this->count('arpa_appointment_historical_approval_event'),'failed batch leaves no events');
        [$exit,$out]=$this->cli($this->applyArgs($manifest));$this->same(0,$exit,'actual CLI apply succeeds');$result=json_decode($out,true,512,JSON_THROW_ON_ERROR);$this->same(9,$result['registered_count'],'only reviewed ELIGIBLE candidates registered');
        $this->same($caseCount+9,$this->count('arpa_appointment_historical_approval_case'),'one case per eligible canonical appointment');$this->same($eventCount+9,$this->count('arpa_appointment_historical_approval_event'),'one REGISTER event per new case');$this->same($beforeSource,$this->operational(),'canonical appointments, requests, closures, provenance and corrections untouched');
        foreach($result['registered'] as $case)$this->same('DRAFT',$case['workflow_status'],'no automatic stage advancement');
        $after=$this->state();[$exit]=$this->cli($this->applyArgs($manifest));$this->same(1,$exit,'replaying old apply confirmation is safely rejected');$this->same($after,$this->state(),'old manifest retry creates no duplicate case/event');
        $repeat=$s->manifest(self::ADMIN);$this->same(0,$repeat['summary']['ELIGIBLE'],'registered candidates no longer eligible for insertion');$this->same(10,$repeat['summary']['ALREADY_REGISTERED'],'repeat dry-run reports existing cases');
        [$exit,$out]=$this->cli($this->applyArgs($repeat));$this->same(0,$exit,'fresh repeat is safe no-op');$this->same(0,json_decode($out,true)['registered_count'],'repeat creates no cases');$this->same($after,$this->state(),'repeat leaves all evidence intact');
        echo 'Synthetic dry-run: '.json_encode($manifest['summary'])."\n";foreach($manifest['candidates'] as $r)if($r['classification']==='MANUAL_REVIEW')echo 'Synthetic manual review: '.$r['reason']."\n";
    }
    private function safetyChecks():void
    {
        $service=new ArpaHistoricalApprovalRegistrationService($this->pdo);$s=$this->sources['valid-ended'];
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare('SELECT * FROM arpa_division_appointment WHERE id=?');$stmt->execute([$s['appointment']]);$a=$stmt->fetch();$a['id']=$this->uuid();$keys=array_keys($a);$this->pdo->prepare('INSERT INTO arpa_division_appointment(`'.implode('`,`',$keys).'`) VALUES('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($a));
            $r=$this->candidate($service->manifest(self::ADMIN),$s['appointment']);$this->same('MANUAL_REVIEW',$r['classification'],'duplicate canonical request representation denied');$this->same(true,str_contains($r['reason'],'duplicate canonical'),'duplicate canonical reason is explicit');
        }finally{$this->pdo->rollBack();}
        $this->pdo->beginTransaction();
        try{$this->pdo->prepare("UPDATE arpa_division_appointment SET appointment_type='UNKNOWN_TYPE' WHERE id=?")->execute([$s['appointment']]);$this->pdo->prepare("UPDATE arpa_division_appointment_request SET appointment_type='UNKNOWN_TYPE' WHERE id=?")->execute([$s['request']]);$r=$this->candidate($service->manifest(self::ADMIN),$s['appointment']);$this->same('MANUAL_REVIEW',$r['classification'],'unsupported canonical type denied');}finally{$this->pdo->rollBack();}
        $this->pdo->beginTransaction();
        try{$this->pdo->prepare('UPDATE arpa_division_appointment_closure SET data_correction_id=? WHERE appointment_id=?')->execute([$this->uuid(),$s['appointment']]);$r=$this->candidate($service->manifest(self::ADMIN),$s['appointment']);$this->same('MANUAL_REVIEW',$r['classification'],'unverified closure correction link denied');}finally{$this->pdo->rollBack();}
        $this->pdo->exec('RENAME TABLE arpa_appointment_correction_amendment_request TO fixture_hidden_lineage');try{$this->fails(fn()=>$service->manifest(self::ADMIN),'missing lineage fails closed rather than falling back to legacy predicates',503);}finally{$this->pdo->exec('RENAME TABLE fixture_hidden_lineage TO arpa_appointment_correction_amendment_request');}
        $this->pdo->exec('DROP TRIGGER trg_hist_approval_event_no_delete');try{$this->fails(fn()=>$service->manifest(self::ADMIN),'incomplete append-only audit schema fails closed',503);}finally{$this->pdo->exec("CREATE TRIGGER trg_hist_approval_event_no_delete BEFORE DELETE ON arpa_appointment_historical_approval_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical approval events are append-only'");}
    }
    private function compatibility():void
    {
        $this->owner();$actor='00000000-0000-4000-8000-000000000001';$row=$this->sources['acting'];$c=$this->pdo->query("SELECT * FROM arpa_appointment_historical_approval_case WHERE appointment_id='{$row['appointment']}'")->fetch();
        $service=new ArpaHistoricalAppointmentApprovalService($this->pdo);$this->same(true,$service->interfaceGet($c['id'],$actor)['actions']['submit'],'ASC can act on administrator-registered Draft');
        $d=$service->submit($c['id'],0,$actor);$this->same('SUBMITTED',$d['case']['workflow_status'],'dependent may submit before its Permanent case has completed approval');
        $read=new App\Services\ArpaHistoricalApprovalReadService($this->pdo);$q=$read->query($actor);$stmt=$this->pdo->prepare($q['sql']);$stmt->execute($q['params']);$rows=$stmt->fetchAll();
        $this->same(true,in_array($c['id'],array_column($rows,'case_id'),true),'registered and submitted case appears in the existing Phase 2 queue');$this->same(count($rows),$read->count($actor),'registered cases reconcile with historical queue count');
        $this->same(true,$read->canUse($actor),'existing ASC Working Context retains queue access');
        $row=$this->sources['missing-foundation'];$id=$this->pdo->query("SELECT id FROM arpa_appointment_historical_approval_case WHERE appointment_id='{$row['appointment']}'")->fetchColumn();$this->fails(fn()=>$service->submit($id,0,$actor),'registration never bypasses missing Permanent prerequisite',409);
        $this->admin();$this->fails(fn()=>$service->submit($c['id'],1,self::ADMIN),'CLI administrator gains no ASC submission permission',403);
        $this->pdo->exec('RENAME TABLE arpa_appointment_historical_approval_event TO fixture_hidden_event');try{$this->fails(fn()=>(new ArpaHistoricalApprovalRegistrationService($this->pdo))->manifest(self::ADMIN),'missing 067 fails closed',503);}finally{$this->pdo->exec('RENAME TABLE fixture_hidden_event TO arpa_appointment_historical_approval_event');}
    }
    private function concurrency():void
    {
        $new=$this->source('concurrent');$this->admin();$manifest=(new ArpaHistoricalApprovalRegistrationService($this->pdo))->manifest(self::ADMIN);$this->same(1,$manifest['summary']['ELIGIBLE'],'one fresh concurrent-registration candidate');$before=$this->operational();
        $workers=[];for($i=0;$i<2;$i++)$workers[]=$this->startCli($this->applyArgs($manifest));$exits=[];foreach($workers as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exits[]=proc_close($p);}sort($exits);$this->same([0,1],$exits,'concurrent apply accepts only one reviewed manifest');
        $stmt=$this->pdo->prepare('SELECT id,workflow_status FROM arpa_appointment_historical_approval_case WHERE appointment_id=?');$stmt->execute([$new['appointment']]);$rows=$stmt->fetchAll();$this->same(1,count($rows),'concurrent registration produces one case');$this->same('DRAFT',$rows[0]['workflow_status'],'concurrent winner stays Draft');
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM arpa_appointment_historical_approval_event WHERE approval_case_id=? AND action='REGISTER'");$stmt->execute([$rows[0]['id']]);$this->same(1,(int)$stmt->fetchColumn(),'concurrent registration produces one event');$this->same($before,$this->operational(),'concurrent registration changes no operational records');
    }
    private function source(string $name,string $type='PERMANENT',?string $officer=null,string $from='2020-01-01',?string $to=null):array{return $this->sources[$name]=$this->invoke($this->fixture,'source',$type,$officer,$from,$to);}
    private function cloneCorrection(string $source,array $changes=[]):string{$s=$this->pdo->prepare('SELECT * FROM arpa_appointment_data_correction WHERE id=?');$s->execute([$source]);$row=array_replace($s->fetch(),['id'=>$this->uuid()],$changes);$keys=array_keys($row);$this->pdo->prepare('INSERT INTO arpa_appointment_data_correction(`'.implode('`,`',$keys).'`) VALUES('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($row));return $row['id'];}
    private function amendment(array $s):string{$id=$this->uuid();$this->pdo->prepare("INSERT INTO arpa_appointment_correction_amendment_request(id,source_correction_id,appointment_id,officer_id,asc_location_id,proposed_action,baseline_json,baseline_hash,proposed_json,correction_reason,created_by) VALUES(?,?,?,?,?,'RESOLVE_CANONICAL_ASSIGNMENT','{}',?,'{}','Synthetic evidence',?)")->execute([$id,$s['correction'],$s['appointment'],$s['officer'],'10000000-0000-4000-8000-000000000001',str_repeat('a',64),self::ADMIN]);return $id;}
    private function input():array{return ['reason'=>'Synthetic registration review','evidence_reference'=>'Synthetic verified HR register'];}
    private function applyArgs(array $m):array{return ['--apply','--database='.$m['database']['database_name'],'--server-uuid='.$m['database']['server_uuid'],'--manifest-hash='.$m['manifest_hash'],'--confirm='.$m['confirmation'],'--reason=Synthetic CLI registration','--evidence-reference=Synthetic verified HR register'];}
    private function startCli(array $args):array{$env=getenv();$env['DB_DATABASE']=$this->db;$pipes=[];$p=proc_open(array_merge([PHP_BINARY,'-d','xdebug.mode=off',BASE_PATH.'/bin/register-arpa-historical-approvals.php','--operator='.self::ADMIN,'--role-assignment='.self::ROLE],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH,$env);if(!is_resource($p))throw new RuntimeException('Cannot start isolated CLI worker.');return [$p,$pipes];}
    private function cli(array $args):array{[$p,$pipes]=$this->startCli($args);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);if($exit===0&&$err!=='')throw new RuntimeException('Unexpected CLI error: '.$err);return [$exit,$out,$err];}
    private function owner():void{$this->invoke($this->fixture,'context');}
    private function admin():void{$_SESSION=['user_id'=>self::ADMIN,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();(new UserContextService($this->pdo))->select(self::ADMIN,self::ROLE,null);Auth::forgetRequestCache();}
    private function candidate(array $m,string $id):array{foreach($m['candidates'] as $r)if($r['appointment_id']===$id)return $r;throw new RuntimeException('Expected candidate absent.');}
    private function state():array{$out=[];foreach($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t)$out[$t]=$this->pdo->query('SELECT * FROM `'.$t.'` ORDER BY 1')->fetchAll();return $out;}
    private function operational():array{return $this->invoke($this->fixture,'operational');}
    private function invoke(object $o,string $m,mixed ...$args):mixed{return(new ReflectionMethod($o,$m))->invoke($o,...$args);}
    private function count(string $t):int{return (int)$this->pdo->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.'; expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function fails(callable $work,string $message,?int $code=null,bool $any=false):void{$this->assertions++;try{$work();}catch(Throwable $e){if(!$any&&!$e instanceof DomainException)throw $e;if($code!==null&&$e->getCode()!==$code)throw new RuntimeException($message.'; unexpected code '.$e->getCode());return;}throw new RuntimeException($message.'; expected rejection');}
}
try{(new ArpaHistoricalApprovalRegistrationIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
