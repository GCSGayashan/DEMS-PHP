<?php
declare(strict_types=1);
define('ARPA_HISTORICAL_RELEASE_FIXTURE_ONLY',true);
require __DIR__.'/ArpaHistoricalReleaseSecurityIsolatedTest.php';
use App\Core\{Auth,Database};
use App\Services\{ArpaAppointmentService,ArpaHistoricalAppointmentApprovalService,ArpaImportedAppointmentWorkflowPolicy};

final class ArpaImportedOriginalApprovalIsolatedTest
{
    private PDO $pdo;private object $fixture;private object $ui;private int $assertions=0;
    public function run():void
    {
        $c=config('database');if(!in_array($c['host'],['127.0.0.1','localhost'],true)||!str_starts_with($c['database'],'dems_test_'))throw new RuntimeException('Disposable local configuration required.');
        $db='dems_test_hist_ui_'.bin2hex(random_bytes(5));$server=new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $property=new ReflectionProperty(Database::class,'pdo');$old=$property->getValue();$session=$_SESSION??[];$created=false;
        try{
            $server->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
            $this->pdo=new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$db};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true,PDO::MYSQL_ATTR_INIT_COMMAND=>'SET SESSION default_storage_engine=InnoDB']);$property->setValue(null,$this->pdo);
            if($this->pdo->query('SELECT DATABASE()')->fetchColumn()!==$db)throw new RuntimeException('Wrong database.');
            $sources=ArpaHistoricalReleaseSecurityIsolatedTest::fixture($this->pdo);$this->fixture=new ArpaHistoricalAppointmentApprovalIsolatedTest();(new ReflectionProperty($this->fixture,'pdo'))->setValue($this->fixture,$this->pdo);
            $this->ui=new ArpaHistoricalApprovalUiIsolatedTest();(new ReflectionProperty($this->ui,'db'))->setValue($this->ui,$db);
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS arpa_appointment_stage_review_audit(id BIGINT PRIMARY KEY,entity_type VARCHAR(30),request_id CHAR(36),review_stage VARCHAR(30),changed_by CHAR(36));
                ALTER TABLE audit_event MODIFY severity VARCHAR(30) NOT NULL DEFAULT 'INFO',ADD created_at DATETIME;
                ALTER TABLE system_notification ADD module_code VARCHAR(80),ADD title VARCHAR(180),ADD message VARCHAR(1000),ADD workflow_stage VARCHAR(80),ADD action_url VARCHAR(500),ADD action_label VARCHAR(80),ADD completed_at DATETIME,ADD resolved_by CHAR(36),ADD resolution_reason VARCHAR(500),ADD dedupe_key VARCHAR(255),ADD required_role_assignment_id CHAR(36),ADD required_scope_assignment_id CHAR(36),ADD created_by CHAR(36),ADD updated_at DATETIME;");
            // Only this disposable fixture permits corrupt legacy rows so the application guard can be tested.
            $this->pdo->exec('ALTER TABLE arpa_division_appointment_closure DROP INDEX uq_fixture_closure, MODIFY effective_to VARCHAR(30) NULL');
            $s=$sources[0];$id=$s['request'];
            $this->pdo->prepare("UPDATE arpa_division_appointment_request SET requested_effective_from='2020-10-15',created_by=? WHERE id=?")->execute([self::actor(1),$id]);
            $this->pdo->prepare("UPDATE arpa_division_appointment SET effective_from='2020-10-15' WHERE id=?")->execute([$s['appointment']]);
            $this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_source,data_correction_id,context_snapshot_json,approval_timestamp_provenance) VALUES(UUID(),'LEGACY_IMPORT',?,?,'2025-01-20','18000000-0000-4000-8000-000000000001','DATA_ISSUE_CORRECTION',?,'{}','UNAVAILABLE_FROM_LEGACY_SOURCE')")->execute([$s['appointment'],$id,$s['correction']]);
            // An adjacent later appointment proves the NULL request end is not mistaken for an open period.
            $this->pdo->prepare("UPDATE arpa_division_appointment_request SET arpa_division_location_id=?,requested_effective_from='2025-01-21' WHERE id=?")->execute([$s['division'],$sources[1]['request']]);
            $this->pdo->prepare("UPDATE arpa_division_appointment SET arpa_division_location_id=?,effective_from='2025-01-21' WHERE id=?")->execute([$s['division'],$sources[1]['appointment']]);
            $this->context(1);$historical=new ArpaHistoricalAppointmentApprovalService($this->pdo);$case=$historical->register($s['correction'],['reason'=>'Existing imported period','evidence_reference'=>'Synthetic original application'],self::actor(1))['case'];
            $this->pdo->prepare("UPDATE arpa_division_appointment_request SET workflow_status='ASC_APPROVED' WHERE id=?")->execute([$id]);
            $this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,new_status,record_origin,timestamp_provenance) VALUES(?,'APPROVE','ASC',?,'ASC_APPROVED','LEGACY_IMPORT','UNAVAILABLE_FROM_LEGACY_SOURCE')")->execute([$id,self::actor(1)]);
            $appointments=$this->rows('arpa_division_appointment');$closures=$this->rows('arpa_division_appointment_closure');$caseBefore=$this->rows('arpa_appointment_historical_approval_case');$events=$this->rows('arpa_appointment_historical_approval_event');$history=$this->rows('arpa_appointment_workflow_action');
            $otherRequests=array_values(array_filter($this->rows('arpa_division_appointment_request'),static fn(array $r):bool=>$r['id']!==$id));$corrections=$this->rows('arpa_appointment_data_correction');
            $service=new ArpaAppointmentService($this->pdo);$page='/hr/arpa-appointments/requests/division/'.$id;
            [$status,$body]=$this->route('GET',$page,[],6);$this->same(200,$status,'District detail opens');
            foreach(['District Verify','2020-10-15','2025-01-20','workflow/division/'.$id.'/verify?stage=DISTRICT'] as $text)$this->same(true,str_contains($body,$text),'original page shows '.$text);
            $this->same(false,str_contains($body,'/attest'),'separate historical workflow does not supply action buttons');
            foreach([1,3,10,11] as $actor){$this->context($actor);$this->fails(fn()=>$service->workflow('division',$id,'VERIFY','DISTRICT','Attempt',self::actor($actor)),'unauthorized reviewer '.$actor);}
            [$status]=$this->route('POST','/hr/arpa-appointments/workflow/division/'.$id.'/verify?stage=DISTRICT',[],6,null,'missing-both');$this->same(419,$status,'missing CSRF rejected');
            $this->context(1);$this->fails(fn()=>$historical->submit($case['id'],0,self::actor(1)),'separate historical submission blocked');
            $this->same(false,$historical->interfaceGet($case['id'],self::actor(1))['actions']['submit'],'separate case remains audit-only');
            $this->same(0,(new App\Services\ArpaHistoricalApprovalReadService($this->pdo))->count(self::actor(1)),'audit-only case is not an independent actionable submission');
            $this->negativePeriods($service,$s,$sources[1]);
            $this->closureRequestConsistency($s,$sources[2]);
            $this->fails(fn()=>App\Services\ArpaAppointmentRules::assertNativeEffectiveDate('2020-10-15'),'native 2025 minimum remains unchanged');
            foreach([[6,'VERIFY','DISTRICT','DISTRICT_VERIFIED'],[7,'APPROVE','DISTRICT','DISTRICT_APPROVED'],[8,'VERIFY','NATIONAL','NATIONAL_VERIFIED'],[9,'APPROVE','NATIONAL','NATIONAL_APPROVED']] as [$actor,$action,$stage,$expected]){
                $this->context($actor);
                $this->closureFailures($service,$s,$actor,$action,$stage);
                [$http]=$this->route('POST','/hr/arpa-appointments/workflow/division/'.$id.'/'.strtolower($action).'?stage='.$stage,['comments'=>'Reviewed imported period'],$actor);$this->same(302,$http,'original authorized POST '.$stage.' '.$action);
                $q=$this->pdo->prepare('SELECT workflow_status,requested_effective_from,requested_effective_to FROM arpa_division_appointment_request WHERE id=?');$q->execute([$id]);$approved=$q->fetch();$this->same($expected,$approved['workflow_status'],'original '.$stage.' '.$action);
                $this->same('2020-10-15',$approved['requested_effective_from'],'original request start is unchanged');$this->same(null,$approved['requested_effective_to'],'canonical closure does not fabricate an original request end');
                $this->fails(fn()=>$service->workflow('division',$id,$action,$stage,'Repeated action',self::actor($actor)),'repeat action cannot duplicate history');
                $this->same($appointments,$this->rows('arpa_division_appointment'),'no appointment insert or mutation');$this->same($closures,$this->rows('arpa_division_appointment_closure'),'closure and complete period preserved');
            }
            $all=$this->rows('arpa_appointment_workflow_action');$this->same(array_slice($all,0,count($history)),$history,'original history preserved');$new=array_slice($all,count($history));$this->same(4,count($new),'exactly four new approval events');
            foreach($new as $i=>$event){$this->same(self::actor([6,7,8,9][$i]),$event['user_id'],'actual authorized reviewer');$this->same(true,$event['action_at']!==null&&substr($event['action_at'],0,10)===date('Y-m-d'),'actual current timestamp, not historical fabrication');$this->same('NATIVE_RECORDED',$event['timestamp_provenance'],'new approval is native-recorded audit');}
            $this->same($caseBefore,$this->rows('arpa_appointment_historical_approval_case'),'registered historical case unchanged');$this->same($events,$this->rows('arpa_appointment_historical_approval_event'),'historical audit unchanged');
            $this->same($otherRequests,array_values(array_filter($this->rows('arpa_division_appointment_request'),static fn(array $r):bool=>$r['id']!==$id)),'unrelated original requests unchanged');$this->same($corrections,$this->rows('arpa_appointment_data_correction'),'source corrections unchanged');
            $this->nativeWorkflow($service,$sources[2]);
            echo 'ArpaImportedOriginalApprovalIsolatedTest: '.$this->assertions." assertions passed; disposable schema removed.\n";
        }finally{$property->setValue(null,$old);$_SESSION=$session;Auth::forgetRequestCache();if($created)$server->exec('DROP DATABASE `'.$db.'`');}
    }
    private function closureFailures(ArpaAppointmentService $service,array $s,int $actor,string $action,string $stage):void
    {
        $corruptions=[
            'missing closure'=>function()use($s):void{$this->pdo->prepare('DELETE FROM arpa_division_appointment_closure WHERE appointment_id=?')->execute([$s['appointment']]);},
            'duplicated closure'=>function()use($s):void{
                $q=$this->pdo->prepare('SELECT * FROM arpa_division_appointment_closure WHERE appointment_id=?');$q->execute([$s['appointment']]);$row=$q->fetch();$row['id']=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();
                $this->pdo->prepare('INSERT INTO arpa_division_appointment_closure('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
            },
            'NULL end'=>null,'empty end'=>'','zero end'=>'0000-00-00','invalid end'=>'2025-02-30','end before start'=>'2020-10-14',
            'request start mismatch'=>function()use($s):void{$this->pdo->prepare("UPDATE arpa_division_appointment_request SET requested_effective_from='2020-10-16' WHERE id=?")->execute([$s['request']]);},
            'request end mismatch'=>function()use($s):void{$this->pdo->prepare("UPDATE arpa_division_appointment_request SET requested_effective_to='2025-01-21' WHERE id=?")->execute([$s['request']]);},
        ];
        foreach($corruptions as $label=>$corrupt){$this->pdo->beginTransaction();try{
            if($corrupt instanceof Closure)$corrupt();else $this->pdo->prepare('UPDATE arpa_division_appointment_closure SET effective_to=? WHERE appointment_id=?')->execute([$corrupt,$s['appointment']]);
            $before=[];foreach(['arpa_division_appointment_request','arpa_division_appointment','arpa_division_appointment_closure','arpa_appointment_data_correction','arpa_appointment_workflow_action','arpa_appointment_historical_approval_case','arpa_appointment_historical_approval_event','audit_event','system_notification'] as $table)$before[$table]=$this->rows($table);
            $this->fails(fn()=>$service->workflow('division',$s['request'],$action,$stage,'Corrupt closure attempt',self::actor($actor)),$stage.' '.$action.' rejects '.$label);
            foreach($before as $table=>$rows)$this->same($rows,$this->rows($table),$label.' rejected before any write to '.$table);
        }finally{$this->pdo->rollBack();}}
    }
    private function closureRequestConsistency(array $s,array $other):void
    {
        $this->pdo->beginTransaction();try{
            $policy=new ArpaImportedAppointmentWorkflowPolicy($this->pdo);
            $this->pdo->prepare("UPDATE arpa_division_appointment_request SET requested_effective_to='2025-01-20' WHERE id=?")->execute([$s['request']]);
            $q=$this->pdo->prepare('SELECT * FROM arpa_division_appointment_request WHERE id=?');$q->execute([$s['request']]);$request=$q->fetch();
            $this->same('2025-01-20',$policy->period($request,true)['effective_to'],'matching explicit original request end is accepted');
            $this->pdo->prepare('UPDATE arpa_division_appointment_closure SET request_id=? WHERE appointment_id=?')->execute([$other['request'],$s['appointment']]);
            $this->fails(fn()=>$policy->period($request,true),'unrelated closure request is rejected');
            foreach(['END','TRANSFER'] as $type){
                $this->pdo->prepare('UPDATE arpa_division_appointment_request SET request_type=?,source_appointment_id=?,officer_id=?,requested_effective_to=? WHERE id=?')->execute([$type,$s['appointment'],$s['officer'],'2025-01-20',$other['request']]);
                $this->same('2025-01-20',$policy->period($request,true)['effective_to'],'matching authoritative '.$type.' request end is accepted');
                $this->pdo->prepare("UPDATE arpa_division_appointment_request SET requested_effective_to='2025-01-21' WHERE id=?")->execute([$other['request']]);
                $this->fails(fn()=>$policy->period($request,true),'inconsistent authoritative '.$type.' request end is rejected');
            }
        }finally{$this->pdo->rollBack();}
    }
    private function nativeWorkflow(ArpaAppointmentService $service,array $s):void
    {
        $this->pdo->beginTransaction();try{
            $this->pdo->prepare("UPDATE arpa_division_appointment_request SET record_origin='NATIVE',workflow_status='ASC_APPROVED',requested_effective_from='2025-01-01' WHERE id=?")->execute([$s['request']]);
            $this->pdo->prepare("UPDATE arpa_division_appointment SET record_origin='NATIVE',effective_from='2025-01-01' WHERE id=?")->execute([$s['appointment']]);
            $before=$this->rows('arpa_division_appointment');
            foreach([[6,'VERIFY','DISTRICT','DISTRICT_VERIFIED'],[7,'APPROVE','DISTRICT','DISTRICT_APPROVED'],[8,'VERIFY','NATIONAL','NATIONAL_VERIFIED'],[9,'APPROVE','NATIONAL','NATIONAL_APPROVED']] as [$actor,$action,$stage,$expected]){
                $this->context($actor);$this->same($expected,$service->workflow('division',$s['request'],$action,$stage,'Native regression',self::actor($actor)),'native workflow '.$stage.' '.$action);
            }
            $this->same($before,$this->rows('arpa_division_appointment'),'native existing appointments are not duplicated');
        }finally{$this->pdo->rollBack();}
    }
    private function negativePeriods(ArpaAppointmentService $service,array $s,array $other):void
    {
        $this->context(6);
        foreach(["UPDATE arpa_division_appointment_request SET legacy_history_only=1 WHERE id=?","UPDATE arpa_division_appointment_request SET appointment_type='ACTING' WHERE id=?","UPDATE arpa_division_appointment_request SET requested_effective_from='2020-10-16' WHERE id=?","UPDATE arpa_division_appointment_request SET requested_effective_to='2025-01-21' WHERE id=?"] as $sql){$this->pdo->beginTransaction();try{$this->pdo->prepare($sql)->execute([$s['request']]);$this->fails(fn()=>$service->workflow('division',$s['request'],'VERIFY','DISTRICT','Attempt',self::actor(6)),'inconsistent/unsupported import blocked');}finally{$this->pdo->rollBack();}}
        $this->pdo->beginTransaction();try{
            $q=$this->pdo->prepare('SELECT id FROM arpa_division_appointment_request WHERE id<>? LIMIT 1');$q->execute([$s['request']]);$otherRequestId=$q->fetchColumn();
            $this->pdo->prepare('UPDATE arpa_division_appointment SET request_id=? WHERE id=?')->execute([$otherRequestId,$s['appointment']]);
            $this->fails(fn()=>$service->workflow('division',$s['request'],'VERIFY','DISTRICT','Attempt',self::actor(6)),'missing canonical appointment never recreated');
        }finally{$this->pdo->rollBack();}
        $this->pdo->beginTransaction();try{
            $q=$this->pdo->prepare('SELECT * FROM arpa_division_appointment WHERE id=?');$q->execute([$s['appointment']]);$duplicate=$q->fetch();$duplicate['id']=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();
            $this->pdo->prepare('INSERT INTO arpa_division_appointment('.implode(',',array_keys($duplicate)).') VALUES('.implode(',',array_fill(0,count($duplicate),'?')).')')->execute(array_values($duplicate));
            $this->fails(fn()=>$service->workflow('division',$s['request'],'VERIFY','DISTRICT','Attempt',self::actor(6)),'multiple canonical appointments remain blocked');
        }finally{$this->pdo->rollBack();}
        $this->pdo->beginTransaction();try{
            $this->pdo->prepare("UPDATE arpa_division_appointment SET effective_from='2021-01-01' WHERE id=?")->execute([$other['appointment']]);
            $this->pdo->prepare("UPDATE arpa_division_appointment_request SET requested_effective_from='2021-01-01' WHERE id=?")->execute([$other['request']]);
            $this->fails(fn()=>$service->workflow('division',$s['request'],'VERIFY','DISTRICT','Attempt',self::actor(6)),'genuine overlapping period remains blocked');
        }finally{$this->pdo->rollBack();}
        // Maker-checker uses the original workflow history, not the separate case history.
        $this->pdo->beginTransaction();try{$this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,new_status) VALUES(?,'VERIFY','DISTRICT',?,'DISTRICT_VERIFIED')")->execute([$s['request'],self::actor(7)]);
            $this->pdo->prepare("UPDATE arpa_division_appointment_request SET workflow_status='DISTRICT_VERIFIED' WHERE id=?")->execute([$s['request']]);$this->context(7);
            $this->fails(fn()=>$service->workflow('division',$s['request'],'APPROVE','DISTRICT','Attempt',self::actor(7)),'same-stage verifier cannot approve');
        }finally{$this->pdo->rollBack();$this->context(6);}
    }
    private function context(int $n):void{(new ReflectionMethod($this->fixture,'context'))->invoke($this->fixture,self::actor($n),$n===3?'SYSTEM_ADMIN':null);}
    private static function actor(int $n):string{return '00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT);}
    private function route(string $method,string $path,array $post,int $actor,?string $role=null,string $csrf='valid'):array{return (new ReflectionMethod($this->ui,'route'))->invoke($this->ui,$method,$path,$post,$actor,$role,$csrf);}
    private function rows(string $table):array{return $this->pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();}
    private function fails(callable $work,string $label):void{try{$work();throw new RuntimeException($label.' unexpectedly succeeded');}catch(DomainException){$this->assertions++;}}
    private function same(mixed $e,mixed $a,string $label):void{$this->assertions++;if($e!==$a)throw new RuntimeException($label.'; expected '.var_export($e,true).', got '.var_export($a,true));}
}
try{(new ArpaImportedOriginalApprovalIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
