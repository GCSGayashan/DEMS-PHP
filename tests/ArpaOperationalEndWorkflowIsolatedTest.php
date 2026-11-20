<?php
declare(strict_types=1);
define('ARPA_HISTORICAL_RELEASE_FIXTURE_ONLY',true);
require __DIR__.'/ArpaHistoricalReleaseSecurityIsolatedTest.php';

use App\Core\{Auth,Database,DataTableQuery,DataTableRegistry,DataTableRequest};
use App\Services\{ArpaAppointmentService,ArpaAppointmentApprovalQualificationPolicy as Qualification};

final class ArpaOperationalEndWorkflowIsolatedTest
{
    private PDO $pdo;private object $fixture;private object $ui;private string $db;private int $assertions=0;
    private const ASC='10000000-0000-4000-8000-000000000001';
    private const REASON='18000000-0000-4000-8000-000000000001';
    public function run():void
    {
        $c=config('database');if($c['host']!=='127.0.0.1'||!str_starts_with($c['database'],'dems_test_'))throw new RuntimeException('Disposable local configuration required.');
        $db='dems_test_hist_ui_'.bin2hex(random_bytes(5));$this->db=$db;$created=false;
        $server=new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $property=new ReflectionProperty(Database::class,'pdo');$old=$property->getValue();$session=$_SESSION??[];
        try{
            $server->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
            $this->pdo=new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$db};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true,PDO::MYSQL_ATTR_INIT_COMMAND=>'SET SESSION default_storage_engine=InnoDB']);
            $property->setValue(null,$this->pdo);if($this->pdo->query('SELECT DATABASE()')->fetchColumn()!==$db)throw new RuntimeException('Wrong fixture database.');
            $sources=ArpaHistoricalReleaseSecurityIsolatedTest::fixture($this->pdo);$this->schema();
            $this->fixture=new ArpaHistoricalAppointmentApprovalIsolatedTest();(new ReflectionProperty($this->fixture,'pdo'))->setValue($this->fixture,$this->pdo);
            $this->ui=new ArpaHistoricalApprovalUiIsolatedTest();(new ReflectionProperty($this->ui,'db'))->setValue($this->ui,$db);
            $service=new ArpaAppointmentService($this->pdo);$qualification=new Qualification($this->pdo);
            $this->nativeConcurrentEnd($service,$qualification,$sources[1],$sources[2]);
            $this->importedFutureEnd($service,$qualification,$sources[0],$sources[2]);
            echo 'ArpaOperationalEndWorkflowIsolatedTest: '.$this->assertions." assertions passed; disposable schema removed.\n";
        }finally{$property->setValue(null,$old);$_SESSION=$session;Auth::forgetRequestCache();if($created)$server->exec('DROP DATABASE `'.$db.'`');}
    }
    private function schema():void
    {
        $this->pdo->exec("ALTER TABLE designation ADD active TINYINT DEFAULT 1,ADD approval_status VARCHAR(30) DEFAULT 'APPROVED';
            ALTER TABLE officer ADD approval_status VARCHAR(30) DEFAULT 'APPROVED',ADD effective_from DATE DEFAULT '2025-01-01',ADD effective_to DATE NULL,ADD initial_appointment_date DATE NULL,ADD created_by CHAR(36);
            ALTER TABLE officer_office_assignment ADD deleted_at DATETIME NULL;
            ALTER TABLE arpa_subject_assignment_closure ADD effective_to DATE;
            CREATE TABLE legacy_officer_reference(id CHAR(36),officer_id CHAR(36),source_system VARCHAR(80),source_table VARCHAR(80),legacy_payload_json JSON);
            ALTER TABLE arpa_division_appointment_request MODIFY record_origin VARCHAR(40) NOT NULL DEFAULT 'NATIVE',MODIFY requested_effective_from DATE NULL,MODIFY workflow_status VARCHAR(50) NOT NULL DEFAULT 'CREATED',ADD impact_snapshot_json JSON;
            ALTER TABLE arpa_division_appointment MODIFY record_origin VARCHAR(40) NOT NULL DEFAULT 'NATIVE';
            ALTER TABLE arpa_division_appointment_closure MODIFY record_origin VARCHAR(40) NOT NULL DEFAULT 'NATIVE',MODIFY approval_timestamp_provenance VARCHAR(80) NOT NULL DEFAULT 'NATIVE_RECORDED',ADD letter_date DATE;
            ALTER TABLE arpa_appointment_workflow_action MODIFY action_at DATETIME DEFAULT CURRENT_TIMESTAMP,MODIFY record_origin VARCHAR(40) DEFAULT 'NATIVE',MODIFY timestamp_provenance VARCHAR(80) DEFAULT 'NATIVE_RECORDED';
            CREATE TABLE arpa_appointment_stage_review_audit(id BIGINT,entity_type VARCHAR(30),request_id CHAR(36),review_stage VARCHAR(30),changed_by CHAR(36));
            ALTER TABLE audit_event MODIFY severity VARCHAR(30) NOT NULL DEFAULT 'INFO',ADD created_at DATETIME;
            ALTER TABLE system_notification ADD module_code VARCHAR(80),ADD title VARCHAR(180),ADD message VARCHAR(1000),ADD workflow_stage VARCHAR(80),ADD action_url VARCHAR(500),ADD action_label VARCHAR(80),ADD completed_at DATETIME,ADD resolved_by CHAR(36),ADD resolution_reason VARCHAR(500),ADD dedupe_key VARCHAR(255),ADD required_role_assignment_id CHAR(36),ADD required_scope_assignment_id CHAR(36),ADD created_by CHAR(36),ADD updated_at DATETIME;
            INSERT INTO location_type VALUES('operational-province-type','PROVINCE');
            INSERT INTO location(id,location_type_id,dad_number,name_en) VALUES('operational-province','operational-province-type','UAT-PROVINCE','Synthetic Province');
            INSERT INTO location_relationship VALUES(UUID(),'operational-province','19000000-0000-4000-8000-000000000001','PROVINCE_DISTRICT',1,'APPROVED','2025-01-01',NULL);
            INSERT INTO application_permission VALUES('operational-end-permission','arpa.appointment.end',1),('operational-create-permission','arpa.appointment.create',1);
            INSERT INTO application_role_permission VALUES('12000000-0000-4000-8000-000000000001','operational-end-permission'),('12000000-0000-4000-8000-000000000001','operational-create-permission');");
    }
    private function nativeConcurrentEnd(ArpaAppointmentService $service,Qualification $qualification,array $s,array $replacement):void
    {
        $this->pdo->prepare('DELETE FROM arpa_division_appointment WHERE id IN(?,?)')->execute([$s['appointment'],$replacement['appointment']]);
        $this->pdo->prepare("UPDATE arpa_division_appointment_request SET record_origin='NATIVE',legacy_exception=0,workflow_status='SUBMITTED',requested_effective_from='2025-01-01',created_by=? WHERE id=?")->execute([self::actor(1),$s['request']]);
        $this->context(1);$this->same(0,$this->current($s['officer'])['recordsFiltered'],'pre-ASC request is not operational');
        $this->step($s['request'],4,'VERIFY','ASC');$this->same(0,$this->current($s['officer'])['recordsFiltered'],'ASC verification alone is not operational');
        $this->step($s['request'],5,'APPROVE','ASC');
        $q=$this->pdo->prepare('SELECT * FROM arpa_division_appointment WHERE request_id=?');$q->execute([$s['request']]);$appointments=$q->fetchAll();$this->same(1,count($appointments),'ASC approval materializes exactly one canonical appointment');$a=$appointments[0];
        $this->context(1);$this->same(1,$this->current($s['officer'])['recordsFiltered'],'ASC-approved pending original is in Current Assignments');
        $this->same(Qualification::PENDING_APPROVAL,$qualification->qualify($a['id'],[self::ASC])['classification'],'strict National qualification is not weakened');
        $this->same('ASC_OPERATIONAL',$qualification->qualifyOperational($a['id'],[self::ASC])['approval_source'],'operational authority is explicitly distinguished');
        $export=iterator_to_array((new DataTableQuery($this->pdo,DataTableRegistry::definition('arpa-open-appointments'),new DataTableRequest(['filters'=>['officer'=>$s['officer']]])))->exportRows());
        $this->same(1,count($export),'Current CSV includes the same ASC-operational record');
        foreach([6=>'arpa-open-appointments-summary',8=>'arpa-open-appointments-district-summary'] as $actor=>$key){$this->context($actor);$summary=(new DataTableQuery($this->pdo,DataTableRegistry::definition($key),new DataTableRequest([])))->response();$this->same(1,array_sum(array_column($summary['data'],'total_count')),'operational geographic summary '.$actor);}
        $this->context(1);
        $yesterday=$this->pdo->query('SELECT DATE_SUB(CURRENT_DATE(),INTERVAL 1 DAY)')->fetchColumn();$today=$this->pdo->query('SELECT CURRENT_DATE()')->fetchColumn();
        $url='/hr/arpa-appointments/divisions/'.$a['id'].'/end';$input=['effective_to'=>$yesterday,'end_reason_id'=>self::REASON,'remarks'=>'Synthetic independent END'];
        foreach([2,6,11] as $actor){[$http]=$this->route('POST',$url,$input,$actor);$this->same($actor===2?302:403,$http,'existing END scope/permission response '.$actor);
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM arpa_division_appointment_request WHERE source_appointment_id=? AND request_type='END'");$q->execute([$a['id']]);$this->same(0,(int)$q->fetchColumn(),'unauthorized END writes nothing '.$actor);
        }
        [$http]=$this->route('POST',$url,$input,1,'missing-both');$this->same(419,$http,'END POST requires CSRF');
        [$http]=$this->route('POST',$url,$input,1);$this->same(302,$http,'authorized ASC submits END while original is pending');
        $q=$this->pdo->prepare("SELECT * FROM arpa_division_appointment_request WHERE source_appointment_id=? AND request_type='END'");$q->execute([$a['id']]);$ends=$q->fetchAll();
        $this->same(1,count($ends),'one selected END request created');$end=$ends[0]['id'];
        $this->same('ASC_APPROVED',$this->status($s['request']),'END does not advance original approval');
        $this->step($end,4,'VERIFY','ASC');$this->context(4,'ASC_ADMIN');$this->fails(fn()=>$service->workflow('division',$end,'APPROVE','ASC','Same reviewer',self::actor(4)),'END maker-checker remains enforced');
        $this->concurrentEndAndReview($end,$s['request']);$closure=$this->closure($a['id']);$this->same($yesterday,$closure['effective_to'],'END closure uses approved effective date');$this->same($end,$closure['request_id'],'closure links to independent END request');
        $this->same('ASC_APPROVED',$this->status($end),'END still awaits its own District approval');$this->context(1);$this->same(0,$this->current($s['officer'])['recordsFiltered'],'effective END removes old appointment from Current');
        $this->fails(fn()=>$service->createAndSubmitEndRequest($a['id'],$today,self::REASON,null,self::actor(1)),'duplicate END cannot recreate closure');
        $data=['officer_id'=>$replacement['officer'],'appointment_type'=>'PERMANENT','asc_location_id'=>self::ASC,'arpa_division_location_id'=>$s['division'],'effective_from'=>$today];
        $this->fails(fn()=>$service->createAndSubmitDivisionAppointmentRequest(array_replace($data,['effective_from'=>$yesterday]),self::actor(1)),'inclusive genuine overlap remains blocked');
        $next=$service->createAndSubmitDivisionAppointmentRequest($data,self::actor(1));$this->step($next,4,'VERIFY','ASC');$this->step($next,5,'APPROVE','ASC');
        $this->context(1);$this->same(1,$this->current($replacement['officer'])['recordsFiltered'],'replacement is operational after effective END and ASC approval');
        $canonical=$this->rows('arpa_division_appointment');$closures=$this->rows('arpa_division_appointment_closure');
        foreach([$s['request'],$end] as $request){foreach([[6,'VERIFY','DISTRICT'],[7,'APPROVE','DISTRICT'],[8,'VERIFY','NATIONAL'],[9,'APPROVE','NATIONAL']] as [$actor,$action,$stage]){if($request===$s['request']&&$stage==='DISTRICT'&&$action==='VERIFY')continue;$this->step($request,$actor,$action,$stage);}}
        $this->same($canonical,$this->rows('arpa_division_appointment'),'later original/END reviews never recreate or reopen appointments');$this->same($closures,$this->rows('arpa_division_appointment_closure'),'later reviews preserve closure and replacement');
        $this->same(Qualification::APPROVED,$qualification->qualify($a['id'],[self::ASC])['classification'],'National qualification still requires completed original evidence');
    }
    private function importedFutureEnd(ArpaAppointmentService $service,Qualification $qualification,array $s,array $replacement):void
    {
        $this->pdo->prepare("UPDATE arpa_division_appointment_request SET workflow_status='ASC_APPROVED',created_by=? WHERE id=?")->execute([self::actor(1),$s['request']]);$this->context(1);
        $missing=$qualification->qualifyOperational($s['appointment'],[self::ASC]);
        $this->same(Qualification::MANUAL_REVIEW,$missing['classification'],'imported status alone grants no operational authority');
        $this->same('MISSING_OR_AMBIGUOUS_ASC_APPROVAL',$missing['reason'],'missing imported ASC event is explicit');
        $this->same(0,$this->current($s['officer'])['recordsFiltered'],'status-only import is excluded from Current');
        $this->fails(fn()=>$service->createAndSubmitEndRequest($s['appointment'],date('Y-m-d'),self::REASON,null,self::actor(1)),'status-only imported END is blocked');
        // Match the actual importer: mapped approver, omitted verifier, and no invented historical timestamp.
        $insert=$this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,action_at,record_origin,previous_status,new_status,timestamp_provenance) VALUES(?,'APPROVE','ASC',?,NULL,'LEGACY_IMPORT','CREATED','ASC_APPROVED','UNAVAILABLE_FROM_LEGACY_SOURCE')");
        $insert->execute([$s['request'],self::actor(5)]);$eventId=$this->pdo->lastInsertId();
        $this->same(Qualification::APPROVED,$qualification->qualifyOperational($s['appointment'],[self::ASC])['classification'],'recorded imported ASC approval establishes operational authority');
        $insert->execute([$s['request'],self::actor(5)]);$duplicate=$this->pdo->lastInsertId();
        $this->same(Qualification::MANUAL_REVIEW,$qualification->qualifyOperational($s['appointment'],[self::ASC])['classification'],'ambiguous imported ASC approvals fail closed');
        $this->same(0,$this->current($s['officer'])['recordsFiltered'],'ambiguous imported evidence is not Current');
        $this->fails(fn()=>$service->createAndSubmitEndRequest($s['appointment'],date('Y-m-d'),self::REASON,null,self::actor(1)),'ambiguous imported END is blocked');
        $this->pdo->prepare('DELETE FROM arpa_appointment_workflow_action WHERE id=?')->execute([$duplicate]);
        foreach([['user_id'=>null],['timestamp_provenance'=>'NATIVE_RECORDED'],['timestamp_provenance'=>'UNKNOWN'],['previous_status'=>'DISTRICT_APPROVED'],['record_origin'=>'UNKNOWN'],['stage'=>'DISTRICT'],['action'=>'VERIFY']] as $invalid){
            foreach($invalid as $column=>$value)$this->pdo->prepare('UPDATE arpa_appointment_workflow_action SET '.$column.'=? WHERE id=?')->execute([$value,$eventId]);
            $this->same(Qualification::MANUAL_REVIEW,$qualification->qualifyOperational($s['appointment'],[self::ASC])['classification'],'invalid imported ASC evidence is rejected: '.array_key_first($invalid));
            $this->pdo->prepare("UPDATE arpa_appointment_workflow_action SET user_id=?,timestamp_provenance='UNAVAILABLE_FROM_LEGACY_SOURCE',previous_status='CREATED',record_origin='LEGACY_IMPORT',stage='ASC',action='APPROVE',action_at=NULL WHERE id=?")->execute([self::actor(5),$eventId]);
        }
        $this->pdo->prepare("UPDATE arpa_appointment_workflow_action SET previous_status='ASC_VERIFIED',timestamp_provenance='LEGACY_SOURCE_RECORDED',action_at='2023-09-08 12:00:00' WHERE id=?")->execute([$eventId]);
        $this->same(Qualification::APPROVED,$qualification->qualifyOperational($s['appointment'],[self::ASC])['classification'],'source-recorded imported ASC timestamp is accepted');
        $this->pdo->prepare("UPDATE arpa_appointment_workflow_action SET record_origin='NATIVE',timestamp_provenance='NATIVE_RECORDED' WHERE id=?")->execute([$eventId]);
        $this->same(Qualification::APPROVED,$qualification->qualifyOperational($s['appointment'],[self::ASC])['classification'],'genuine native-recorded approval of an imported request is accepted');
        $this->pdo->prepare('UPDATE arpa_appointment_workflow_action SET action_at=NULL WHERE id=?')->execute([$eventId]);
        $this->same(Qualification::MANUAL_REVIEW,$qualification->qualifyOperational($s['appointment'],[self::ASC])['classification'],'native-recorded imported approval cannot claim an unavailable timestamp');
        $this->pdo->prepare("UPDATE arpa_appointment_workflow_action SET record_origin='LEGACY_IMPORT',previous_status='CREATED',timestamp_provenance='UNAVAILABLE_FROM_LEGACY_SOURCE',action_at=NULL WHERE id=?")->execute([$eventId]);
        $q=$this->pdo->prepare('SELECT action_at,timestamp_provenance FROM arpa_appointment_workflow_action WHERE id=?');$q->execute([$eventId]);
        $this->same(['action_at'=>null,'timestamp_provenance'=>'UNAVAILABLE_FROM_LEGACY_SOURCE'],$q->fetch(),'qualification preserves unknown historical time rather than inventing one');
        $this->same(1,$this->current($s['officer'])['recordsFiltered'],'eligible imported ASC-approved canonical is operational');
        $this->same(Qualification::PENDING_APPROVAL,$qualification->qualify($s['appointment'],[self::ASC])['classification'],'imported final authorization remains pending');
        $this->context(2);$this->same(0,$this->current($s['officer'])['recordsFiltered'],'other ASC cannot see imported operational appointment');$this->context(1);
        $this->pdo->prepare('UPDATE arpa_division_appointment SET legacy_history_only=1 WHERE id=?')->execute([$s['appointment']]);
        $this->same(0,$this->current($s['officer'])['recordsFiltered'],'history-only imported record is excluded');
        $this->fails(fn()=>$service->createAndSubmitEndRequest($s['appointment'],date('Y-m-d'),self::REASON,null,self::actor(1)),'history-only source cannot be ended operationally');
        $this->pdo->prepare('UPDATE arpa_division_appointment SET legacy_history_only=0 WHERE id=?')->execute([$s['appointment']]);
        $this->pdo->prepare('UPDATE officer_office_assignment SET active=0 WHERE officer_id=?')->execute([$s['officer']]);
        $this->fails(fn()=>$service->createAndSubmitEndRequest($s['appointment'],date('Y-m-d'),self::REASON,null,self::actor(1)),'unresolved Appointment Data Issue blocks END');
        $this->pdo->prepare('UPDATE officer_office_assignment SET active=1 WHERE officer_id=?')->execute([$s['officer']]);
        $future=$this->pdo->query('SELECT DATE_ADD(CURRENT_DATE(),INTERVAL 7 DAY)')->fetchColumn();$end=$service->createAndSubmitEndRequest($s['appointment'],$future,self::REASON,'Scheduled closure',self::actor(1));
        $this->step($end,4,'VERIFY','ASC');$this->step($end,5,'APPROVE','ASC');$this->context(1);
        $current=$this->current($s['officer']);$this->same(1,$current['recordsFiltered'],'future closure does not hide an appointment before effective end');
        $this->same(false,str_contains($current['data'][0]['actions'],'/end'),'already scheduled closure has no duplicate END action');
        try{
            $this->pdo->exec('SET timestamp=UNIX_TIMESTAMP('.$this->pdo->quote($future.' 12:00:00').')');
            $this->same(1,$this->current($s['officer'])['recordsFiltered'],'inclusive effective end date remains current');
            $after=(new DateTimeImmutable($future))->modify('+1 day')->format('Y-m-d');$this->pdo->exec('SET timestamp=UNIX_TIMESTAMP('.$this->pdo->quote($after.' 12:00:00').')');
            $this->same(0,$this->current($s['officer'])['recordsFiltered'],'appointment leaves Current after future end becomes effective');
        }finally{$this->pdo->exec('SET timestamp=DEFAULT');$this->context(1);}
        $closure=$this->closure($s['appointment']);$canonical=$this->rows('arpa_division_appointment');
        foreach([[6,'VERIFY','DISTRICT'],[7,'APPROVE','DISTRICT'],[8,'VERIFY','NATIONAL'],[9,'APPROVE','NATIONAL']] as [$actor,$action,$stage])$this->step($s['request'],$actor,$action,$stage);
        $this->same($canonical,$this->rows('arpa_division_appointment'),'imported original approval never duplicates a canonical appointment');$this->same($closure,$this->closure($s['appointment']),'imported original approval preserves independent END closure');
    }
    private function concurrentEndAndReview(string $end,string $original):void
    {
        $jobs=[];
        foreach([[$end,5,'approve','ASC'],[$original,6,'verify','DISTRICT']] as [$id,$actor,$action,$stage]){
            $url='/hr/arpa-appointments/workflow/division/'.$id.'/'.$action.'?stage='.$stage;$pipes=[];
            $process=proc_open([PHP_BINARY,'-d','xdebug.mode=off',__DIR__.'/ArpaHistoricalApprovalUiIsolatedTest.php',$this->db,'--route','POST',$url,base64_encode(json_encode(['comments'=>'Concurrent operational review'])),(string)$actor,'','valid'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);
            if(!is_resource($process))throw new RuntimeException('Could not start concurrent workflow request.');$jobs[]=[$process,$pipes];
        }
        foreach($jobs as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$this->same(0,proc_close($process),'concurrent child exits: '.$error);$this->same(true,str_contains($out,'ROUTED_STATUS=302'),'concurrent routed action returns safely');}
        $this->same('ASC_APPROVED',$this->status($end),'concurrent END ASC approval completes');$this->same('DISTRICT_VERIFIED',$this->status($original),'concurrent original District verification completes');
    }
    private function step(string $id,int $actor,string $action,string $stage):void
    {
        [$http]=$this->route('POST','/hr/arpa-appointments/workflow/division/'.$id.'/'.strtolower($action).'?stage='.$stage,['comments'=>'Synthetic operational workflow review'],$actor);
        $this->same(302,$http,'routed '.$stage.' '.$action);$this->same(match([$stage,$action]){['ASC','VERIFY']=>'ASC_VERIFIED',['ASC','APPROVE']=>'ASC_APPROVED',['DISTRICT','VERIFY']=>'DISTRICT_VERIFIED',['DISTRICT','APPROVE']=>'DISTRICT_APPROVED',['NATIONAL','VERIFY']=>'NATIONAL_VERIFIED',default=>'NATIONAL_APPROVED'},$this->status($id),'state after '.$stage.' '.$action);
    }
    private function status(string $id):string{$q=$this->pdo->prepare('SELECT workflow_status FROM arpa_division_appointment_request WHERE id=?');$q->execute([$id]);return $q->fetchColumn();}
    private function closure(string $id):array{$q=$this->pdo->prepare('SELECT * FROM arpa_division_appointment_closure WHERE appointment_id=?');$q->execute([$id]);$rows=$q->fetchAll();$this->same(1,count($rows),'exactly one canonical closure');return $rows[0];}
    private function current(string $officer):array{return(new DataTableQuery($this->pdo,DataTableRegistry::definition('arpa-open-appointments'),new DataTableRequest(['filters'=>['officer'=>$officer]])))->response();}
    private function context(int $actor,?string $role=null):void{(new ReflectionMethod($this->fixture,'context'))->invoke($this->fixture,self::actor($actor),$role);}
    private static function actor(int $n):string{return '00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT);}
    private function route(string $method,string $path,array $post,int $actor,string $csrf='valid'):array{return(new ReflectionMethod($this->ui,'route'))->invoke($this->ui,$method,$path,$post,$actor,null,$csrf);}
    private function rows(string $table):array{return $this->pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();}
    private function fails(callable $work,string $label):void{try{$work();throw new RuntimeException($label.' unexpectedly succeeded');}catch(DomainException){$this->assertions++;}}
    private function same(mixed $expected,mixed $actual,string $label):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($label.'; expected '.var_export($expected,true).', got '.var_export($actual,true));}
}
try{(new ArpaOperationalEndWorkflowIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
