<?php
declare(strict_types=1);
define('ARPA_CORRECTION_DRAFT_FIXTURE_ONLY',true);
require __DIR__.'/ArpaAppointmentCorrectionAmendmentDraftTest.php';

use App\Core\{Auth,Database};
use App\Services\{ArpaLegacyRequestExclusionService,ArpaAppointmentDataIssueCorrectionService,ArpaAppointmentReadService,ArpaDivisionContinuityService,UserContextService};

final class ArpaLegacyRequestExclusionIsolatedTest
{
    private const ADMIN='00000000-0000-4000-8000-000000000001';
    private const OFFICER='be3c5c14-7393-4d54-9cab-92cd327deefa';
    private const DIVISION='8376ac85-46cf-441e-b3ae-e39507bd8bf4';
    private const ASC='10000000-0000-4000-8000-000000000001';
    private const FIRST='febc95d9-96f9-11f1-8055-fc5cee2ebe64';
    private const SECOND='f623cc94-96f8-11f1-8055-fc5cee2ebe64';
    private PDO $pdo;
    private int $assertions=0;
    private string $name;

    public function run():void
    {
        $c=config('database');if(!in_array($c['host'],['127.0.0.1','localhost'],true))throw new RuntimeException('Use a local isolated server.');
        $this->name='dems_test_legacy_exclusion_'.bin2hex(random_bytes(5));if(!preg_match('/^dems_test_legacy_exclusion_[a-f0-9]{10}$/D',$this->name))throw new RuntimeException('Unsafe fixture database.');
        $server=new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4',$c['host'],$c['port']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $property=new ReflectionProperty(Database::class,'pdo');$old=$property->getValue();$oldSession=$_SESSION??[];$created=false;
        try{
            $server->exec('CREATE DATABASE `'.$this->name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
            $this->pdo=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port'],$this->name),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
            if($this->pdo->query('SELECT DATABASE()')->fetchColumn()!==$this->name)throw new RuntimeException('Wrong database before fixture writes.');
            $property->setValue(null,$this->pdo);$this->pdo->exec("SET SESSION default_storage_engine='InnoDB'");
            // Reuse the established isolated schema, identities and canonical correction fixtures.
            $fixture=new ArpaAppointmentCorrectionAmendmentDraftTest();(new ReflectionProperty($fixture,'pdo'))->setValue($fixture,$this->pdo);
            foreach(['createFixtureSchema'] as $method)(new ReflectionMethod($fixture,$method))->invoke($fixture);
            $this->pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/062_arpa_appointment_correction_amendment_foundation.sql'));
            (new ReflectionMethod($fixture,'insertFixtures'))->invoke($fixture);
            (new ReflectionMethod($fixture,'exerciseHistoricalPeerDecisions'))->invoke($fixture);
            $this->fixtures();$this->exercise();$this->parallel();$this->interfaceChecks();$this->schemaCompatibility();
            echo 'ArpaLegacyRequestExclusionIsolatedTest: '.$this->assertions.' assertions passed; MySQL '.$server->getAttribute(PDO::ATTR_SERVER_VERSION)."; disposable database removed.\n";
        }finally{$property->setValue(null,$old);$_SESSION=$oldSession;Auth::forgetRequestCache();if($created)$server->exec('DROP DATABASE `'.$this->name.'`');}
    }
    private function fixtures():void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS arpa_appointment_workflow_action(id BIGINT AUTO_INCREMENT PRIMARY KEY,request_id CHAR(36),action VARCHAR(50),stage VARCHAR(50));
            ALTER TABLE audit_event ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
            UPDATE system_user SET username='dems.admin' WHERE id='".self::ADMIN."';
            INSERT INTO application_role VALUES('legacy-system-role','SYSTEM_ADMIN','System Administrator','SYSTEM',1,'APPROVED');
            INSERT INTO application_role_permission VALUES('legacy-system-role','13000000-0000-4000-8000-000000000011');
            INSERT INTO application_role_permission VALUES('12000000-0000-4000-8000-000000000001','13000000-0000-4000-8000-000000000011');
            INSERT INTO user_account_role VALUES('legacy-system-assignment','".self::ADMIN."','legacy-system-role',1,'APPROVED',CURRENT_DATE(),NULL);
            INSERT INTO designation VALUES('legacy-arpa-designation','ARPA_OFFICER');
            INSERT INTO officer(id,dad_number,name_with_initials,primary_designation_id) VALUES('".self::OFFICER."','70045-0002795','Synthetic exclusion Officer','legacy-arpa-designation');
            INSERT INTO officer_office_assignment VALUES('legacy-case-office','".self::OFFICER."','1b000000-0000-4000-8000-000000000001',1,'APPROVED','2025-01-01',NULL);
            INSERT INTO location VALUES('".self::DIVISION."','11000000-0000-4000-8000-000000000002','70007-0005666','Synthetic Thalapitaoya'),('legacy-province','11000000-0000-4000-8000-000000000003','70001-X','Synthetic Province');
            INSERT INTO location_relationship VALUES('legacy-arpa-rel','".self::ASC."','".self::DIVISION."','ASC_ARPA_DIVISION',1,'APPROVED','2024-01-05',NULL),('legacy-province-rel','legacy-province','19000000-0000-4000-8000-000000000001','PROVINCE_DISTRICT',1,'APPROVED','2024-01-05',NULL);
            CREATE TABLE legacy_arpa_location_repair_item(id INT PRIMARY KEY,request_id CHAR(36),appointment_id CHAR(36),closure_id CHAR(36),before_target_json JSON,corrected_target_json JSON);
            CREATE TABLE unexpected_request_dependency(id INT PRIMARY KEY,request_id CHAR(36),FOREIGN KEY(request_id) REFERENCES arpa_division_appointment_request(id));
            CREATE TABLE system_notification(id CHAR(36) PRIMARY KEY,entity_type VARCHAR(80),entity_id CHAR(36),notification_type VARCHAR(50),action_status VARCHAR(50));");
        $this->make(self::FIRST,'2020-09-21');$this->make(self::SECOND,'2026-01-01');
        $this->pdo->prepare("INSERT INTO legacy_arpa_location_repair_item VALUES(1,?,NULL,NULL,'{}','{}')")->execute([self::FIRST]);
        // Baseline shared layout reads notification context in one set-based query.
        $this->pdo->exec("ALTER TABLE system_notification ADD recipient_user_id CHAR(36),ADD read_at DATETIME NULL,ADD priority VARCHAR(20),ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP;
            ALTER TABLE system_user ADD officer_id CHAR(36);
            ALTER TABLE officer ADD primary_office_id CHAR(36);
            ALTER TABLE office ADD dad_number VARCHAR(50),ADD name_en VARCHAR(255);
            ALTER TABLE officer_office_assignment ADD reason VARCHAR(255);
            ALTER TABLE user_account_role ADD reason VARCHAR(255);
            ALTER TABLE user_account_scope ADD office_id CHAR(36);
            CREATE TABLE officer_edit_request(id CHAR(36) PRIMARY KEY,officer_id CHAR(36));
            CREATE TABLE arpa_subject_assignment_request(id CHAR(36) PRIMARY KEY,officer_id CHAR(36),asc_location_id CHAR(36));");
    }
    private function make(string $id,string $from='2025-01-01'):void
    {
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,legacy_history_only,legacy_exception,legacy_exception_codes_json,origin_metadata_json) VALUES(?,'LEGACY_IMPORT','APPOINTMENT',?,'PERMANENT',?,?,?,'CREATED',1,1,'[\"INCOMPLETE_LEGACY_WORKFLOW\"]','{\"evidence\":\"synthetic\"}')")->execute([$id,self::OFFICER,self::ASC,self::DIVISION,$from]);
        $this->pdo->prepare("INSERT INTO legacy_arpa_appointment_source_reference(id,target_appointment_request_id,source_system,source_table,legacy_appointment_id) VALUES(?,?,'dems_legacy_hr','tbl_officer_apoint','synthetic')")->execute([$id,$id]);
    }
    private function exercise():void
    {
        $service=new ArpaLegacyRequestExclusionService($this->pdo);$this->context();
        $before=$this->request(self::FIRST);$sources=$this->rows('SELECT * FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=?',[self::FIRST]);
        $repairs=$this->rows('SELECT * FROM legacy_arpa_location_repair_item');
        $otherState=$this->unrelated();$preview=$service->preview(self::FIRST,self::ADMIN);$this->same(true,$preview['eligible'],'synthetic unresolved request is eligible for evidence-backed exclusion');
        foreach(['00000000-0000-4000-8000-000000000004','00000000-0000-4000-8000-000000000005','00000000-0000-4000-8000-000000000007','00000000-0000-4000-8000-000000000009'] as $actor){$this->context($actor,null);$this->throws(fn()=>$service->preview(self::FIRST,$actor),'ASC Subject/Admin, District and National cannot inspect removal',403);$this->throws(fn()=>$service->exclude(self::FIRST,[], $actor),'unauthorized direct removal denied',403);}
        $this->context(self::ADMIN,'ASC_SUBJECT_OFFICER');$this->throws(fn()=>$service->preview(self::FIRST,self::ADMIN),'canonical administrator in wrong Working Context denied',403);
        $this->context();$this->pdo->beginTransaction();try{$this->pdo->exec("UPDATE user_account_role SET active=0 WHERE id='legacy-system-assignment'");Auth::forgetRequestCache();$this->throws(fn()=>$service->preview(self::FIRST,self::ADMIN),'inactive administrator assignment cannot authorize removal',403);}finally{$this->pdo->rollBack();}$this->context();
        $this->context();$input=$this->input($preview);
        foreach(['delete_reason','evidence_reference','confirmation_id','preview_hash','confirm_delete'] as $field){$invalid=$input;unset($invalid[$field]);$this->throws(fn()=>$service->exclude(self::FIRST,$invalid,self::ADMIN),'required '.$field);}
        $bad=$input;$bad['confirmation_id']=self::SECOND;$this->throws(fn()=>$service->exclude(self::FIRST,$bad,self::ADMIN),'cross-record confirmation denied');
        $this->pdo->prepare("UPDATE arpa_division_appointment_request SET request_remarks='Changed after preview' WHERE id=?")->execute([self::FIRST]);$this->throws(fn()=>$service->exclude(self::FIRST,$input,self::ADMIN),'stale preview rejected',409);
        $this->pdo->prepare('UPDATE arpa_division_appointment_request SET request_remarks=NULL WHERE id=?')->execute([self::FIRST]);
        $this->context(self::ADMIN,'ASC_SUBJECT_OFFICER');$correction=new ArpaAppointmentDataIssueCorrectionService($this->pdo);
        $resolve=['correction_action'=>'RESOLVE_CANONICAL_ASSIGNMENT','correction_reason'=>'Synthetic verified evidence','appointment_status'=>'OPEN'];
        $this->throws(fn()=>$correction->correct('LEGACY_HISTORICAL_EXCEPTION:'.self::SECOND,$resolve,self::ADMIN),'overlapping unresolved peer blocks initially');
        $this->context();$preview=$service->preview(self::FIRST,self::ADMIN);$service->exclude(self::FIRST,$this->input($preview),self::ADMIN);
        $after=$this->request(self::FIRST);$this->same(true,$after['deleted_at']!==null,'existing soft-delete marker set');$this->same(self::ADMIN,$after['deleted_by'],'actor stored');
        foreach($before as $key=>$value)if(!in_array($key,['deleted_at','deleted_by','delete_reason','version'],true))$this->same($value,$after[$key],'original imported field retained: '.$key);
        $this->same((int)$before['version']+1,(int)$after['version'],'one version increment');
        $this->same($sources,$this->rows('SELECT * FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=?',[self::FIRST]),'source provenance untouched');$this->same($repairs,$this->rows('SELECT * FROM legacy_arpa_location_repair_item'),'location-repair evidence retained');
        $audit=$service->audit(self::ADMIN,self::FIRST);$this->same(1,count($audit),'exactly one independent audit record');$expectedSnapshot=$before;$actualSnapshot=$audit[0]['details']['previous_values'];ksort($expectedSnapshot);ksort($actualSnapshot);$this->same($expectedSnapshot,$actualSnapshot,'complete original request captured (JSON object key order is immaterial)');$this->same('Synthetic supporting document',$audit[0]['details']['evidence_reference'],'evidence reference retained');
        $this->throws(fn()=>$service->exclude(self::FIRST,$input,self::ADMIN),'duplicate exclusion rejected',409);
        $this->same(null,$correction->issue('LEGACY_HISTORICAL_EXCEPTION:'.self::FIRST),'excluded source disappears from active issue source');
        $issues=(new ArpaDivisionContinuityService($this->pdo))->unresolvedDataIssues(self::DIVISION);$this->same(false,in_array('LEGACY_HISTORICAL_EXCEPTION:'.self::FIRST,array_column($issues,'row_key'),true),'continuity no longer counts excluded issue');
        $this->same(null,(new ReflectionMethod($correction,'historicalRequest'))->invoke($correction,self::FIRST,true),'locked historical read rejects excluded source, including a stale correction race');
        // The previous reflection call uses FOR UPDATE in autocommit and cannot modify data.
        $this->context(self::ADMIN,'ASC_SUBJECT_OFFICER');
        $this->pdo->beginTransaction();try{
            $pending='60000000-0000-4000-8000-000000000099';$this->make($pending,'2026-01-01');
            $this->pdo->prepare("UPDATE arpa_division_appointment_request SET record_origin='NATIVE',legacy_history_only=0,legacy_exception=0,workflow_status='SUBMITTED' WHERE id=?")->execute([$pending]);
            $this->throws(fn()=>$correction->correct('LEGACY_HISTORICAL_EXCEPTION:'.self::SECOND,$resolve,self::ADMIN),'genuine pending reservation still blocks after exclusion');
            $this->same('SUBMITTED',$this->request($pending)['workflow_status'],'reservation is not cancelled by exclusion');
        }finally{$this->pdo->rollBack();}
        $resolved=$correction->correct('LEGACY_HISTORICAL_EXCEPTION:'.self::SECOND,$resolve,self::ADMIN);
        $this->same('RESOLVED_BY_CORRECTION',$resolved['resolution_status'],'remaining valid synthetic source resolves through ordinary ASC correction');
        $this->same(0,count((new ArpaDivisionContinuityService($this->pdo))->unresolvedDataIssues(self::DIVISION)),'no excluded blocker remains after valid correction');
        $this->throws(fn()=>(new ArpaAppointmentReadService($this->pdo))->assertDivisionPeriodDoesNotOverlap(self::DIVISION,'2026-01-01'),'canonical overlap protection unchanged');
        $this->context();$this->same(false,$service->preview(self::SECOND,self::ADMIN)['eligible'],'canonical request cannot be excluded');
        $this->same($otherState,$this->unrelated(),'no unrelated Officers or Divisions changed');

        $third='60000000-0000-4000-8000-000000000003';$this->make($third);
        foreach([
            "UPDATE arpa_division_appointment_request SET record_origin='NATIVE' WHERE id='{$third}'",
            "UPDATE arpa_division_appointment_request SET workflow_status='SUBMITTED' WHERE id='{$third}'",
            "UPDATE arpa_division_appointment_request SET legacy_history_only=0 WHERE id='{$third}'",
            "INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,asc_location_id,arpa_division_location_id,effective_from) VALUES('exclude-canonical','LEGACY_IMPORT','{$third}','".self::OFFICER."','PERMANENT','".self::ASC."','".self::DIVISION."','2025-01-01')",
            "INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,context_snapshot_json,approval_timestamp_provenance) VALUES('exclude-closure','LEGACY_IMPORT','30000000-0000-4000-8000-000000000001','{$third}','2025-12-31','{}','UNAVAILABLE_FROM_LEGACY_SOURCE')",
            "INSERT INTO arpa_appointment_workflow_action(request_id,action,stage) VALUES('{$third}','SUBMIT','CREATOR')",
            "INSERT INTO system_notification(id,entity_type,entity_id,notification_type,action_status) VALUES('exclude-notification','ARPA_DIVISION_REQUEST','{$third}','ACTION_REQUIRED','PENDING')",
            "UPDATE legacy_arpa_appointment_source_reference SET target_appointment_id='30000000-0000-4000-8000-000000000001' WHERE target_appointment_request_id='{$third}'",
            "INSERT INTO unexpected_request_dependency VALUES(1,'{$third}')",
            "INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,request_id,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin) VALUES('exclude-correction','x','LEGACY_HISTORICAL_EXCEPTION','".self::OFFICER."','{$third}','".self::ASC."','".self::ADMIN."','KEEP_AS_HISTORICAL_EXCEPTION','KEPT_HISTORICAL_EXCEPTION','Fixture','{}','{}','LEGACY_IMPORT')",
            "INSERT INTO arpa_appointment_correction_amendment_request(id,source_correction_id,appointment_id,officer_id,asc_location_id,proposed_action,baseline_json,baseline_hash,proposed_json,correction_reason,created_by) VALUES('exclude-amendment','50000000-0000-4000-8000-000000000001','30000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001','".self::ASC."','SET_EFFECTIVE_TO','{\"referenced_request\":\"{$third}\"}',REPEAT('0',64),'{}','Fixture','".self::ADMIN."')",
            "INSERT INTO arpa_appointment_correction_amendment_request(id,source_correction_id,appointment_id,officer_id,asc_location_id,proposed_action,baseline_json,baseline_hash,proposed_json,correction_reason,created_by,workflow_status) VALUES('exclude-completed','50000000-0000-4000-8000-000000000001','30000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001','".self::ASC."','SET_EFFECTIVE_TO','{}',REPEAT('0',64),'{\"referenced_request\":\"{$third}\"}','Fixture','".self::ADMIN."','NATIONAL_APPROVED')",
        ] as $sql){$this->pdo->beginTransaction();try{$this->pdo->exec($sql);$this->same(false,$service->preview($third,self::ADMIN)['eligible'],'dependency or unsafe state blocked');$this->throws(fn()=>$service->exclude($third,$this->input($service->preview($third,self::ADMIN)),self::ADMIN),'backend repeats dependency check',409);}finally{$this->pdo->rollBack();}}
        $p=$service->preview($third,self::ADMIN);$snapshot=$this->request($third);$audits=count($service->audit(self::ADMIN));
        $this->throws(fn()=>(new \App\Services\ArpaAppointmentAdministrationService($this->pdo))->deleteRequest($third,'Bypass attempt',self::ADMIN),'older generic delete service cannot bypass evidence/confirmation checks');
        $this->pdo->exec("CREATE TRIGGER fail_exclusion_audit BEFORE INSERT ON audit_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced audit failure'");
        try{$this->throws(fn()=>$service->exclude($third,$this->input($p),self::ADMIN),'audit insertion failure rolls back',null,true);}finally{$this->pdo->exec('DROP TRIGGER fail_exclusion_audit');}
        $this->same($snapshot,$this->request($third),'failed audit cannot exclude request');$this->same($audits,count($service->audit(self::ADMIN)),'failed transaction appends no audit');
    }
    private function parallel():void
    {
        $id='60000000-0000-4000-8000-000000000004';$this->make($id);$this->context();$service=new ArpaLegacyRequestExclusionService($this->pdo);$input=$this->input($service->preview($id,self::ADMIN));
        $code=<<<'PHP'
$root=$argv[1];require $root.'/bootstrap.php';$db=$argv[2];if(!preg_match('/^dems_test_legacy_exclusion_[a-f0-9]{10}$/D',$db))exit(90);$c=config('database');$p=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port'],$db),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);(new ReflectionProperty(App\Core\Database::class,'pdo'))->setValue(null,$p);$actor='00000000-0000-4000-8000-000000000001';$_SESSION=['user_id'=>$actor,'authenticated_at'=>time(),'last_activity_at'=>time()];(new App\Services\UserContextService($p))->select($actor,'legacy-system-assignment',null);App\Core\Auth::forgetRequestCache();try{(new App\Services\ArpaLegacyRequestExclusionService($p))->exclude($argv[3],json_decode(base64_decode($argv[4]),true),$actor);echo 'EXCLUDED';}catch(DomainException $e){echo 'BLOCKED';}catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage());exit(1);}
PHP;
        $this->pdo->beginTransaction();$s=$this->pdo->prepare('SELECT id FROM location WHERE id=? FOR UPDATE');$s->execute([self::DIVISION]);$workers=[];
        try{for($i=0;$i<2;$i++){$pipes=[];$p=proc_open([PHP_BINARY,'-d','xdebug.mode=off','-r',$code,BASE_PATH,$this->name,$id,base64_encode(json_encode($input))],[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);if(!is_resource($p))throw new RuntimeException('Cannot start concurrent test.');$workers[]=[$p,$pipes];}}finally{$this->pdo->rollBack();}
        $out=[];foreach($workers as [$p,$pipes]){$out[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$this->same(0,proc_close($p),'concurrent worker exits safely: '.$err);}sort($out);$this->same(['BLOCKED','EXCLUDED'],$out,'two independent concurrent attempts produce one exclusion');$this->same(1,count($service->audit(self::ADMIN,$id)),'concurrent duplicate creates no second audit');
    }
    private function interfaceChecks():void
    {
        $this->context();$id='60000000-0000-4000-8000-000000000003';$preview=(new ArpaLegacyRequestExclusionService($this->pdo))->preview($id,self::ADMIN);$input=[];
        ob_start();require BASE_PATH.'/app/Views/arpa_appointments/legacy_request_exclusion.php';$html=ob_get_clean();
        foreach(['Dependency preview','name="_csrf"','name="delete_reason"','name="evidence_reference"','name="confirmation_id"','name="confirm_delete"','name="preview_hash"','method="post"'] as $needle)$this->same(true,str_contains($html,$needle),'preview form includes '.$needle);
        $controller=file_get_contents(BASE_PATH.'/app/Controllers/ArpaLegacyRequestExclusionController.php');$this->same(true,str_contains($controller,'Csrf::validate()'),'POST enforces CSRF');
        $routes=file_get_contents(BASE_PATH.'/routes/web.php');$this->same(true,str_contains($routes,"post('/hr/arpa-appointments/legacy-requests/{id}/exclude'"),'POST route registered');
        $this->same(false,str_contains($routes,"get('/hr/arpa-appointments/legacy-requests/{id}/restore'"),'no unsafe restoration endpoint');
        $beforeGet=[$this->request($id),$this->rows('SELECT * FROM arpa_division_appointment ORDER BY id'),$this->rows('SELECT * FROM arpa_appointment_data_correction ORDER BY id'),count((new ArpaLegacyRequestExclusionService($this->pdo))->audit(self::ADMIN))];
        [$status,$body]=$this->route('GET','/hr/arpa-appointments/legacy-requests/'.$id.'/exclude');$this->same(200,$status,'authorized routed dependency preview renders');$this->same(true,str_contains($body,'Dependency preview')&&str_contains($body,'Supporting evidence reference'),'actual controller uses dependency/evidence form');
        $this->same(true,str_contains($body,'Synthetic Thalapitaoya'),'preview identifies the exact Division');
        $this->same($beforeGet,[$this->request($id),$this->rows('SELECT * FROM arpa_division_appointment ORDER BY id'),$this->rows('SELECT * FROM arpa_appointment_data_correction ORDER BY id'),count((new ArpaLegacyRequestExclusionService($this->pdo))->audit(self::ADMIN))],'GET preview cannot write appointment, correction, source request or exclusion audit');
        $issuePath='/hr/arpa-appointments/issues/'.rawurlencode('LEGACY_HISTORICAL_EXCEPTION:'.$id);
        [$status,$body]=$this->route('GET',$issuePath);$this->same(200,$status,'administrator can review original data issue');$this->same(true,str_contains($body,'Preview exclusion of an unnecessary legacy request'),'administrator entry point shown on original issue');
        [$status,$body]=$this->route('GET',$issuePath,[],'00000000-0000-4000-8000-000000000004');$this->same(200,$status,'assigned ASC Subject Officer retains ordinary issue review');$this->same(false,str_contains($body,'Preview exclusion of an unnecessary legacy request'),'ASC issue review does not show administrator removal');
        foreach(['00000000-0000-4000-8000-000000000004','00000000-0000-4000-8000-000000000005','00000000-0000-4000-8000-000000000007','00000000-0000-4000-8000-000000000009'] as $actor){[$status]=$this->route('GET','/hr/arpa-appointments/legacy-requests/'.$id.'/exclude',[],$actor);$this->same(403,$status,'unauthorized direct GET denied');[$status]=$this->route('POST','/hr/arpa-appointments/legacy-requests/'.$id.'/exclude',[],$actor);$this->same(403,$status,'unauthorized direct POST denied');}
        $service=new ArpaLegacyRequestExclusionService($this->pdo);$input=$this->input($service->preview($id,self::ADMIN));$before=$this->request($id);
        [$status]=$this->route('POST','/hr/arpa-appointments/legacy-requests/'.$id.'/exclude',$input,self::ADMIN,false);$this->same(419,$status,'invalid CSRF rejected by actual POST route');$this->same($before,$this->request($id),'invalid CSRF cannot modify request');
        $invalid=$input;$invalid['delete_reason']='';[$status,$body]=$this->route('POST','/hr/arpa-appointments/legacy-requests/'.$id.'/exclude',$invalid);$this->same(422,$status,'missing reason rejected');$this->same(true,str_contains($body,'Synthetic supporting document'),'validation error retains entered evidence');
        $fifth='60000000-0000-4000-8000-000000000005';$this->make($fifth);$input=$this->input($service->preview($fifth,self::ADMIN));[$status]=$this->route('POST','/hr/arpa-appointments/legacy-requests/'.$fifth.'/exclude',$input);$this->same(302,$status,'valid confirmed synthetic exclusion through POST redirects to audit');
        [$status,$body]=$this->route('GET','/hr/arpa-appointments/legacy-requests/'.$fifth.'/exclusion');$this->same(200,$status,'administrator read-only audit route works');$this->same(true,str_contains($body,'Synthetic supporting document')&&str_contains($body,$fifth),'audit shows preserved evidence and identity');
        [$status]=$this->route('POST','/hr/arpa-appointments/legacy-requests/'.$fifth.'/exclude',$input);$this->same(409,$status,'duplicate POST is rejected');$this->same(1,count($service->audit(self::ADMIN,$fifth)),'duplicate POST cannot duplicate audit');
        [$status]=$this->route('GET','/hr/arpa-appointments/legacy-requests/'.$fifth.'/exclusion',[],'00000000-0000-4000-8000-000000000005');$this->same(403,$status,'administrator audit not exposed to ASC Admin');
    }
    private function schemaCompatibility():void
    {
        $this->context();$id='60000000-0000-4000-8000-000000000003';$service=new ArpaLegacyRequestExclusionService($this->pdo);
        // Optional amendment storage can be absent; do not require a feature flag or later migration.
        $this->pdo->exec('RENAME TABLE arpa_appointment_correction_amendment_event TO fixture_hidden_amendment_event,arpa_appointment_correction_amendment_request TO fixture_hidden_amendment_request');
        try{$this->same(true,$service->preview($id,self::ADMIN)['eligible'],'existing soft-delete mechanism works without amendment tables');}
        finally{$this->pdo->exec('RENAME TABLE fixture_hidden_amendment_event TO arpa_appointment_correction_amendment_event,fixture_hidden_amendment_request TO arpa_appointment_correction_amendment_request');}
        $this->pdo->exec('ALTER TABLE arpa_division_appointment_request RENAME COLUMN delete_reason TO fixture_hidden_reason');
        try{$p=$service->preview($id,self::ADMIN);$this->same(false,$p['eligible'],'missing soft-delete schema fails closed');$this->throws(fn()=>$service->exclude($id,$this->input($p),self::ADMIN),'missing schema cannot be bypassed by direct service',409);}
        finally{$this->pdo->exec('ALTER TABLE arpa_division_appointment_request RENAME COLUMN fixture_hidden_reason TO delete_reason');}
    }
    private function route(string $method,string $path,array $post=[],string $actor=self::ADMIN,bool $csrf=true):array
    {
        $code=<<<'PHP'
require $argv[1].'/bootstrap.php';$db=$argv[2];if(!preg_match('/^dems_test_legacy_exclusion_[a-f0-9]{10}$/D',$db))exit(90);$c=config('database');$p=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port'],$db),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);(new ReflectionProperty(App\Core\Database::class,'pdo'))->setValue(null,$p);$actor=$argv[3];$_SESSION=['user_id'=>$actor,'authenticated_at'=>time(),'last_activity_at'=>time(),'_csrf'=>'fixture-csrf'];$contexts=(new App\Services\UserContextService($p))->availableContexts($actor);$context=$contexts[0];foreach($contexts as $candidate)if(($actor==='00000000-0000-4000-8000-000000000001'&&$candidate['role_code']==='SYSTEM_ADMIN')||($actor==='00000000-0000-4000-8000-000000000004'&&$candidate['role_code']==='ASC_SUBJECT_OFFICER')){$context=$candidate;break;}(new App\Services\UserContextService($p))->select($actor,$context['role_assignment_id'],$context['scope_assignment_id']);App\Core\Auth::forgetRequestCache();$_POST=json_decode(base64_decode($argv[6]),true);$_POST['_csrf']=$argv[7]==='1'?'fixture-csrf':'invalid-csrf';$_SERVER['REQUEST_URI']=$argv[5];$_SERVER['REQUEST_METHOD']=$argv[4];http_response_code(200);register_shutdown_function(static function(){echo "\nROUTED_STATUS=".http_response_code();});$router=new App\Core\Router();require BASE_PATH.'/routes/web.php';$router->dispatch($argv[4],$argv[5]);
PHP;
        $pipes=[];$process=proc_open([PHP_BINARY,'-d','xdebug.mode=off','-d','display_errors=0','-r',$code,BASE_PATH,$this->name,$actor,$method,$path,base64_encode(json_encode($post)),$csrf?'1':'0'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);if(!is_resource($process))throw new RuntimeException('Cannot start routed request test.');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException('Routed request failed: '.$err);if(!preg_match('/ROUTED_STATUS=(\d+)$/',$out,$m))throw new RuntimeException('No routed status: '.$err);return [(int)$m[1],$out];
    }
    private function input(array $p):array{return ['delete_reason'=>'Synthetic evidence confirms this record is unnecessary','evidence_reference'=>'Synthetic supporting document','confirmation_id'=>$p['request']['id'],'confirm_delete'=>'1','preview_hash'=>$p['preview_hash']];}
    private function context(string $actor=self::ADMIN,?string $role='SYSTEM_ADMIN'):void{$_SESSION=['user_id'=>$actor,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();$svc=new UserContextService($this->pdo);foreach($svc->availableContexts($actor) as $c)if($role===null||$c['role_code']===$role){$svc->select($actor,$c['role_assignment_id'],$c['scope_assignment_id']);Auth::forgetRequestCache();return;}throw new RuntimeException('Fixture context unavailable.');}
    private function request(string $id):array{return $this->rows('SELECT * FROM arpa_division_appointment_request WHERE id=?',[$id])[0];}
    private function rows(string $sql,array $args=[]):array{$s=$this->pdo->prepare($sql);$s->execute($args);return $s->fetchAll();}
    private function unrelated():array{$out=[];foreach(['arpa_division_appointment','arpa_division_appointment_request','arpa_appointment_data_correction'] as $t)$out[$t]=$this->rows('SELECT * FROM '.$t.' WHERE officer_id<>? ORDER BY id',[self::OFFICER]);return $out;}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.'; expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $work,string $message,?int $code=null,bool $any=false):void{$this->assertions++;try{$work();}catch(Throwable $e){if(!$any&&!$e instanceof DomainException)throw $e;if($code!==null&&(int)$e->getCode()!==$code)throw new RuntimeException($message.'; wrong code '.$e->getCode());return;}throw new RuntimeException($message.'; expected failure');}
}
try{(new ArpaLegacyRequestExclusionIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
