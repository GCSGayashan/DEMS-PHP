<?php
declare(strict_types=1);
define('ARPA_HISTORICAL_APPROVAL_FIXTURE_ONLY',true);
require __DIR__.'/ArpaHistoricalAppointmentApprovalIsolatedTest.php';

use App\Core\{Auth,Database,DataTableFormat,DataTableRegistry,DataTableQuery,DataTableRequest};
use App\Services\{ArpaHistoricalAppointmentApprovalService,ArpaHistoricalApprovalReadService,ArpaWorkflowQueuePolicy,UserContextService};

final class ArpaHistoricalApprovalUiIsolatedTest
{
    private PDO $pdo;
    private string $db;
    private object $fixture;
    private array $cases=[];
    private int $assertions=0;
    private const ASC='10000000-0000-4000-8000-000000000001';
    public function run():void
    {
        $c=config('database');if(!in_array($c['host'],['127.0.0.1','localhost'],true)||!str_starts_with($c['database'],'dems_test_'))throw new RuntimeException('Use isolated process-only database settings.');
        $this->db='dems_test_hist_ui_'.bin2hex(random_bytes(5));$server=new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4',$c['host'],$c['port']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $property=new ReflectionProperty(Database::class,'pdo');$old=$property->getValue();$session=$_SESSION??[];$created=false;
        try{
            $server->exec('CREATE DATABASE `'.$this->db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;$this->pdo=self::connect($this->db);
            if($this->pdo->query('SELECT DATABASE()')->fetchColumn()!==$this->db)throw new RuntimeException('Wrong database before fixture writes.');$property->setValue(null,$this->pdo);
            $base=new ArpaAppointmentCorrectionAmendmentDraftTest();(new ReflectionProperty($base,'pdo'))->setValue($base,$this->pdo);$this->call($base,'createFixtureSchema');
            $this->pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/062_arpa_appointment_correction_amendment_foundation.sql'));$this->call($base,'insertFixtures');$this->call($base,'exerciseHistoricalPeerDecisions');
            if(!(new \App\Services\ArpaHistoricalApprovalReadService($this->pdo))->available())$this->pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/067_arpa_historical_approval_foundation.sql'));
            $this->pdo->exec("INSERT INTO designation VALUES('historical-test-arpa','ARPA_OFFICER');CREATE TABLE IF NOT EXISTS arpa_appointment_workflow_action(id BIGINT AUTO_INCREMENT PRIMARY KEY,request_id CHAR(36),action VARCHAR(50),stage VARCHAR(50),user_id CHAR(36),action_at DATETIME NULL,record_origin VARCHAR(40),previous_status VARCHAR(50),new_status VARCHAR(50),comments TEXT,timestamp_provenance VARCHAR(80)) ENGINE=InnoDB");
            $this->fixture=new ArpaHistoricalAppointmentApprovalIsolatedTest();(new ReflectionProperty($this->fixture,'pdo'))->setValue($this->fixture,$this->pdo);
            for($i=0;$i<9;$i++)$this->cases[$i]=$this->call($this->fixture,'source');
            $this->schema();$service=new ArpaHistoricalAppointmentApprovalService($this->pdo);
            foreach($this->cases as $i=>&$s){$this->context(1);$s['case']=$service->register($s['correction'],['reason'=>'Synthetic UI baseline','evidence_reference'=>'Synthetic letter <script>alert(1)</script>'],self::actor(1));if($i===0||$i===8)continue;$s['case']=$service->submit($s['case']['case']['id'],0,self::actor(1));foreach([[4,'VERIFY'],[5,'APPROVE'],[6,'VERIFY'],[7,'APPROVE'],[8,'VERIFY']] as $n=>[$actor,$action]){if($n>=$i-1)break;$this->context($actor);$s['case']=$service->review($s['case']['case']['id'],$action,(int)$s['case']['case']['version'],self::actor($actor),'Synthetic stage review','Synthetic evidence');}}
            unset($s);$this->context(1);$sourceState=$this->call($this->fixture,'operational');
            $this->queues();$this->routes();$this->staleSource();$this->staleDraftQueues();$this->profile();$this->missingSchema();
            $this->same($sourceState,$this->call($this->fixture,'operational'),'all routed UI actions leave appointments, requests, closures, original workflows, provenance and corrections unchanged');
            $out=$this->securityHeaders();$this->same(true,str_contains($out,'assertions passed'),'SecurityHeadersTest passes using this isolated schema');echo trim($out)."\n";
            echo 'ArpaHistoricalApprovalUiIsolatedTest: '.$this->assertions." assertions passed; disposable database removed.\n";
        }finally{$property->setValue(null,$old);$_SESSION=$session;Auth::forgetRequestCache();if($created)$server->exec('DROP DATABASE `'.$this->db.'`');}
    }
    private function schema():void
    {
        // Only disposable fixture schema extensions for the actual application layout/queries.
        $this->pdo->exec("ALTER TABLE location ADD approval_status VARCHAR(30) DEFAULT 'APPROVED',ADD operational_status VARCHAR(30) DEFAULT 'ACTIVE',ADD effective_from DATE DEFAULT '2024-01-05',ADD effective_to DATE NULL;
            ALTER TABLE system_user ADD officer_id CHAR(36),ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP;
            ALTER TABLE officer ADD primary_office_id CHAR(36);
            ALTER TABLE office ADD dad_number VARCHAR(50),ADD name_en VARCHAR(255),ADD approval_status VARCHAR(30) DEFAULT 'APPROVED',ADD operational_status VARCHAR(30) DEFAULT 'ACTIVE',ADD effective_from DATE DEFAULT '2024-01-05',ADD effective_to DATE NULL;
            ALTER TABLE officer_office_assignment ADD reason VARCHAR(255);
            ALTER TABLE user_account_role ADD reason VARCHAR(255);
            ALTER TABLE user_account_scope ADD office_id CHAR(36);
            ALTER TABLE arpa_division_appointment_request ADD created_by CHAR(36),ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP,ADD finalized_by CHAR(36),ADD finalized_at DATETIME NULL;
            ALTER TABLE arpa_division_appointment ADD letter_reference_id CHAR(36),ADD letter_type VARCHAR(80),ADD letter_date DATE NULL;
            CREATE TABLE officer_edit_request(id CHAR(36) PRIMARY KEY,officer_id CHAR(36));
            CREATE TABLE arpa_subject_assignment_request(id CHAR(36) PRIMARY KEY,officer_id CHAR(36),asc_location_id CHAR(36),workflow_status VARCHAR(50),legacy_history_only TINYINT DEFAULT 0);
            CREATE TABLE system_notification(id CHAR(36) PRIMARY KEY,entity_type VARCHAR(80),entity_id CHAR(36),recipient_user_id CHAR(36),notification_type VARCHAR(50),action_status VARCHAR(30),read_at DATETIME NULL,priority VARCHAR(20),created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
            INSERT IGNORE INTO application_role_permission SELECT id,'13000000-0000-4000-8000-000000000011' FROM application_role;");
    }
    private function queues():void
    {
        $read=new ArpaHistoricalApprovalReadService($this->pdo);
        foreach([1,4,5,6,7,8,9,10] as $actor){$this->context($actor);$q=$read->query(self::actor($actor));$s=$this->pdo->prepare($q['sql']);$s->execute($q['params']);$rows=$s->fetchAll();$data=$this->table('arpa-historical-approvals-pending');$this->same(count($rows),$data['recordsTotal'],'historical table population equals shared count for role '.$actor);$this->same(count($rows),$read->count(self::actor($actor)),'historical count agrees with records');$this->same(count($rows)+$this->table('arpa-submitted-appointments')['recordsTotal'],(new ArpaWorkflowQueuePolicy($this->pdo))->actionableCount(self::actor($actor)),'dashboard pending total reconciles');
            $expected=match($actor){1=>['DRAFT','SUBMITTED'],4=>['DRAFT','SUBMITTED'],5=>['ASC_VERIFIED'],6=>['ASC_APPROVED'],7=>['DISTRICT_VERIFIED'],8=>['DISTRICT_APPROVED'],9=>['NATIONAL_VERIFIED'],default=>[]};
            $statuses=array_values(array_unique(array_column($rows,'historical_status')));sort($statuses);sort($expected);$this->same($expected,$statuses,'only the authorized stage is listed for role '.$actor);
            if($actor===10)$this->same(0,count($rows),'other District has no actionable cases');
        }
        $this->context(1);$page=$this->table('arpa-historical-approvals-pending',[],['length'=>10,'search'=>['value'=>'Synthetic']]);$this->same(true,$page['recordsFiltered']>0,'server search works');$this->same(true,count($page['data'])<=10,'pagination retained');
        $this->context(6);$summary=$this->table('arpa-submitted-appointments-summary');$sum=0;foreach($summary['data'] as $r)$sum+=(int)strip_tags($r['total_count']);$this->same((new ArpaWorkflowQueuePolicy($this->pdo))->actionableCount(self::actor(6)),$sum,'District ASC summary includes native plus historical cases');
        $this->context(8);$summary=$this->table('arpa-submitted-appointments-district-summary');$sum=0;foreach($summary['data'] as $r)$sum+=(int)strip_tags($r['total_count']);$this->same((new ArpaWorkflowQueuePolicy($this->pdo))->actionableCount(self::actor(8)),$sum,'National District summary reconciles');
        [$status,$body]=$this->route('GET','/hr/arpa-appointments/submitted',[],8);$this->same(200,$status,'existing National pending interface renders');$this->same(true,str_contains($body,'Historical Appointment'),'historical panel is integrated into original pending page');
        [$status]=$this->route('GET','/api/datatables/arpa-historical-approvals-pending?asc_id=10000000-0000-4000-8000-000000000002',[],1);$this->same(403,$status,'forged ASC filter denied by actual JSON endpoint');
        [$status]=$this->route('GET','/api/datatables/arpa-historical-approvals-pending',[],11);$this->same(403,$status,'Viewer cannot query historical queues');
        [$status]=$this->route('GET','/exports/arpa-historical-approvals-pending',[],1);$this->same(404,$status,'no unrequested export surface introduced');
    }
    private function routes():void
    {
        $id=$this->cases[0]['case']['case']['id'];$url='/hr/arpa-appointments/historical-approvals/'.$id;
        [$status,$html]=$this->route('GET',$url);$this->same(200,$status,'owner detail route works');foreach(['Original Workflow Status','Historical Approval Status','Operational Appointment Status','Historical Correction Provenance','Complete Case Event History','name="expected_version"','name="_csrf"','Submit Historical Appointment'] as $s)$this->same(true,str_contains($html,$s),'detail displays '.$s);
        $this->same(false,str_contains($html,'<script>alert(1)</script>'),'evidence escaped');
        foreach([2,10,11] as $actor){[$status]=$this->route('GET',$url,[],$actor);$this->same(403,$status,'unauthorized direct detail denied');[$status]=$this->route('POST',$url.'/submit',['expected_version'=>'0'],$actor);$this->same(403,$status,'unauthorized direct POST denied');}
        foreach(['invalid','missing-both'] as $csrf){[$status]=$this->route('POST',$url.'/submit',['expected_version'=>'0'],1,null,$csrf);$this->same(419,$status,'CSRF denied: '.$csrf);}
        [$status]=$this->route('POST',$url.'/submit',['expected_version'=>'0','officer_id'=>'forged']);$this->same(422,$status,'protected identities rejected');
        $this->context(1);$service=new ArpaHistoricalAppointmentApprovalService($this->pdo);$detail=$service->get($id,self::actor(1));
        [$status,$html]=$this->route('POST',$url.'/save',['expected_version'=>'0','expected_source_hash'=>$detail['current_source_hash'],'reason'=>'','evidence_reference'=>'Preserve entered evidence']);$this->same(422,$status,'required reason validation');$this->same(true,str_contains($html,'Preserve entered evidence'),'failed validation preserves entered fields');
        [$status]=$this->route('POST',$url.'/save',['expected_version'=>'0','expected_source_hash'=>$detail['current_source_hash'],'reason'=>'Updated synthetic reason','evidence_reference'=>'Updated synthetic letter']);$this->same(302,$status,'save delegates real service API');
        [$status]=$this->route('POST',$url.'/submit',['expected_version'=>'1']);$this->same(302,$status,'submit delegates real API');
        [$status]=$this->route('POST',$url.'/submit',['expected_version'=>'1']);$this->same(409,$status,'stale/duplicate POST cannot resubmit');
        [$status]=$this->route('POST',$url.'/review',['expected_version'=>'2','action'=>'VERIFY','reason'=>'ASC verification','evidence_reference'=>'ASC proof'],4,'ASC_SUBJECT_OFFICER');$this->same(302,$status,'ASC verification succeeds');
        [$status,$html]=$this->route('GET',$url,[],4,'ASC_ADMIN');$this->same(200,$status,'same actor can inspect through authorized changed context');$this->same(false,str_contains($html,'value="APPROVE"'),'maker-checker suppresses approval button');
        [$status]=$this->route('POST',$url.'/review',['expected_version'=>'3','action'=>'APPROVE','reason'=>'Forged approval','evidence_reference'=>'Proof'],4,'ASC_ADMIN');$this->same(403,$status,'backend rejects approval despite role switch');
        foreach([[5,'APPROVE'],[6,'VERIFY'],[7,'APPROVE'],[8,'VERIFY']] as $n=>[$actor,$action]){[$status]=$this->route('POST',$url.'/review',['expected_version'=>(string)(3+$n),'action'=>$action,'reason'=>'Authorized review','evidence_reference'=>'Recorded evidence'],$actor);$this->same(302,$status,'stage '.$action.' role '.$actor);}
        [$status]=$this->route('POST',$url.'/review',['expected_version'=>'7','action'=>'APPROVE','reason'=>'National approval','evidence_reference'=>'National proof'],9);$this->same(422,$status,'explicit final approval confirmation required');
        [$status]=$this->route('POST',$url.'/review',['expected_version'=>'7','action'=>'APPROVE','reason'=>'National approval','evidence_reference'=>'National proof','confirm_final'=>'1'],9);$this->same(302,$status,'final National approval succeeds');
        [$status,$html]=$this->route('GET',$url,[],9);$this->same(200,$status,'completed reviewer retains authorized detail access');$this->same(true,str_contains($html,'National approval')&&str_contains($html,'NATIONAL_APPROVED'),'complete event history rendered');
        $this->context(9);$this->same(true,$this->table('arpa-historical-approvals-completed')['recordsTotal']>=1,'own historical completed action is listed');
        [$status,$html]=$this->route('GET','/hr/arpa-appointments/approval',[],9);$this->same(200,$status,'existing completed-action interface renders');$this->same(true,str_contains($html,'arpa-historical-approvals-completed'),'historical completed table is integrated without replacing native table');
        $native=DataTableRegistry::definition('arpa-approval-verification');$this->same(true,in_array("r.record_origin='NATIVE'",$native['baseWhere'],true)&&in_array("w.record_origin='NATIVE'",$native['baseWhere'],true),'native-only completed filters remain unchanged');
        // Exercise a real return/edit/resubmit and a terminal rejection through POST.
        $id=$this->cases[3]['case']['case']['id'];$u='/hr/arpa-appointments/historical-approvals/'.$id;
        [$status]=$this->route('POST',$u.'/review',['expected_version'=>'3','action'=>'RETURN_FOR_CORRECTION','reason'=>'More evidence','evidence_reference'=>'Return note'],6);$this->same(302,$status,'District return succeeds');
        $this->context(1);$d=$service->get($id,self::actor(1));[$status]=$this->route('POST',$u.'/save',['expected_version'=>'4','expected_source_hash'=>$d['current_source_hash'],'reason'=>'Corrected evidence','evidence_reference'=>'Fresh letter']);$this->same(302,$status,'returned evidence edit succeeds');[$status]=$this->route('POST',$u.'/submit',['expected_version'=>'5']);$this->same(302,$status,'resubmission restarts ASC review');
        $id=$this->cases[2]['case']['case']['id'];$u='/hr/arpa-appointments/historical-approvals/'.$id;[$status]=$this->route('POST',$u.'/review',['expected_version'=>'2','action'=>'REJECT','reason'=>'Rejected after review','evidence_reference'=>'Rejection evidence'],5);$this->same(302,$status,'authorized rejection succeeds');[$status,$html]=$this->route('GET',$u,[],5);$this->same(200,$status,'rejecting actor can inspect completed rejection');$this->same(true,str_contains($html,'Rejected after review'),'rejection event remains available');
        $this->context(5);$this->same(true,$this->table('arpa-historical-approvals-completed')['recordsTotal']>=1,'rejection appears in own completed history');
    }
    private function staleSource():void
    {
        $s=$this->cases[8];$id=$s['case']['case']['id'];$u='/hr/arpa-appointments/historical-approvals/'.$id;$this->context(1);$service=new ArpaHistoricalAppointmentApprovalService($this->pdo);$original=$service->get($id,self::actor(1));
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('UPDATE arpa_division_appointment_request SET version=version+1 WHERE id=?')->execute([$s['request']]);
            // Separate route workers cannot see uncommitted fixture changes; exercise the shared service UI guards in this connection.
            $this->same(true,$service->interfaceGet($id,self::actor(1))['source_stale'],'source changes invalidate positive UI controls');
            $this->same(false,$service->interfaceGet($id,self::actor(1))['actions']['submit'],'stale source cannot be submitted through UI');
            try{$service->submit($id,0,self::actor(1));throw new RuntimeException('Stale source was accepted');}catch(DomainException $e){$this->same(409,$e->getCode(),'stale source also blocked by backend');}
            try{$service->updateDraft($id,['reason'=>'Retained reason','evidence_reference'=>'Retained proof'],0,$original['current_source_hash'],self::actor(1));throw new RuntimeException('Stale acknowledgement was accepted');}catch(DomainException $e){$this->same(409,$e->getCode(),'stale baseline acknowledgement rejected');}
        }finally{$this->pdo->rollBack();}
        $before=$this->pdo->query('SELECT * FROM arpa_appointment_historical_approval_event ORDER BY id')->fetchAll();$beforeCase=$service->get($id,self::actor(1))['case'];
        [$status]=$this->route('GET',$u);$this->same(200,$status,'draft can still be viewed after rejected stale operation');$this->same($before,$this->pdo->query('SELECT * FROM arpa_appointment_historical_approval_event ORDER BY id')->fetchAll(),'GET does not insert or change case events');$this->same($beforeCase,$service->get($id,self::actor(1))['case'],'GET does not mutate case/version');
    }
    private function staleDraftQueues():void
    {
        $source=$this->cases[8];$id=$source['case']['case']['id'];$service=new ArpaHistoricalAppointmentApprovalService($this->pdo);$read=new ArpaHistoricalApprovalReadService($this->pdo);
        $operational=$this->call($this->fixture,'operational');
        $parity=function(int $actor,bool $visible,bool $retire,?string $role=null)use($id,$service,$read):void{
            $this->context($actor,$role);$q=$read->query(self::actor($actor));$s=$this->pdo->prepare($q['sql']);$s->execute($q['params']);$rows=$s->fetchAll();$ids=array_column($rows,'case_id');
            $this->same($visible,in_array($id,$ids,true),'pending visibility for actor/context '.$actor.' '.$role);
            $this->same(count($rows),$read->count(self::actor($actor)),'shared pending count matches exact population');
            $table=$this->table('arpa-historical-approvals-pending',[],['length'=>100]);$this->same(count($rows),$table['recordsTotal'],'DataTable total includes the same stale-retirement population');$this->same(count($rows),$table['recordsFiltered'],'unfiltered DataTable count agrees');
            $total=(new ArpaWorkflowQueuePolicy($this->pdo))->actionableCount(self::actor($actor));$this->same($this->table('arpa-submitted-appointments')['recordsTotal']+count($rows),$total,'dashboard count equals unchanged native queue plus historical cases');
            // Check the shared summary source without granting ASC users a District/National drill-down route.
            [$summary]=$this->callRegistrySummary();$where=$summary['baseWhere']??[];$sql=($summary['with']??'').' SELECT COUNT(*) FROM '.$summary['from'].($where===[]?'':' WHERE '.implode(' AND ',$where));$sum=$this->pdo->prepare($sql);$sum->execute($summary['baseParams']??[]);$this->same($total,(int)$sum->fetchColumn(),'hierarchy summary source reconciles with pending list and dashboard');
            try{$detail=$service->interfaceGet($id,self::actor($actor));$canRetire=$detail['actions']['reject'];}catch(DomainException $e){$this->same(403,$e->getCode(),'unauthorized direct detail fails closed');$canRetire=false;}
            $this->same($retire,$canRetire,'pending retirement matches actual authorized detail action');
            if($retire){$row=$rows[array_search($id,$ids,true)];$this->same(true,str_contains($row['entry_type'],'Administrative resolution required'),'stale pending case clearly labelled');$this->same(null,$detail['actions']['primary'],'stale retirement cannot verify/approve');$this->same(false,$detail['actions']['submit'],'stale retirement cannot submit');$this->same(false,$detail['actions']['return'],'only governed rejection available for stale draft');
                $this->same(true,stripos(json_encode($table),'Administrative resolution required')!==false,'rendered DataTable labels administrative resolution');$profile=$read->profileRows([$detail['case']['appointment_id']],self::actor($actor));$this->same(1,(int)$profile[$detail['case']['appointment_id']][0]['can_read_case'],'profile case link agrees with authorized stale detail access');
                ob_start();require BASE_PATH.'/app/Views/arpa_appointments/historical_approval_detail.php';$html=ob_get_clean();$this->same(true,str_contains($html,'Administrative resolution required (stale source)'),'detail explains required administrative resolution');$this->same(false,str_contains($html,'value="VERIFY"')||str_contains($html,'value="APPROVE"')||str_contains($html,'Submit Historical Appointment'),'no positive stale controls rendered');
            }
        };
        $this->pdo->beginTransaction();
        try{
            $native='80000000-0000-4000-8000-000000000001';$n=$this->cases[0];
            $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,created_by) VALUES(?,'NATIVE','APPOINTMENT',?,'PERMANENT',?,?,?,'ASC_VERIFIED',?)")->execute([$native,$n['officer'],self::ASC,$n['division'],'2020-01-01',self::actor(1)]);
            foreach([[4,'VERIFY','NATIVE'],[4,'VERIFY','LEGACY_IMPORT'],[5,'APPROVE','NATIVE'],[5,'APPROVE','LEGACY_IMPORT']] as [$actor,$action,$origin])$this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,action_at,record_origin,previous_status,new_status) VALUES(?,?,'ASC',?,NOW(),?,'SUBMITTED','ASC_VERIFIED')")->execute([$native,$action,self::actor($actor),$origin]);
            foreach([[4,'ASC_SUBJECT_OFFICER'],[5,'ASC_ADMIN']] as [$actor,$role]){$this->context($actor,$role);$this->same(1,$this->table('arpa-approval-verification')['recordsTotal'],'native completed action retained and imported action excluded for '.$role);}
            $parity(5,false,false);$parity(1,true,false); // Current DRAFT belongs to ASC preparation, not retirement.
            $this->pdo->prepare('UPDATE arpa_division_appointment_request SET version=version+1 WHERE id=?')->execute([$source['request']]);
            $parity(5,true,true);$parity(4,true,true,'ASC_ADMIN');$parity(4,true,false,'ASC_SUBJECT_OFFICER');
            foreach([2,6,7,8,9,10] as $actor)$parity($actor,false,false);
            $this->context(11);$this->same(0,$read->count(self::actor(11)),'Viewer has no pending count');try{$service->get($id,self::actor(11));throw new RuntimeException('Viewer read stale draft');}catch(DomainException $e){$this->same(403,$e->getCode(),'Viewer direct detail denied');}
            // The very same administrator identity in a different ASC cannot discover or retire this case.
            $this->pdo->exec("UPDATE user_account_scope SET location_id='10000000-0000-4000-8000-000000000002' WHERE id='15000000-0000-4000-8000-000000000006'");$parity(5,false,false);
            $this->pdo->exec("UPDATE user_account_scope SET location_id='".self::ASC."' WHERE id='15000000-0000-4000-8000-000000000006'");$parity(5,true,true);
            $this->pdo->exec("DELETE FROM application_role_permission WHERE role_id='12000000-0000-4000-8000-000000000002' AND permission_id='13000000-0000-4000-8000-000000000010'");$parity(5,false,false);
            $this->pdo->exec("INSERT INTO application_role_permission VALUES('12000000-0000-4000-8000-000000000002','13000000-0000-4000-8000-000000000010')");
            $this->pdo->exec("INSERT INTO user_account_role(id,user_id,role_id,active,approval_status,effective_from) VALUES('queue-maker-admin','".self::actor(1)."','12000000-0000-4000-8000-000000000002',1,'APPROVED',CURRENT_DATE());
                INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,active,approval_status,effective_from) VALUES('queue-maker-scope','".self::actor(1)."','queue-maker-admin','ASC','EXACT','".self::ASC."',1,'APPROVED',CURRENT_DATE())");
            $parity(1,false,false,'ASC_ADMIN'); // Switching roles cannot retire one's own draft/correction.
            $this->context(5);$before=$service->get($id,self::actor(5))['case'];
            try{$service->review($id,'REJECT',99,self::actor(5),'Retire stale case','Synthetic evidence');throw new RuntimeException('Stale version accepted');}catch(DomainException $e){$this->same(409,$e->getCode(),'retirement retains optimistic version guard');}
            foreach(['VERIFY','APPROVE'] as $action)try{$service->review($id,$action,0,self::actor(5),'Review','Synthetic evidence');throw new RuntimeException('Positive stale action accepted');}catch(DomainException $e){$this->same(true,in_array($e->getCode(),[403,409],true),'backend forbids positive stale action');}
            $this->same($before,$service->get($id,self::actor(5))['case'],'denied actions do not change case');
            // Restore only the synthetic version inside this transaction, then exercise real return processing.
            $this->pdo->prepare('UPDATE arpa_division_appointment_request SET version=version-1 WHERE id=?')->execute([$source['request']]);$this->context(1);$c=$service->submit($id,0,self::actor(1));
            $this->context(4);$c=$service->review($id,'VERIFY',(int)$c['case']['version'],self::actor(4),'Verified','Synthetic evidence');$this->context(5);$c=$service->review($id,'RETURN_FOR_CORRECTION',(int)$c['case']['version'],self::actor(5),'Return','Synthetic evidence');
            $parity(5,false,false);$parity(1,true,false); // Current RETURNED remains ASC-editable, not admin-retirable.
            $this->pdo->prepare('UPDATE arpa_division_appointment_request SET version=version+1 WHERE id=?')->execute([$source['request']]);$parity(5,true,true);$parity(4,false,false,'ASC_ADMIN'); // Its verifier cannot retire it by role switching.
            $this->context(5);$c=$service->review($id,'REJECT',(int)$c['case']['version'],self::actor(5),'Retire obsolete returned case','Synthetic evidence');$this->same('REJECTED',$c['case']['workflow_status'],'existing governed rejection retires stale case');$parity(5,false,false);$parity(1,false,false);
            $completed=$this->table('arpa-historical-approvals-completed',[],['length'=>100]);$this->same(false,str_contains(json_encode($completed),'Administrative resolution required'),'completed action labels/selection are not changed by retirement discovery');
            $this->context(5);$q=$read->query(self::actor(5));$s=$this->pdo->prepare($q['sql']);$s->execute($q['params']);$this->same(false,in_array($this->cases[0]['case']['case']['id'],array_column($s->fetchAll(),'case_id'),true),'National-approved terminal case stays out of pending queue');
            $this->same(1,$this->table('arpa-submitted-appointments')['recordsTotal'],'native pending ASC approval remains unchanged after historical retirement');
            foreach([[4,'ASC_SUBJECT_OFFICER'],[5,'ASC_ADMIN']] as [$actor,$role]){$this->context($actor,$role);$this->same(1,$this->table('arpa-approval-verification')['recordsTotal'],'native completed filters unchanged after historical retirement for '.$role);}
        }finally{$this->pdo->rollBack();$this->context(1);}
        $this->same($operational,$this->call($this->fixture,'operational'),'queue/detail inspection and rolled-back synthetic transitions preserve all operational records');
    }
    private function profile():void
    {
        $this->context(1);$s=$this->cases[0];$service=new App\Services\OfficerProfileService($this->pdo);$r=$this->call($service,'operationalAppointments',$s['officer'],[self::ASC]);$rows=$r[0];$this->same('CREATED',$rows[0]['workflow_status'],'profile retains original imported workflow status');$this->same('NATIONAL_APPROVED',$rows[0]['historical_approvals'][0]['workflow_status'],'profile has separate historical status');
        $line='';foreach(file(BASE_PATH.'/app/Views/officers/show.php') as $l)if(str_starts_with($l,'<?php $renderOperational='))$line=$l;
$dash='—';$canAdminDateCorrect=false;eval('use App\\Core\\DataTableFormat; '.substr(trim($line),5,-2));ob_start();$renderOperational('Synthetic Profile',$rows,[]);$html=ob_get_clean();foreach(['Original Workflow Status','Historical Approval Status','Operational Appointment Status','Historical Correction Provenance','View Case','National Approved'] as $text)$this->same(true,str_contains($html,$text),'profile renders '.$text);
        $this->context(11);$rows=$this->call($service,'operationalAppointments',$s['officer'],[self::ASC])[0];$this->same(0,(int)$rows[0]['historical_approvals'][0]['can_read_case'],'Viewer can see profile status but cannot open restricted case evidence');
    }
    private function missingSchema():void
    {
        $id=$this->cases[0]['case']['case']['id'];$this->pdo->exec('RENAME TABLE arpa_appointment_historical_approval_event TO fixture_hidden_event,arpa_appointment_historical_approval_case TO fixture_hidden_case');
        try{[$status,$body]=$this->route('GET','/hr/arpa-appointments/historical-approvals/'.$id);$this->same(503,$status,'direct route without migration fails closed');$this->same(false,str_contains($body,'SQLSTATE')||str_contains($body,'Stack trace'),'no SQL or stack trace exposed');[$status]=$this->route('POST','/hr/arpa-appointments/historical-approvals/'.$id.'/submit',['expected_version'=>'8']);$this->same(503,$status,'valid-CSRF POST without schema cannot write');$this->context(1);$this->same(0,(new ArpaHistoricalApprovalReadService($this->pdo))->count(self::actor(1)),'schema-absent dashboard remains compatible');$this->same(0,count($this->call(new App\Services\OfficerProfileService($this->pdo),'operationalAppointments',$this->cases[0]['officer'],[self::ASC])[0][0]['historical_approvals']),'schema-absent profile remains compatible');[$status,$body]=$this->route('GET','/hr/arpa-appointments/submitted',[],8);$this->same(200,$status,'existing queue page works without migration 067');$this->same(false,str_contains($body,'arpa-historical-approvals-pending'),'schema-absent page does not render an unusable historical table');}
        finally{$this->pdo->exec('RENAME TABLE fixture_hidden_event TO arpa_appointment_historical_approval_event,fixture_hidden_case TO arpa_appointment_historical_approval_case');}
    }
    private function table(string $key,array $context=[],array $input=[]):array{$d=DataTableRegistry::definition($key,$context);$this->same(true,!isset($d['authorize'])||($d['authorize'])(),'table authorizes selected role');return(new DataTableQuery($this->pdo,$d,new DataTableRequest($input)))->response();}
    private function callRegistrySummary():array{return (new ReflectionMethod(DataTableRegistry::class,'arpaAppointmentSummarySource'))->invoke(null,'submitted');}
    private function route(string $method,string $path,array $post=[],int $actor=1,?string $role=null,string $csrf='valid'):array
    {
        $out=$this->child(['--route',$method,$path,base64_encode(json_encode($post)),(string)$actor,$role??'',$csrf]);if(!preg_match('/\nROUTED_STATUS=(\d+)$/',$out,$m))throw new RuntimeException('Missing routed status');return [(int)$m[1],substr($out,0,-strlen($m[0]))];
    }
    private function securityHeaders():string{return $this->child(['--security']);}
    private function child(array $args):string{$pipes=[];$p=proc_open(array_merge([PHP_BINARY,'-d','xdebug.mode=off','-d','display_errors=0',__FILE__,$this->db],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);if($exit!==0)throw new RuntimeException('Isolated request failed: '.$err);return $out;}
    public static function childMain(array $args):void
    {
        $test=new self();$test->pdo=self::connect($args[1]);(new ReflectionProperty(Database::class,'pdo'))->setValue(null,$test->pdo);$test->fixture=new ArpaHistoricalAppointmentApprovalIsolatedTest();(new ReflectionProperty($test->fixture,'pdo'))->setValue($test->fixture,$test->pdo);
        if($args[2]==='--security'){$test->context(1);$source=file_get_contents(__DIR__.'/SecurityHeadersTest.php');$source=str_replace("require dirname(__DIR__) . '/bootstrap.php';","require_once BASE_PATH.'/bootstrap.php';",$source);eval(substr($source,5));return;}
        $test->context((int)$args[6],$args[7]!==''?$args[7]:null);$_SESSION['_csrf']='ui-fixture-csrf';$_POST=json_decode(base64_decode($args[5]),true);$_POST['_csrf']=$args[8]==='valid'?'ui-fixture-csrf':'invalid';if($args[8]==='missing-both'){unset($_SESSION['_csrf'],$_POST['_csrf']);}
        $_GET=[];parse_str(parse_url($args[4],PHP_URL_QUERY)??'',$_GET);$_SERVER['REQUEST_METHOD']=$args[3];$_SERVER['REQUEST_URI']=$args[4];http_response_code(200);register_shutdown_function(static function(){echo "\nROUTED_STATUS=".http_response_code();});$router=new App\Core\Router();require BASE_PATH.'/routes/web.php';$router->dispatch($args[3],$args[4]);
    }
    private function context(int $n,?string $role=null):void{$this->call($this->fixture,'context',self::actor($n),$role);}
    private static function actor(int $n):string{return '00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT);}
    private function call(object $object,string $method,mixed ...$args):mixed{return(new ReflectionMethod($object,$method))->invoke($object,...$args);}
    private static function connect(string $db):PDO{if(!preg_match('/^dems_test_hist_ui_[a-f0-9]{10}$/D',$db))throw new RuntimeException('Unsafe UI fixture database.');$c=config('database');return new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port'],$db),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);}
    private function same(mixed $e,mixed $a,string $m):void{$this->assertions++;if($e!==$a)throw new RuntimeException($m.'; expected '.var_export($e,true).', got '.var_export($a,true));}
}
if(!defined('ARPA_HISTORICAL_UI_FIXTURE_ONLY'))try{if(isset($argv[2]))ArpaHistoricalApprovalUiIsolatedTest::childMain($argv);else(new ArpaHistoricalApprovalUiIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
