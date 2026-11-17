<?php
declare(strict_types=1);
define('ARPA_HISTORICAL_UI_FIXTURE_ONLY',true);
require __DIR__.'/ArpaHistoricalApprovalUiIsolatedTest.php';

use App\Core\{Auth,Database,DataTableQuery,DataTableRegistry,DataTableRequest,ScopeService};
use App\Services\{ArpaHistoricalAppointmentApprovalService,OfficerProfileService};

final class ApprovedAssignmentsFixtureStatement extends PDOStatement
{
    protected function __construct(private ApprovedAssignmentsFixturePdo $owner){}
    public function execute(?array $params=null):bool
    {
        foreach($params??[] as $v)if(is_string($v))$this->owner->maxParameterBytes=max($this->owner->maxParameterBytes,strlen($v));
        return parent::execute($params);
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array
    {
        $rows=parent::fetchAll($mode,...$args);
        if(str_contains($this->queryString,'_qualification_id')){
            $this->owner->maxCandidateRows=max($this->owner->maxCandidateRows,count($rows));$this->owner->candidateQueries++;
            if($rows!==[]&&$this->owner->rolloverTo!==null){$at=$this->owner->rolloverTo;$this->owner->rolloverTo=null;$this->owner->exec('SET timestamp=UNIX_TIMESTAMP('.$this->owner->quote($at).')');$this->owner->rollovers++;}
        }
        return $rows;
    }
}

final class ApprovedAssignmentsFixturePdo extends PDO
{
    public ?string $failSql=null;
    public int $maxParameterBytes=0;
    public int $maxCandidateRows=0;
    public int $candidateQueries=0;
    public ?int $failAfterCandidates=null;
    public ?string $rolloverTo=null;
    public int $rollovers=0;
    public int $reportingDateReads=0;
    public int $unfrozenCandidateQueries=0;
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false
    {
        if(str_contains($query,'CURRENT_DATE() reporting_date'))$this->reportingDateReads++;
        return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        if((str_contains($query,'_qualification_id')||str_contains($query,'FROM location summary_'))&&str_contains($query,'CURRENT_DATE()'))$this->unfrozenCandidateQueries++;
        if($this->failAfterCandidates!==null&&str_contains($query,'_qualification_id')&&$this->candidateQueries>=$this->failAfterCandidates){$this->failAfterCandidates=null;throw new RuntimeException('Synthetic late candidate failure.');}
        if($this->failSql!==null&&str_contains($query,$this->failSql)){$this->failSql=null;throw new RuntimeException('Synthetic qualification evidence failure.');}
        try{return parent::prepare($query,$options);}catch(PDOException $e){fwrite(STDERR,'Isolated SQL preparation failed: '.$e->getMessage()."\n");throw $e;}
    }
}

final class ArpaApprovedAssignmentsIsolatedTest
{
    private const ASC='10000000-0000-4000-8000-000000000001';
    private const OTHER_ASC='10000000-0000-4000-8000-000000000002';
    private const EXAMPLE='2a7636b3-bc86-11f1-b00c-02a68d69e1a8';
    private const EXAMPLE_REQUEST='062e727c-96fa-11f1-8055-fc5cee2ebe64';
    private PDO $pdo;
    private object $fixture;
    private array $sources=[];
    private int $assertions=0;

    public function run():void
    {
        $c=config('database');if($c['host']!=='127.0.0.1'||!str_starts_with($c['database'],'dems_test_'))throw new RuntimeException('Explicit disposable local configuration required.');
        $db='dems_test_approved_assignments_'.bin2hex(random_bytes(5));$created=false;
        $server=new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $property=new ReflectionProperty(Database::class,'pdo');$old=$property->getValue();$session=$_SESSION??[];
        try{
            $server->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
            $this->pdo=new ApprovedAssignmentsFixturePdo("mysql:host={$c['host']};port={$c['port']};dbname={$db};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
            $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ApprovedAssignmentsFixtureStatement::class,[$this->pdo]]);
            if($this->pdo->query('SELECT DATABASE()')->fetchColumn()!==$db)throw new RuntimeException('Wrong database before fixture writes.');$property->setValue(null,$this->pdo);
            $this->setup();$before=$this->snapshot();
            $this->listings();$this->profiles();$this->summaries();$this->scope();$this->unavailable();
            $this->same($before,$this->snapshot(),'all listing/profile/count/export operations leave records and events unchanged');
            $this->resourceFailures();$this->largePopulations();$this->midnightRollovers();
            echo 'ArpaApprovedAssignmentsIsolatedTest: '.$this->assertions.' assertions passed; MySQL '.$server->getAttribute(PDO::ATTR_SERVER_VERSION)."; disposable database removed.\n";
        }finally{$property->setValue(null,$old);$_SESSION=$session;Auth::forgetRequestCache();if($created)$server->exec('DROP DATABASE `'.$db.'`');}
    }
    private function setup():void
    {
        $base=new ArpaAppointmentCorrectionAmendmentDraftTest();(new ReflectionProperty($base,'pdo'))->setValue($base,$this->pdo);
        $this->call($base,'createFixtureSchema');$this->pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/062_arpa_appointment_correction_amendment_foundation.sql'));$this->call($base,'insertFixtures');$this->call($base,'exerciseHistoricalPeerDecisions');
        $this->pdo->exec("INSERT INTO designation VALUES('historical-test-arpa','ARPA_OFFICER');UPDATE arpa_division_appointment SET legacy_history_only=1");
        $this->fixture=new ArpaHistoricalAppointmentApprovalIsolatedTest();(new ReflectionProperty($this->fixture,'pdo'))->setValue($this->fixture,$this->pdo);
        foreach(['hema','native_pending','native_approved','import_approved','historical','stale','rejected','ambiguous','missing','ended','future','history_only','foreign'] as $name)$this->sources[$name]=$this->call($this->fixture,'source');
        for($i=0;$i<20;$i++)$this->sources['bulk'.$i]=$this->call($this->fixture,'source');
        $ui=new ArpaHistoricalApprovalUiIsolatedTest();(new ReflectionProperty($ui,'pdo'))->setValue($ui,$this->pdo);$this->call($ui,'schema');
        foreach($this->sources as $name=>$s){
            $this->pdo->prepare('UPDATE officer SET dad_number=?,name_with_initials=? WHERE id=?')->execute(['UAT-APPR-'.$name,'Synthetic '.$name,$s['officer']]);
            $this->pdo->prepare("UPDATE arpa_division_appointment SET district_location_id_snapshot='19000000-0000-4000-8000-000000000001',district_name_snapshot='District One',province_name_snapshot='Province One' WHERE id=?")->execute([$s['appointment']]);
        }
        foreach(['native_pending','native_approved','missing','ended','future','foreign',...array_map(fn($i)=>'bulk'.$i,range(0,19))] as $name){$s=$this->sources[$name];foreach(['arpa_division_appointment'=>$s['appointment'],'arpa_division_appointment_request'=>$s['request']] as $table=>$id)$this->pdo->prepare("UPDATE {$table} SET record_origin='NATIVE' WHERE id=?")->execute([$id]);}
        $this->pdo->prepare("UPDATE arpa_division_appointment_request SET workflow_status='ASC_APPROVED' WHERE id=?")->execute([$this->sources['native_pending']['request']]);
        foreach(['native_approved','import_approved','ended','future','foreign',...array_map(fn($i)=>'bulk'.$i,range(0,19))] as $name)$this->national($this->sources[$name]);
        $this->pdo->prepare("UPDATE arpa_division_appointment_request SET workflow_status='NATIONAL_APPROVED' WHERE id=?")->execute([$this->sources['missing']['request']]);
        $future=$this->sources['future'];$this->pdo->prepare("UPDATE arpa_division_appointment SET effective_from='2099-01-01' WHERE id=?")->execute([$future['appointment']]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,effective_to,context_snapshot_json,approval_timestamp_provenance) VALUES(UUID(),'NATIVE',?,'2020-12-31','{}','NATIVE_RECORDED')")->execute([$this->sources['ended']['appointment']]);
        $this->pdo->prepare('UPDATE arpa_division_appointment SET legacy_history_only=1 WHERE id=?')->execute([$this->sources['history_only']['appointment']]);
        $foreign=$this->sources['foreign'];foreach(['arpa_division_appointment'=>$foreign['appointment'],'arpa_division_appointment_request'=>$foreign['request']] as $table=>$id)$this->pdo->prepare("UPDATE {$table} SET asc_location_id=? WHERE id=?")->execute([self::OTHER_ASC,$id]);
        $service=new ArpaHistoricalAppointmentApprovalService($this->pdo);(new ReflectionProperty($this->fixture,'service'))->setValue($this->fixture,$service);
        foreach(['historical','stale','rejected','ambiguous'] as $name){
            $s=$this->sources[$name];$this->context(1);$c=$service->register($s['correction'],['reason'=>'Synthetic acceptance evidence','evidence_reference'=>'Synthetic appointment letter'],$this->actor(1));$c=$service->submit($c['case']['id'],0,$this->actor(1));
            if($name==='rejected'){$c=$this->call($this->fixture,'advance',$c,4,'VERIFY');$this->call($this->fixture,'advance',$c,5,'REJECT');}
            else $this->call($this->fixture,'complete',$c);
        }
        $this->pdo->prepare('UPDATE arpa_division_appointment_request SET version=version+1 WHERE id=?')->execute([$this->sources['stale']['request']]);
        $this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin) SELECT UUID(),CONCAT(issue_row_key,':ambiguous'),issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin FROM arpa_appointment_data_correction WHERE id=?")->execute([$this->sources['ambiguous']['correction']]);
        $s=$this->sources['hema'];$this->pdo->prepare("UPDATE officer SET dad_number='70045-0005843',name_with_initials='D M HEMALATHA' WHERE id=?")->execute([$s['officer']]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status) SELECT ?,'LEGACY_IMPORT','APPOINTMENT',officer_id,appointment_type,asc_location_id,arpa_division_location_id,'2024-02-26','CREATED' FROM arpa_division_appointment_request WHERE id=?")->execute([self::EXAMPLE_REQUEST,$s['request']]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,asc_location_id,arpa_division_location_id,asc_name_snapshot,arpa_name_snapshot,effective_from,legacy_history_only) SELECT ?,'LEGACY_IMPORT',?,officer_id,appointment_type,asc_location_id,arpa_division_location_id,asc_name_snapshot,arpa_name_snapshot,'2024-02-26',0 FROM arpa_division_appointment WHERE id=?")->execute([self::EXAMPLE,self::EXAMPLE_REQUEST,$s['appointment']]);
        $this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin) SELECT UUID(),?,'LEGACY_HISTORICAL_EXCEPTION',officer_id,?,?,asc_location_id,corrected_by,correction_action,resolution_status,'Synthetic example fixture','{}','{}','LEGACY_IMPORT' FROM arpa_appointment_data_correction WHERE id=?")->execute(['LEGACY_HISTORICAL_EXCEPTION:'.self::EXAMPLE_REQUEST,self::EXAMPLE,self::EXAMPLE_REQUEST,$s['correction']]);
        $this->context(1);
    }
    private function listings():void
    {
        $d=$this->table();$all=$d->response();$export=iterator_to_array($d->exportRows());
        $this->same(24,$all['recordsTotal'],'only approved open in-scope appointments counted');$this->same(24,count($export),'unpaginated CSV uses identical qualification');
        $names=array_column($export,'officer_name');
        foreach(['native_pending','stale','rejected','ambiguous','missing','history_only','foreign','ended'] as $name)$this->same(false,in_array('Synthetic '.$name,$names,true),$name.' excluded from approved Current Assignments');
        foreach(['native_approved','import_approved','historical','future'] as $name)$this->same(true,in_array('Synthetic '.$name,$names,true),$name.' preserves existing open-period semantics');
        $this->same(false,str_contains(json_encode($all),'HEMALATHA'),'CREATED example is not an approved Current Assignment');
        $filtered=$this->table(['search'=>['value'=>'bulk'],'length'=>10,'start'=>10,'order'=>[['column'=>1,'dir'=>'desc']]])->response();
        $this->same(24,$filtered['recordsTotal'],'search leaves total approved count intact');$this->same(20,$filtered['recordsFiltered'],'search counts before pagination');$this->same(10,count($filtered['data']),'second page full after qualification');
        $expected=array_map(fn($i)=>'Synthetic bulk'.$i,range(0,19));rsort($expected,SORT_STRING);
        $this->same(array_slice($expected,10,10),array_column($filtered['data'],'officer_name'),'SQL ordering and OFFSET follow qualification');
        $csv=$this->table(['search'=>['value'=>'bulk'],'length'=>10,'start'=>10,'order'=>[['column'=>1,'dir'=>'desc']]]);
        $this->same($expected,array_column(iterator_to_array($csv->exportRows()),'officer_name'),'CSV ignores paging but retains search/sort');
        $s=$this->sources['historical'];$officer=$this->table(['filters'=>['officer'=>$s['officer']]])->response();$this->same(1,$officer['recordsFiltered'],'officer filter uses qualified set');$this->same('Created',strip_tags($officer['data'][0]['workflow_status']),'completed Historical Approval does not rewrite original workflow');
        $this->same(0,$this->table(['filters'=>['asc'=>self::OTHER_ASC]])->response()['recordsFiltered'],'forged filter cannot broaden ASC scope');
        $this->same(24,$this->table([],['asc_id'=>self::ASC])->response()['recordsTotal'],'ASC route bind order is preserved');
        $this->same(0,$this->table([],['asc_id'=>self::OTHER_ASC])->response()['recordsTotal'],'forged route context cannot broaden scope');
        $s=$this->sources['hema'];
        try{(new App\Services\ArpaAppointmentReadService($this->pdo))->assertDivisionPeriodDoesNotOverlap($s['division'],'2024-02-26');throw new RuntimeException('Pending canonical occupancy was lost.');}catch(DomainException){$this->same(true,true,'unapproved canonical appointment still blocks real overlaps');}
        $vacancy=$this->pdo->prepare('SELECT COUNT(*) FROM '.App\Services\ArpaAppointmentReadService::vacantDivisionSource().' v WHERE v.id=?');$vacancy->execute([$s['division']]);$this->same(0,(int)$vacancy->fetchColumn(),'hiding unapproved Current rows does not create vacancy');
        $this->same(false,$this->pdo->inTransaction(),'listing/export snapshot closed');
        $env=getenv();$env['DB_DATABASE']=$this->pdo->query('SELECT DATABASE()')->fetchColumn();$pipes=[];
        $process=proc_open([PHP_BINARY,'-d','xdebug.mode=off',__FILE__,'--csv',base64_encode(json_encode(['search'=>['value'=>'bulk'],'length'=>10,'start'=>10])), '1'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH,$env);
        if(!is_resource($process))throw new RuntimeException('Unable to run isolated CSV controller.');$body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        $this->same(0,$exit,'actual CSV controller exits successfully: '.$error);$this->same(true,str_starts_with($body,"\xEF\xBB\xBF"),'CSV retains UTF-8 BOM');
        $stream=fopen('php://temp','w+');fwrite($stream,substr($body,3));rewind($stream);$headers=fgetcsv($stream,0,',','"','');$csvRows=[];while(($row=fgetcsv($stream,0,',','"',''))!==false)$csvRows[]=$row;fclose($stream);
        $this->same(true,in_array('Original Workflow Status',$headers,true),'CSV keeps original status explicitly labelled');$this->same(20,count($csvRows),'actual CSV controller exports full authorized filtered result, not one page');
        $process=proc_open([PHP_BINARY,'-d','xdebug.mode=off',__FILE__,'--csv',base64_encode(json_encode(['_simulate_export_failure'=>true])),'1'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH,$env);
        if(!is_resource($process))throw new RuntimeException('Unable to run isolated failed CSV controller.');$body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        $this->same(false,str_starts_with($body,"\xEF\xBB\xBF"),'failed CSV preflight publishes no CSV body');$this->same(true,str_contains($error,'FIXTURE_HTTP_STATUS=500'),'failed CSV returns controlled HTTP 500');
        foreach(['SQLSTATE','Stack trace',BASE_PATH] as $private)$this->same(false,str_contains($body,$private),'failed CSV hides implementation detail');
        $process=proc_open([PHP_BINARY,'-d','xdebug.mode=off',__FILE__,'--csv',base64_encode(json_encode(['search'=>['value'=>'NO_MATCH_SYNTHETIC_EXPORT']])),'1'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH,$env);
        if(!is_resource($process))throw new RuntimeException('Unable to run empty CSV controller.');$body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        $this->same(0,$exit,'empty qualified CSV completes successfully');$this->same('',trim($error),'empty CSV does not traverse a closed generator');$this->same(true,str_starts_with($body,"\xEF\xBB\xBF"),'empty CSV retains headers/BOM');
        $this->table(['length'=>10])->response();$one=$this->questions();$this->table(['length'=>10])->response();$ten=$this->questions()-$one;
        $one=$this->questions();$this->table(['length'=>25])->response();$twentyFive=$this->questions()-$one;
        $this->same($ten,$twentyFive,'query count does not grow with returned appointment rows');
    }
    private function profiles():void
    {
        foreach(['hema'=>'PENDING_APPROVAL','native_pending'=>'PENDING_APPROVAL','native_approved'=>'CURRENT','import_approved'=>'CURRENT','historical'=>'CURRENT','stale'=>'MANUAL_REVIEW','rejected'=>'MANUAL_REVIEW','ambiguous'=>'MANUAL_REVIEW','missing'=>'MANUAL_REVIEW','ended'=>'PREVIOUS','future'=>'FUTURE','history_only'=>'HISTORICAL_ONLY'] as $name=>$period){
            $rows=$this->profile($this->sources[$name]['officer']);foreach($rows as $row){$this->same($period,$row['profile_period'],$name.' appears in correct profile section');$this->same(true,array_key_exists('approval_source',$row),'approval source available to presentation');}
        }
        $hema=$this->profile($this->sources['hema']['officer']);$matches=array_values(array_filter($hema,fn($row)=>$row['id']===self::EXAMPLE));
        $this->same(1,count($matches),'verified example identity retained');$this->same('CREATED',$matches[0]['workflow_status'],'example original status unchanged');$this->same('2024-02-26',$matches[0]['effective_from'],'example date unchanged');$this->same(null,$matches[0]['effective_to'],'no fake closure');
        $rows=$this->profile($this->sources['historical']['officer']);$this->same('HISTORICAL_APPROVAL',$rows[0]['approval_source'],'historical evidence source displayed');
        $line='';foreach(file(BASE_PATH.'/app/Views/officers/show.php') as $l)if(str_starts_with($l,'<?php $renderOperational='))$line=$l;
        $dash='—';$canAdminDateCorrect=false;eval('use App\\Core\\DataTableFormat; '.substr(trim($line),5,-2));
        ob_start();$renderOperational('Awaiting Appointment Approval',$hema,[]);$html=ob_get_clean();
        foreach(['Awaiting Appointment Approval','Approval Qualification / Source','Original Workflow Status','Created','Imported Record','2024'] as $text)$this->same(true,str_contains($html,$text),'profile renders '.$text);
        $this->same(true,str_contains($html,'Not an approved Current Assignment'),'pending period cannot imply completed approval');
        $this->same(true,str_contains($html,'Recorded Effective Period: Open'),'pending date-open evidence is labelled explicitly');
        $this->same(false,str_contains($html,'>Current / Open</span>'),'pending open period has no Current badge');
        ob_start();$renderOperational('Current ARPA Assignments',$rows,[]);$html=ob_get_clean();$this->same(true,str_contains($html,'Historical Approval'),'completed proof source rendered');
        $this->same(true,str_contains($html,'>Current / Open</span>'),'approved current appointment retains Current/Open badge');
        ob_start();$renderOperational('Appointment Records Requiring Review',$this->profile($this->sources['stale']['officer']),[]);$reviewHtml=ob_get_clean();
        $this->same(true,str_contains($reviewHtml,'Not an approved Current Assignment'),'manual review cannot imply current approval');$this->same(false,str_contains($reviewHtml,'>Current / Open</span>'),'manual review date-open period has no Current badge');
        $current_appointments=$future_appointments=$previous_appointments=$current_subjects=$future_subjects=$previous_subjects=$review_appointments=$historical_only_appointments=[];
        $awaiting_appointments=$hema;$appointment_workflow=$subject_workflow=[];
        $lines=file(BASE_PATH.'/app/Views/officers/show.php');$sections='';foreach($lines as $l)if(str_starts_with($l,"<?php \$renderOperational('Current ARPA Assignments'"))$sections=$l;
        ob_start();eval('?>'.$sections);$html=ob_get_clean();$pendingPosition=strpos($html,'Awaiting Appointment Approval');
        $this->same(false,str_contains(substr($html,0,$pendingPosition),'2024'),'example date is absent from approved Current section');
        $this->same(true,str_contains(substr($html,$pendingPosition),'2024'),'example appears in Awaiting section');
        $this->same([],$this->profile($this->sources['hema']['officer'],[]),'empty profile ASC scope remains closed');
    }
    private function summaries():void
    {
        $this->context(6);$d=DataTableRegistry::definition('arpa-open-appointments-summary');$this->same(true,($d['authorize'])(),'District summary authorization preserved');
        $r=(new DataTableQuery($this->pdo,$d,new DataTableRequest([])))->response();$sum=array_sum(array_map(fn($row)=>(int)$row['total_count'],$r['data']));$this->same(24,$sum,'District summary counts approved appointments across its ASCs only');
        $this->context(8);$d=DataTableRegistry::definition('arpa-open-appointments-district-summary');$r=(new DataTableQuery($this->pdo,$d,new DataTableRequest([])))->response();$this->same(25,array_sum(array_map(fn($row)=>(int)$row['total_count'],$r['data'])),'National summary agrees with authorized detail');$this->context(1);
    }
    private function scope():void
    {
        $this->context(2);$this->same(1,$this->table()->response()['recordsTotal'],'other ASC sees only its own approved record');$this->same([],$this->profile($this->sources['hema']['officer'],[self::OTHER_ASC]),'profile cross-ASC denial unchanged');
        foreach([1,5] as $actor){$this->context($actor);$this->same(24,$this->table()->response()['recordsTotal'],'ASC Subject/Admin approved visibility');}
        $this->context(4);unset($_SESSION['access_context']);Auth::forgetRequestCache();try{$this->table()->response();throw new RuntimeException('Missing context accepted.');}catch(DomainException $e){$this->same(403,$e->getCode(),'adapter direct call requires selected context when several contexts exist');}$this->context(1);
        $grant=$this->pdo->query("SELECT rp.role_id,rp.permission_id FROM application_role_permission rp JOIN user_account_role ur ON ur.role_id=rp.role_id JOIN application_permission p ON p.id=rp.permission_id WHERE ur.user_id='".$this->actor(1)."' AND p.permission_key='arpa.appointment.view' LIMIT 1")->fetch();
        $this->pdo->prepare('DELETE FROM application_role_permission WHERE role_id=? AND permission_id=?')->execute(array_values($grant));Auth::forgetRequestCache();
        try{try{$this->table()->response();throw new RuntimeException('Missing permission accepted.');}catch(DomainException $e){$this->same(403,$e->getCode(),'direct adapted read cannot bypass appointment.view');}}finally{$this->pdo->prepare('INSERT INTO application_role_permission(role_id,permission_id) VALUES(?,?)')->execute(array_values($grant));Auth::forgetRequestCache();}
    }
    private function unavailable():void
    {
        $this->pdo->exec('RENAME TABLE arpa_appointment_historical_approval_event TO hidden_hist_event');
        try{$r=$this->table()->response();$this->same(23,$r['recordsTotal'],'unavailable historical evidence excludes historical approval, retains valid originals');$rows=$this->profile($this->sources['historical']['officer']);$this->same('MANUAL_REVIEW',$rows[0]['profile_period'],'profile unavailable evidence fails closed');}finally{$this->pdo->exec('RENAME TABLE hidden_hist_event TO arpa_appointment_historical_approval_event');}
        $this->pdo->failSql='FROM arpa_division_appointment_request WHERE id IN';try{$this->table()->response();throw new RuntimeException('Unavailable evidence accepted.');}catch(DomainException $e){$this->same(503,$e->getCode(),'unavailable evidence aborts the read instead of returning a partial total');}
        $this->pdo->failSql='FROM arpa_division_appointment_request WHERE id IN';$this->same('MANUAL_REVIEW',$this->profile($this->sources['native_approved']['officer'])[0]['profile_period'],'profile classifies unavailable evidence as review, never Previous/Current');
        $plain=['from'=>'officer','select'=>['id','name_with_initials'],'count'=>'id','baseWhere'=>['id=?'],'baseParams'=>[$this->sources['hema']['officer']],'columns'=>[['label'=>'Name','key'=>'name_with_initials','sort'=>'name_with_initials']]];
        $this->same(1,(new DataTableQuery($this->pdo,$plain,new DataTableRequest([])))->response()['recordsTotal'],'unadapted DataTables retain their existing behavior');
        $this->same(1,count(iterator_to_array((new DataTableQuery($this->pdo,$plain,new DataTableRequest([])))->exportRows())),'unadapted exports retain their existing behavior');
    }
    private function resourceFailures():void
    {
        $before=count(get_resources('stream'));$settings=$this->pdo->query('SELECT @@session.max_execution_time')->fetchColumn();
        $broken=DataTableRegistry::definition('arpa-open-appointments');unset($broken['readAdapter']);
        try{(new DataTableQuery($this->pdo,$broken,new DataTableRequest([])))->response();throw new DomainException('Unadapted protected definition accepted.');}catch(RuntimeException $e){$this->same(true,str_contains($e->getMessage(),'adapter is required'),'missing adapter cannot fall through to unapproved SQL');}
        $this->pdo->failSql='_qualification_id';$export=$this->table()->exportRows();
        try{$export->rewind();throw new RuntimeException('Expected export failure.');}catch(DomainException $e){$this->same(503,$e->getCode(),'candidate failure aborts export before any rows escape');}
        unset($export);gc_collect_cycles();$this->same($before,count(get_resources('stream')),'failed export closes temporary stream');$this->same(false,$this->pdo->inTransaction(),'failed export rolls back snapshot');
        $this->same($settings,$this->pdo->query('SELECT @@session.max_execution_time')->fetchColumn(),'failed export restores SELECT timeout');
        $export=$this->table()->exportRows();$export->rewind();$this->same($before+1,count(get_resources('stream')),'prepared export owns only one temporary stream');
        $this->same(false,$this->pdo->inTransaction(),'prepared export releases snapshot before client streaming');unset($export);gc_collect_cycles();$this->same($before,count(get_resources('stream')),'abandoned download closes temporary stream');
        $this->same($settings,$this->pdo->query('SELECT @@session.max_execution_time')->fetchColumn(),'successful export restores SELECT timeout');
        $config=DataTableRegistry::definition('arpa-open-appointments');$config['exportSpoolMaxBytes']=32;$export=(new DataTableQuery($this->pdo,$config,new DataTableRequest([])))->exportRows();
        try{$export->rewind();throw new RuntimeException('Expected spool budget rejection.');}catch(DomainException $e){$this->same(503,$e->getCode(),'storage budget fails closed, never truncates a successful export');}
        unset($export);gc_collect_cycles();$this->same($before,count(get_resources('stream')),'storage budget failure removes temporary stream');
        try{(new ReflectionMethod(App\Services\ArpaApprovedAssignmentReadAdapter::class,'checkDeadline'))->invoke(null,hrtime(true)-1);throw new RuntimeException('Expected deadline rejection.');}catch(DomainException $e){$this->same(503,$e->getCode(),'expired snapshot budget fails closed');}
    }

    private function largePopulations():void
    {
        $this->context(1);$template=$this->sources['native_approved'];$officers=[];
        foreach(['Scale A','Scale a','Scale Á','Scale A ','Scale සිංහල','Scale தமிழ்','Scale '.str_repeat('Long Name ',20)] as $i=>$name){$id=$this->newId();$this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,arpa_service_permanency) VALUES(?,?,?,'PERMANENT_IN_SERVICE')")->execute([$id,'UAT-SCALE-'.$i,$name]);$officers[]=$id;}
        $input="JSON_TABLE(?, '$[*]' COLUMNS(id CHAR(36) PATH '$.id',request_id CHAR(36) PATH '$.request',officer_id CHAR(36) PATH '$.officer',ready INT PATH '$.ready',approved_at DATETIME PATH '$.approved_at' NULL ON EMPTY)) j";
        $request=$this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,finalized_by,finalized_at,request_remarks) SELECT j.request_id,'NATIVE','APPOINTMENT',j.officer_id,a.appointment_type,a.asc_location_id,a.arpa_division_location_id,a.effective_from,IF(j.ready=1,'NATIONAL_APPROVED','ASC_APPROVED'),?,'2026-11-01 00:00:00','SYNTHETIC_SCALE' FROM {$input} JOIN arpa_division_appointment a ON a.id=?");
        $appointment=$this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,asc_location_id,arpa_division_location_id,asc_name_snapshot,arpa_name_snapshot,district_location_id_snapshot,district_name_snapshot,effective_from,approved_at) SELECT j.id,'NATIVE',j.request_id,j.officer_id,a.appointment_type,a.asc_location_id,a.arpa_division_location_id,a.asc_name_snapshot,a.arpa_name_snapshot,a.district_location_id_snapshot,a.district_name_snapshot,a.effective_from,j.approved_at FROM {$input} JOIN arpa_division_appointment a ON a.id=?");
        $event=$this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,action_at,record_origin,previous_status,new_status,timestamp_provenance) SELECT j.request_id,'APPROVE','NATIONAL',?,'2026-11-01 00:00:00','NATIVE','NATIONAL_VERIFIED','NATIONAL_APPROVED','NATIVE_RECORDED' FROM {$input} WHERE j.ready=1");
        $added=0;$approved=0;
        foreach([1200,6000] as $size){
            while($added<$size){$batch=[];
                for($i=0;$i<100&&$added<$size;$i++,$added++){$ready=$added%3!==0;$batch[]=['id'=>$this->newId(),'request'=>$this->newId(),'officer'=>$officers[$added%count($officers)],'ready'=>$ready?1:0,'approved_at'=>$added%7===0?'2026-11-01 00:00:00':null];if($ready)$approved++;}
                $json=json_encode($batch,JSON_THROW_ON_ERROR);$request->execute([$this->actor(9),$json,$template['appointment']]);$appointment->execute([$json,$template['appointment']]);$event->execute([$this->actor(9),$json]);
            }
            $digest=$this->operationalDigest();$this->pdo->maxParameterBytes=$this->pdo->maxCandidateRows=$this->pdo->candidateQueries=0;
            gc_collect_cycles();if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();$baseMemory=memory_get_usage(false);$started=hrtime(true);$questions=$this->questions();
            $input=['length'=>100,'start'=>250,'order'=>[['column'=>1,'dir'=>'asc']],'search'=>['value'=>'Scale']];$result=$this->table($input)->response();
            $elapsed=(hrtime(true)-$started)/1e9;$extra=max(0,memory_get_peak_usage(false)-$baseMemory);$queries=$this->questions()-$questions;
            $this->same(24+$approved,$result['recordsTotal'],'large mixed population total');$this->same($approved,$result['recordsFiltered'],'large filtered total before pagination');$this->same(100,count($result['data']),'large mixed page stays full');
            $this->same(true,$extra<16*1024*1024,'bounded additional PHP memory, not an appointment ID map');$this->same(true,$this->pdo->maxCandidateRows<=200,'all SQL candidate buffers bounded to 200');
            $this->same(true,$this->pdo->maxParameterBytes<16384,'detail uses no large JSON/packet parameter');$this->same(true,$queries<$size,'set-based queries, not one query per appointment');
            $oracle="SELECT a.id,o.name_with_initials FROM arpa_division_appointment a JOIN arpa_division_appointment_request r ON r.id=a.request_id JOIN officer o ON o.id=a.officer_id WHERE r.request_remarks='SYNTHETIC_SCALE' AND r.workflow_status='NATIONAL_APPROVED'";
            $expected=$this->pdo->query($oracle.' ORDER BY o.name_with_initials ASC,a.id ASC LIMIT 100 OFFSET 250')->fetchAll();
            $this->same(array_map('trim',array_column($expected,'name_with_initials')),array_map('html_entity_decode',array_column($result['data'],'officer_name')),'MySQL locale/case/accent/trailing-space ordering across candidate boundaries');
            foreach($result['data'] as $i=>$row)$this->same(true,str_contains($row['actions'],$expected[$i]['id']),'stable ID tie-break on the actual page');
            $this->same($digest,$this->operationalDigest(),'large reads leave all operational evidence unchanged');
            echo sprintf("Scale %d (%d approved/%d pending): %.3fs, %.2f MiB extra peak PHP, %d statements, max %d candidates/%d-byte parameter.\n",$size,$approved,$size-$approved,$elapsed,$extra/1048576,$queries,$this->pdo->maxCandidateRows,$this->pdo->maxParameterBytes);
        }
        $allApproved=DataTableRegistry::definition('arpa-open-appointments');$allApproved['baseWhere'][]="req.request_remarks='SYNTHETIC_SCALE' AND req.workflow_status='NATIONAL_APPROVED'";
        $r=(new DataTableQuery($this->pdo,$allApproved,new DataTableRequest(['length'=>100,'start'=>1000,'order'=>[['column'=>1,'dir'=>'desc']]])))->response();
        $this->same($approved,$r['recordsTotal'],'large approved-only population uses the same strict policy');$this->same($approved,$r['recordsFiltered'],'approved-only filtered count');$this->same(100,count($r['data']),'approved-only pagination remains complete');
        foreach(['asc','desc'] as $direction){$input=['length'=>25,'start'=>425,'order'=>[['column'=>12,'dir'=>$direction]],'search'=>['value'=>'Scale']];$r=$this->table($input)->response();
            $oracle=$this->pdo->query("SELECT a.id FROM arpa_division_appointment a JOIN arpa_division_appointment_request r ON r.id=a.request_id WHERE r.request_remarks='SYNTHETIC_SCALE' AND r.workflow_status='NATIONAL_APPROVED' ORDER BY a.approved_at ".strtoupper($direction).',a.id ASC LIMIT 25 OFFSET 425')->fetchAll(PDO::FETCH_COLUMN);
            foreach($r['data'] as $i=>$row)$this->same(true,str_contains($row['actions'],$oracle[$i]),'NULL/date '.$direction.' ordering matches MySQL across batches');}
        $count=0;$started=hrtime(true);foreach($this->table(['search'=>['value'=>'Scale'],'length'=>10,'start'=>425])->exportRows() as $row){$this->same(true,str_starts_with($row['officer_name'],'Scale'),'large CSV contains only filtered authorized rows');$count++;}
        $this->same($approved,$count,'large CSV is complete, not a requested page');echo sprintf("Large CSV: %d rows, %.3fs.\n",$count,(hrtime(true)-$started)/1e9);
        $this->same($approved,$this->table(['search'=>['value'=>'Scale'],'filters'=>['district'=>'19000000-0000-4000-8000-000000000001']])->response()['recordsFiltered'],'large geographic filtering agrees with qualified search');
        $streams=count(get_resources('stream'));$this->pdo->candidateQueries=0;$this->pdo->failAfterCandidates=2;$failed=$this->table(['search'=>['value'=>'Scale']])->exportRows();
        try{$failed->rewind();throw new RuntimeException('Expected late export failure.');}catch(DomainException $e){$this->same(503,$e->getCode(),'late export failure does not publish a prepared partial file');}
        unset($failed);gc_collect_cycles();$this->same($streams,count(get_resources('stream')),'late export failure removes temporary stream');$this->same(false,$this->pdo->inTransaction(),'late export failure releases snapshot');
        $this->context(6);$d=DataTableRegistry::definition('arpa-open-appointments-summary');$r=(new DataTableQuery($this->pdo,$d,new DataTableRequest([])))->response();$this->same(24+$approved,array_sum(array_column($r['data'],'total_count')),'large District summary remains consistent');
        $this->context(8);$d=DataTableRegistry::definition('arpa-open-appointments-district-summary');$r=(new DataTableQuery($this->pdo,$d,new DataTableRequest([])))->response();$this->same(25+$approved,array_sum(array_column($r['data'],'total_count')),'large National summary remains consistent');
        $this->context(10);$d=DataTableRegistry::definition('arpa-open-appointments-summary');$r=(new DataTableQuery($this->pdo,$d,new DataTableRequest([])))->response();$this->same(1,array_sum(array_column($r['data'],'total_count')),'wrong District cannot see large other-District population');
        $this->context(2);$this->same(1,$this->table()->response()['recordsTotal'],'other ASC cannot see large population');
        $this->same(false,$this->pdo->inTransaction(),'large scopes release snapshot');$this->context(1);
    }
    private function midnightRollovers():void
    {
        $template=$this->sources['native_approved'];$zone=$this->pdo->query('SELECT @@session.time_zone')->fetchColumn();
        try{foreach([['2026-11-17','2026-11-18'],['2026-12-31','2027-01-01']] as [$day,$next]){
            $tag='SYNTHETIC_MIDNIGHT_'.$day;$officer=$this->newId();
            $this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,arpa_service_permanency) VALUES(?,?,?,'PERMANENT_IN_SERVICE')")->execute([$officer,'UAT-MIDNIGHT-'.$day,'Midnight '.$day]);
            $batch=[];for($i=0;$i<300;$i++)$batch[]=['id'=>$this->newId(),'request'=>$this->newId(),'start'=>$i<250?$next:$day];
            $json=json_encode($batch,JSON_THROW_ON_ERROR);
            $input="JSON_TABLE(?, '$[*]' COLUMNS(id CHAR(36) PATH '$.id',request_id CHAR(36) PATH '$.request',start_date DATE PATH '$.start')) j";
            $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,finalized_by,finalized_at,request_remarks) SELECT j.request_id,'NATIVE','APPOINTMENT',?,a.appointment_type,a.asc_location_id,a.arpa_division_location_id,j.start_date,'NATIONAL_APPROVED',?,'2026-11-01 00:00:00',? FROM {$input} JOIN arpa_division_appointment a ON a.id=?")->execute([$officer,$this->actor(9),$tag,$json,$template['appointment']]);
            $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,asc_location_id,arpa_division_location_id,asc_name_snapshot,arpa_name_snapshot,district_location_id_snapshot,district_name_snapshot,effective_from) SELECT j.id,'NATIVE',j.request_id,?,a.appointment_type,a.asc_location_id,a.arpa_division_location_id,a.asc_name_snapshot,a.arpa_name_snapshot,a.district_location_id_snapshot,a.district_name_snapshot,j.start_date FROM {$input} JOIN arpa_division_appointment a ON a.id=?")->execute([$officer,$json,$template['appointment']]);
            $this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,action_at,record_origin,previous_status,new_status,timestamp_provenance) SELECT j.request_id,'APPROVE','NATIONAL',?,'2026-11-01 00:00:00','NATIVE','NATIONAL_VERIFIED','NATIONAL_APPROVED','NATIVE_RECORDED' FROM {$input}")->execute([$this->actor(9),$json]);
            $before=$this->operationalDigest();
            $clock=function(bool $roll=true)use($day,$next):array{
                $this->pdo->rolloverTo=null;$this->pdo->exec("SET timestamp=UNIX_TIMESTAMP('{$day} 23:59:59')");
                $this->pdo->rolloverTo=$roll?$next.' 00:00:01':null;
                return [$this->pdo->reportingDateReads,$this->pdo->rollovers,$this->pdo->unfrozenCandidateQueries];
            };
            $checkClock=function(array $before,bool $rolled=true):void{
                $this->same(1,$this->pdo->reportingDateReads-$before[0],'exactly one MySQL reporting-date capture per complete operation');
                $this->same($rolled?1:0,$this->pdo->rollovers-$before[1],'clock moves only between candidate batches');
                $this->same($before[2],$this->pdo->unfrozenCandidateQueries,'candidate and summary SQL contain no live CURRENT_DATE');
                $this->same(false,$this->pdo->inTransaction(),'rollover operation releases its snapshot');
            };
            $detail=function()use($tag):array{
                $d=DataTableRegistry::definition('arpa-open-appointments');$d['baseWhere'][]='req.request_remarks=?';$d['baseParams'][]=$tag;
                // Test-only identity projection lets us compare exact rows, not repeated officer names.
                $d['select'][]='a.id AS appointment_id';
                $d['approvalReportingDate']='1900-01-01'; // The adapter must overwrite even a prepopulated date.
                array_splice($d['columns'],count($d['columns'])-1,0,[['key'=>'appointment_id','label'=>'Appointment ID','sort'=>'a.id']]);
                return $d;
            };
            foreach(['asc','desc'] as $direction){
                $oracle=$this->pdo->prepare("SELECT a.id FROM arpa_division_appointment a JOIN arpa_division_appointment_request r ON r.id=a.request_id WHERE r.request_remarks=? ORDER BY CASE WHEN a.effective_from>DATE '{$day}' THEN 'SCHEDULED' ELSE 'ACTIVE' END ".strtoupper($direction).',a.id ASC');$oracle->execute([$tag]);$expected=$oracle->fetchAll(PDO::FETCH_COLUMN);
                foreach([0,125,275] as $offset){
                    $mark=$clock();$this->context(1);$r=(new DataTableQuery($this->pdo,$detail(),new DataTableRequest(['length'=>25,'start'=>$offset,'order'=>[['column'=>9,'dir'=>$direction]]])))->response();
                    $this->same(300,$r['recordsTotal'],'rollover total');$this->same(300,$r['recordsFiltered'],'rollover filtered total');
                    $this->same(array_slice($expected,$offset,25),array_column($r['data'],'appointment_id'),'first/middle/final '.$direction.' page matches the frozen-date SQL oracle');$checkClock($mark);
                }
                $mark=$clock();$rows=iterator_to_array((new DataTableQuery($this->pdo,$detail(),new DataTableRequest(['order'=>[['column'=>9,'dir'=>$direction]],'search'=>['value'=>'Midnight']])))->exportRows());
                $ids=array_column($rows,'appointment_id');$this->same($expected,$ids,'CSV preserves all 300 qualified identities and status ordering across midnight');$this->same(300,count(array_unique($ids)),'CSV has no omissions or duplicates');$checkClock($mark);
                $mark=$clock(false);$stable=iterator_to_array((new DataTableQuery($this->pdo,$detail(),new DataTableRequest(['order'=>[['column'=>9,'dir'=>$direction]],'search'=>['value'=>'Midnight']])))->exportRows());
                $this->same($rows,$stable,'rollover and stable-clock CSV results agree exactly');$checkClock($mark,false);
            }
            foreach(['ACTIVE'=>50,'SCHEDULED'=>250] as $status=>$count){
                $mark=$clock();$r=(new DataTableQuery($this->pdo,$detail(),new DataTableRequest(['filters'=>['status'=>$status],'search'=>['value'=>'Midnight'],'order'=>[['column'=>9,'dir'=>'asc']]])))->response();
                $this->same(300,$r['recordsTotal'],'search/filter base count retains the captured date');$this->same($count,$r['recordsFiltered'],'status filter retains captured date after base scan rolls over');$checkClock($mark);
                $mark=$clock();$rows=iterator_to_array((new DataTableQuery($this->pdo,$detail(),new DataTableRequest(['filters'=>['status'=>$status],'search'=>['value'=>'Midnight']])))->exportRows());
                $this->same($count,count($rows),'filtered CSV agrees with filtered listing after midnight');$checkClock($mark);
            }
            $mark=$clock();$source=$detail();$source['baseWhere'][]='a.effective_from>CURRENT_DATE()';
            $r=(new DataTableQuery($this->pdo,$source,new DataTableRequest([])))->response();$this->same(250,$r['recordsTotal'],'date-dependent candidate selection is frozen too');$checkClock($mark);
            foreach([6=>300,8=>300,2=>0,10=>0] as $actor=>$count){
                $mark=$clock();$this->context($actor);$r=(new DataTableQuery($this->pdo,$detail(),new DataTableRequest(['order'=>[['column'=>9,'dir'=>'asc']]])))->response();
                $this->same($count,$r['recordsTotal'],'rollover preserves ASC/District/National scope for actor '.$actor);$checkClock($mark,$count>0);
            }
            foreach([6=>'arpa-open-appointments-summary',8=>'arpa-open-appointments-district-summary'] as $actor=>$key){
                $mark=$clock();$this->context($actor);$d=DataTableRegistry::definition($key);$d['approvalSummarySource']['baseWhere'][]='req.request_remarks=?';$d['approvalSummarySource']['baseParams'][]=$tag;
                $r=(new DataTableQuery($this->pdo,$d,new DataTableRequest([])))->response();$this->same(300,array_sum(array_column($r['data'],'total_count')),'geographic summary and source share frozen date');$checkClock($mark);
            }
            $this->pdo->rolloverTo=null;$this->pdo->exec('SET timestamp=DEFAULT');$this->context(1);
            $this->same($before,$this->operationalDigest(),'rollover reads do not modify appointments, closures or approval evidence');
            echo 'Midnight rollover '.$day.' -> '.$next.": 300/300 rows; counts, pages, filters, CSV and scopes agree.\n";
        }
        foreach(['2026-02-30',"2026-12-31' OR 1=1 --"] as $date){
            $d=$detail();unset($d['readAdapter']);$d['approvalReportingDate']=$date;
            try{(new DataTableQuery($this->pdo,$d,new DataTableRequest([])))->candidateBatches()->current();throw new DomainException('Invalid reporting date accepted.');}
            catch(RuntimeException $e){$this->same('Invalid approved-assignment reporting date.',$e->getMessage(),'invalid date cannot enter the SQL literal');}
        }
        }finally{$this->pdo->rolloverTo=null;$this->pdo->exec('SET timestamp=DEFAULT');$this->context(1);}
        $this->same($zone,$this->pdo->query('SELECT @@session.time_zone')->fetchColumn(),'reporting date never changes the database timezone');
    }
    private function newId():string{$s=bin2hex(random_bytes(16));return substr($s,0,8).'-'.substr($s,8,4).'-'.substr($s,12,4).'-'.substr($s,16,4).'-'.substr($s,20);}
    private function operationalDigest():string
    {
        $hash=hash_init('sha256');foreach(['officer','arpa_division_appointment','arpa_division_appointment_request','arpa_division_appointment_closure','arpa_appointment_workflow_action','arpa_appointment_data_correction','arpa_appointment_historical_approval_case','arpa_appointment_historical_approval_event'] as $table){$s=$this->pdo->query('SELECT * FROM '.$table.' ORDER BY id');while($row=$s->fetch())hash_update($hash,serialize($row));$s->closeCursor();}return hash_final($hash);
    }
    private function table(array $input=[],array $context=[]):DataTableQuery{return new DataTableQuery($this->pdo,DataTableRegistry::definition('arpa-open-appointments',$context),new DataTableRequest($input));}
    private function profile(string $id,?array $scope=[self::ASC]):array{return $this->call(new OfficerProfileService($this->pdo),'operationalAppointments',$id,$scope)[0];}
    private function national(array $s):void{$this->pdo->prepare("UPDATE arpa_division_appointment_request SET workflow_status='NATIONAL_APPROVED',finalized_by=?,finalized_at=NOW() WHERE id=?")->execute([$this->actor(9),$s['request']]);$this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,action_at,record_origin,previous_status,new_status,timestamp_provenance) VALUES(?,'APPROVE','NATIONAL',?,NOW(),'NATIVE','NATIONAL_VERIFIED','NATIONAL_APPROVED','NATIVE_RECORDED')")->execute([$s['request'],$this->actor(9)]);}
    private function context(int $actor):void{$this->call($this->fixture,'context',$this->actor($actor));}
    private function actor(int $n):string{return '00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT);}
    private function call(object $o,string $method,...$args):mixed{return (new ReflectionMethod($o,$method))->invoke($o,...$args);}
    private function questions():int{return (int)$this->pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];}
    private function snapshot():array{$out=[];foreach($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t)$out[$t]=$this->pdo->query('SELECT * FROM `'.$t.'` ORDER BY 1')->fetchAll();return $out;}
    public static function csvChild(array $args):never
    {
        $c=config('database');if($c['host']!=='127.0.0.1'||!preg_match('/^dems_test_approved_assignments_[a-f0-9]{10}$/D',$c['database']))throw new RuntimeException('Unsafe CSV fixture database.');
        $test=new self();$test->pdo=new ApprovedAssignmentsFixturePdo("mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        if($test->pdo->query('SELECT DATABASE()')->fetchColumn()!==$c['database'])throw new RuntimeException('Wrong CSV fixture database.');(new ReflectionProperty(Database::class,'pdo'))->setValue(null,$test->pdo);
        $test->fixture=new ArpaHistoricalAppointmentApprovalIsolatedTest();(new ReflectionProperty($test->fixture,'pdo'))->setValue($test->fixture,$test->pdo);$test->context((int)$args[3]);$_GET=json_decode(base64_decode($args[2]),true);
        if(!empty($_GET['_simulate_export_failure'])){$test->pdo->failSql='_qualification_id';unset($_GET['_simulate_export_failure']);register_shutdown_function(static fn()=>fwrite(STDERR,'FIXTURE_HTTP_STATUS='.http_response_code()));}
        (new App\Controllers\DataTableController())->export('arpa-open-appointments');
    }
    private function same(mixed $expected,mixed $actual,string $label):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($label.'; expected '.var_export($expected,true).', got '.var_export($actual,true));}
}
try{if(($argv[1]??'')==='--csv')ArpaApprovedAssignmentsIsolatedTest::csvChild($argv);else(new ArpaApprovedAssignmentsIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
