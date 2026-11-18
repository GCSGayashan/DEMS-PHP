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
    private array $embeddedSources=[];
    private array $verifyCases=[];
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
            $this->pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/068_arpa_historical_verify_optional_evidence.sql'));
            $this->pdo->exec("INSERT INTO designation VALUES('historical-test-arpa','ARPA_OFFICER');CREATE TABLE IF NOT EXISTS arpa_appointment_workflow_action(id BIGINT AUTO_INCREMENT PRIMARY KEY,request_id CHAR(36),action VARCHAR(50),stage VARCHAR(50),user_id CHAR(36),action_at DATETIME NULL,record_origin VARCHAR(40),previous_status VARCHAR(50),new_status VARCHAR(50),comments TEXT,timestamp_provenance VARCHAR(80)) ENGINE=InnoDB");
            $this->fixture=new ArpaHistoricalAppointmentApprovalIsolatedTest();(new ReflectionProperty($this->fixture,'pdo'))->setValue($this->fixture,$this->pdo);
            for($i=0;$i<9;$i++)$this->cases[$i]=$this->call($this->fixture,'source');
            for($i=0;$i<3;$i++)$this->embeddedSources[$i]=$this->call($this->fixture,'source','PERMANENT',null,'2020-01-01','2024-12-31');
            foreach([4,6,8] as $reviewer)$this->verifyCases[$reviewer]=$this->call($this->fixture,'source');
            $this->schema();$this->requestSchema();$service=new ArpaHistoricalAppointmentApprovalService($this->pdo);
            foreach($this->cases as $i=>&$s){$this->context(1);$s['case']=$service->register($s['correction'],['reason'=>'Synthetic UI baseline','evidence_reference'=>'Synthetic letter <script>alert(1)</script>'],self::actor(1));if($i===0||$i===8)continue;$s['case']=$service->submit($s['case']['case']['id'],0,self::actor(1));foreach([[4,'VERIFY'],[5,'APPROVE'],[6,'VERIFY'],[7,'APPROVE'],[8,'VERIFY']] as $n=>[$actor,$action]){if($n>=$i-1)break;$this->context($actor);$s['case']=$service->review($s['case']['case']['id'],$action,(int)$s['case']['case']['version'],self::actor($actor),'Synthetic stage review','Synthetic evidence');}}
            unset($s);
            foreach([4=>'ASC',6=>'DISTRICT',8=>'NATIONAL'] as $reviewer=>$stage){
                $source=$this->verifyCases[$reviewer];$this->context(1);$c=$service->register($source['correction'],['reason'=>'Optional verification fixture','evidence_reference'=>'Synthetic original evidence'],self::actor(1));$c=$service->submit($c['case']['id'],0,self::actor(1));
                foreach([[4,'VERIFY'],[5,'APPROVE'],[6,'VERIFY'],[7,'APPROVE']] as [$actor,$action]){if($actor>=$reviewer)break;$this->context($actor);$c=$service->review($c['case']['id'],$action,(int)$c['case']['version'],self::actor($actor),'Authorized setup review','Synthetic setup evidence');}
                $this->verifyCases[$reviewer]=[$c,$reviewer,$stage];
            }
            $this->context(1);$sourceState=$this->call($this->fixture,'operational');
            $this->nextActionDisplay();$this->queues();$this->combinedQueues();$this->optionalVerify();$this->routes();$this->staleSource();$this->staleDraftQueues();$this->profile();$this->profilePeriods();$this->missingSchema();
            $this->embeddedRequest();
            $this->requestCaseSelection();$this->stageHistoryDisplay();
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
    private function requestSchema():void
    {
        $this->pdo->exec("ALTER TABLE officer ADD class_id CHAR(36),ADD officer_status_id CHAR(36),ADD operational_status VARCHAR(30) DEFAULT 'ACTIVE',ADD approval_status VARCHAR(30) DEFAULT 'APPROVED';
            ALTER TABLE designation ADD name_en VARCHAR(255),ADD active TINYINT DEFAULT 1,ADD approval_status VARCHAR(30) DEFAULT 'APPROVED';
            ALTER TABLE location ADD official_code VARCHAR(100);
            CREATE TABLE officer_class(id CHAR(36) PRIMARY KEY,name_en VARCHAR(255)) ENGINE=InnoDB;
            CREATE TABLE officer_status(id CHAR(36) PRIMARY KEY,name_en VARCHAR(255)) ENGINE=InnoDB;
            CREATE TABLE arpa_appointment_stage_review(id CHAR(36),entity_type VARCHAR(30),request_id CHAR(36),review_stage VARCHAR(30),updated_by CHAR(36)) ENGINE=InnoDB;");
    }
    private function nextActionDisplay():void
    {
        $service=new ArpaHistoricalAppointmentApprovalService($this->pdo);
        $render=static function(array $detail):string{
            $request=['officer_number'=>'UAT','officer_name'=>'Synthetic Officer'];$status='ASC_APPROVED';$administrativelyDeleted=false;$historicalPanel=['detail'=>$detail];
            ob_start();try{require BASE_PATH.'/app/Views/arpa_appointments/historical_request_panel.php';return (string)ob_get_contents();}finally{ob_end_clean();}
        };
        $steps=[
            [0,1,'SUBMIT','ASC','Submit Historical Appointment','Assigned ASC Subject Officer'],
            [1,4,'VERIFY','ASC','ASC Verify Historical Appointment','ASC Subject Officer'],
            [2,5,'APPROVE','ASC','ASC Approve Historical Appointment','ASC Administrator'],
            [3,6,'VERIFY','DISTRICT','District Verify Historical Appointment','District Subject Officer'],
            [4,7,'APPROVE','DISTRICT','District Approve Historical Appointment','District Administrator'],
            [5,8,'VERIFY','NATIONAL','National Verify Historical Appointment','National Subject Officer'],
            [6,9,'APPROVE','NATIONAL','National Approve Historical Appointment','National Administrator'],
        ];
        foreach($steps as [$index,$actor,$action,$stage,$label,$role]){
            $this->context($actor);$detail=$service->interfaceGet($this->cases[$index]['case']['case']['id'],self::actor($actor));$next=$detail['next_action'];
            foreach(['action'=>$action,'stage'=>$stage,'label'=>$label,'role'=>$role,'authorized'=>true] as $key=>$expected)$this->same($expected,$next[$key],'next '.$key.' for '.$detail['case']['workflow_status']);
            $html=$render($detail);foreach(['Next Workflow Action',$detail['case']['workflow_status'],$label,$role,'Authorized to perform this action.','value="'.$action.'">'.$label.'</button>'] as $text)$this->same(true,str_contains($html,$text),'authorized panel renders '.$text);
        }
        // The original imported status is deliberately ASC_APPROVED, even when the historical case is Draft.
        $this->context(1);$id=$this->cases[0]['case']['case']['id'];$draft=$service->interfaceGet($id,self::actor(1));
        $this->same('SUBMIT',$draft['next_action']['action'],'original imported approval cannot override historical Draft next action');
        $this->context(4,'ASC_ADMIN');$detail=$service->interfaceGet($this->cases[2]['case']['case']['id'],self::actor(4));
        $this->same('ASC Approve Historical Appointment',$detail['next_action']['label'],'maker-checker does not conceal the responsible next stage');
        $this->same(false,$detail['next_action']['authorized'],'same verifier cannot approve');$html=$render($detail);
        $this->same(true,str_contains($html,'Not authorized to perform this action'),'restricted current user is identified');$this->same(false,str_contains($html,'value="APPROVE"'),'no unauthorized approval button');
        [$status,$html]=$this->route('GET','/hr/arpa-appointments/requests/division/'.$this->cases[1]['request'],[],11);
        $this->same(200,$status,'viewer retains original request visibility');$this->same(false,str_contains($html,'data-historical-next-action'),'inaccessible historical case exposes no next-action information');
        $this->context(1);$this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('UPDATE arpa_division_appointment_request SET version=version+1 WHERE id=?')->execute([$this->cases[0]['request']]);
            $stale=$service->interfaceGet($id,self::actor(1));$this->same(false,$stale['next_action']['authorized'],'stale baseline blocks next action');
            $this->same(false,str_contains($render($stale),'value="SUBMIT"'),'stale case has no submit button');
        }finally{$this->pdo->rollBack();}
        $this->pdo->beginTransaction();
        try{
            foreach(['RETURNED'=>['Resubmit Historical Appointment','SUBMIT',true],'NATIONAL_APPROVED'=>['Historical Approval Completed',null,false],'REJECTED'=>['Historical Approval Rejected. No further workflow action.',null,false]] as $status=>[$label,$action,$authorized]){
                $this->pdo->prepare('UPDATE arpa_appointment_historical_approval_case SET workflow_status=? WHERE id=?')->execute([$status,$id]);
                $detail=$service->interfaceGet($id,self::actor(1));$next=$detail['next_action'];
                $this->same($label,$next['label'],'correct existing state label for '.$status);$this->same($action,$next['action'],'no invented transition for '.$status);$this->same($authorized,$next['authorized'],'authorization for '.$status);
                $html=$render($detail);$this->same(true,str_contains($html,$label),'state label displayed for '.$status);
                $this->same($authorized,str_contains($html,'value="SUBMIT"'),'submit control follows existing rules for '.$status);
                if($action===null)$this->same(false,str_contains($html,'/attest'),'terminal state has no action form');
            }
        }finally{$this->pdo->rollBack();$this->context(1);}
    }
    private function embeddedRequest():void
    {
        $service=new ArpaHistoricalAppointmentApprovalService($this->pdo);$this->context(1);
        $source=$this->embeddedSources[0];$page='/hr/arpa-appointments/requests/division/'.$source['request'];
        [$status,$html]=$this->route('GET',$page);$this->same(200,$status,'unregistered imported request remains viewable');
        $this->same(true,str_contains($html,'original request workflow'),'unregistered imported record uses original workflow guidance');
        $this->same(false,str_contains($html,'/attest'),'unregistered case cannot be acted on');
        $c=$service->register($source['correction'],['reason'=>'Reviewed complete imported period','evidence_reference'=>'Actual administrative registration fixture'],self::actor(1));
        $id=$c['case']['id'];$url='/hr/arpa-appointments/historical-approvals/'.$id.'/attest';$hash=$c['case']['baseline_hash'];
        $post=['expected_version'=>'0','baseline_hash'=>$hash,'confirm_records'=>'1','action'=>'SUBMIT'];
        [$status,$html]=$this->route('GET',$page);$this->same(200,$status,'imported division detail integrates historical case');
        foreach(['Imported Historical Appointment','2020-01-01','2024-12-31','Original Workflow Status','Separate Historical Case (Audit Only)'] as $text)$this->same(true,str_contains($html,$text),'embedded detail shows '.$text);
        foreach(['/workflow/division/','Native Appointment','type="file"','name="evidence_reference"'] as $text)$this->same(false,str_contains($html,$text),'embedded historical path omits '.$text);
        foreach([2,10,11] as $actor){[$status]=$this->route('POST',$url,$post,$actor);$this->same(403,$status,'attestation respects geographic scope/role '.$actor);}
        foreach(['invalid','missing-both'] as $csrf){[$status]=$this->route('POST',$url,$post,1,null,$csrf);$this->same(419,$status,'attestation retains CSRF protection');}
        [$status]=$this->route('GET',$url);$this->same(404,$status,'no GET attestation mutation route');
        $missing=$post;unset($missing['confirm_records']);[$status]=$this->route('POST',$url,$missing);$this->same(422,$status,'explicit record confirmation mandatory');
        [$status]=$this->route('POST',$url,array_replace($post,['baseline_hash'=>str_repeat('0',64)]));$this->same(409,$status,'review must match displayed baseline');
        [$status]=$this->route('POST',$url,$post+['evidence_reference'=>'fabricated']);$this->same(422,$status,'attestation cannot silently accept evidence/identity fields');
        [$status]=$this->route('POST',$url,$post);$this->same(302,$status,'owner submits from embedded page');
        [$status]=$this->route('POST',$url,$post);$this->same(409,$status,'optimistic locking prevents repeated submission');
        $version=1;
        foreach([[4,'VERIFY','ASC_SUBJECT_OFFICER'],[5,'APPROVE',null],[6,'VERIFY',null],[7,'APPROVE',null],[8,'VERIFY',null],[9,'APPROVE',null]] as [$actor,$action,$role]){
            [$status,$html]=$this->route('GET',$page,[],$actor,$role);$this->same(200,$status,'authorized stage reviewer sees original page');
            $this->same(false,str_contains($html,'value="'.$action.'"'),'separate case stage does not authorize an original request action');
            $input=['expected_version'=>(string)$version,'baseline_hash'=>$hash,'confirm_records'=>'1','action'=>$action,'reason'=>'Reviewed existing appointment and closure'];
            if($actor===9){[$status]=$this->route('POST',$url,$input,$actor);$this->same(422,$status,'National final confirmation retained');$input['confirm_final']='1';}
            [$status]=$this->route('POST',$url,$input,$actor,$role);$this->same(302,$status,'existing-record attestation action '.$action.' actor '.$actor);$version++;
            if($actor===4){[$status,$html]=$this->route('GET',$page,[],4,'ASC_ADMIN');$this->same(false,str_contains($html,'value="APPROVE"'),'maker-checker hides own approval');
                [$status]=$this->route('POST',$url,array_replace($input,['expected_version'=>'2','action'=>'APPROVE']),4,'ASC_ADMIN');$this->same(403,$status,'maker-checker enforced on direct POST');}
        }
        $this->context(1);$done=$service->get($id,self::actor(1));$this->same('NATIONAL_APPROVED',$done['case']['workflow_status'],'complete existing sequence reaches National approval');
        $this->same('2024-12-31',$done['baseline']['closures'][0]['effective_to'],'one case covers actual closure');
        $this->same(1,(int)$this->pdo->query("SELECT COUNT(*) FROM arpa_appointment_historical_approval_case WHERE appointment_id='".$source['appointment']."'")->fetchColumn(),'no separate closure approval case');
        foreach(array_slice($done['events'],1) as $event){$audit=json_decode($event['after_json'],true);$this->same('EXISTING_RECORD_ATTESTATION',$audit['existing_record_attestation']['mode'],'audit explicitly distinguishes attestation from documentary evidence');$this->same($hash,$audit['existing_record_attestation']['baseline_hash'],'audit identifies reviewed complete baseline');$this->same(true,$event['user_id']!==''&&$event['action_at']!=='','audit records reviewer and system timestamp');}
        $this->same('CREATED',$this->pdo->query("SELECT workflow_status FROM arpa_division_appointment_request WHERE id='".$source['request']."'")->fetchColumn(),'original imported workflow status unchanged');
        [$status,$html]=$this->route('GET',$page,[],9);$this->same(200,$status,'terminal historical case stays viewable');$this->same(false,str_contains($html,'/attest'),'terminal case shows no action form');

        // Closure changes invalidate the same whole-period baseline; no date is silently refreshed.
        $stale=$this->embeddedSources[1];$this->context(1);$c=$service->register($stale['correction'],['reason'=>'Closure baseline','evidence_reference'=>'Administrative evidence'],self::actor(1));
        $this->pdo->beginTransaction();
        try{$this->pdo->prepare("UPDATE arpa_division_appointment_closure SET effective_to='2024-11-30' WHERE appointment_id=?")->execute([$stale['appointment']]);
            try{$service->submitExistingRecordAttestation($c['case']['id'],0,self::actor(1),true,$c['case']['baseline_hash']);throw new RuntimeException('Changed closure accepted');}catch(DomainException $e){$this->same(409,$e->getCode(),'actual closure is protected by source-baseline lock');}
        }finally{$this->pdo->rollBack();}
        // The original evidence route still requires real non-empty evidence.
        $c=$service->submitExistingRecordAttestation($c['case']['id'],0,self::actor(1),true,$c['case']['baseline_hash']);$this->context(4);
        $verified=$service->review($c['case']['id'],'VERIFY',1,self::actor(4),'Review','');$last=end($verified['events']);$this->same('ASC_VERIFIED',$verified['case']['workflow_status'],'VERIFY accepts an omitted evidence reference');$this->same('',$last['evidence_reference'],'no evidence placeholder is fabricated');$this->same('Review',$last['reason'],'supplied verification reason is preserved');
        // Return and rejection use the same governed transition implementation, not original workflows.
        $this->context(1);$s=$this->embeddedSources[2];$c=$service->register($s['correction'],['reason'=>'Whole period review','evidence_reference'=>'Administrative registration evidence'],self::actor(1));
        $id=$c['case']['id'];$hash=$c['case']['baseline_hash'];$url='/hr/arpa-appointments/historical-approvals/'.$id.'/attest';
        foreach([[1,'SUBMIT'],[4,'VERIFY'],[5,'RETURN_FOR_CORRECTION'],[1,'SUBMIT'],[4,'VERIFY'],[5,'REJECT']] as $version=>[$actor,$action]){
            $input=['expected_version'=>(string)$version,'baseline_hash'=>$hash,'confirm_records'=>'1','action'=>$action,'reason'=>'Explicit review decision'];
            if($action==='VERIFY'){
                $missing=$input;unset($missing['confirm_records']);[$status]=$this->route('POST',$url,$missing,$actor);$this->same(422,$status,'review confirmation cannot be omitted');
                [$status]=$this->route('POST',$url,array_replace($input,['reason'=>[]]),$actor);$this->same(422,$status,'verification reason must still be valid text');$input['reason']='';
            }
            [$status]=$this->route('POST',$url,$input,$actor);$this->same(302,$status,'attestation supports governed '.$action);
        }
        $this->context(1);$this->same('REJECTED',$service->get($id,self::actor(1))['case']['workflow_status'],'attested rejection remains terminal');
        $this->context(1);
    }
    private function requestCaseSelection():void
    {
        $this->context(1);$source=$this->embeddedSources[0];$other=$this->embeddedSources[1];$read=new ArpaHistoricalApprovalReadService($this->pdo);
        $original=$read->requestCase($source['appointment'],$source['request'],self::actor(1));$id=$original['case']['id'];
        $this->same(null,$read->requestCase($other['appointment'],$source['request'],self::actor(1)),'appointment and request must both match');
        $render=function()use($source,$read):string{
            $request=(new App\Services\ArpaAppointmentReadService($this->pdo))->workflowRequestDetail('division',$source['request']);$status=$request['workflow_status'];$administrativelyDeleted=false;
            $historicalPanel=['detail'=>$read->requestCase($source['appointment'],$source['request'],(string)Auth::user()['id']),'message'=>'Historical approval is unavailable in your current Working Context.'];
            ob_start();try{require BASE_PATH.'/app/Views/arpa_appointments/historical_request_panel.php';return (string)ob_get_contents();}finally{ob_end_clean();}
        };
        $panel=static function(string $html):string{if(!preg_match('~<section class="form-section"><h2 class="h5">.*?</section>~s',$html,$m))throw new RuntimeException('Missing imported panel');return $m[0];};
        $clone=function(string $request,string $correction,string $status)use($original):string{
            $row=$original['case'];unset($row['active_appointment_id']);$row['id']=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();$row['request_id']=$request;$row['source_correction_id']=$correction;$row['workflow_status']=$status;$row['reason']='Sensitive fixture case '.$row['id'];$row['baseline_hash']=hash('sha256',$row['id']);
            $this->pdo->prepare('INSERT INTO arpa_appointment_historical_approval_case ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return $row['id'];
        };
        $this->pdo->beginTransaction();
        try{
            $foreign=$clone($other['request'],$other['correction'],'DRAFT');
            $this->same($id,$read->requestCase($source['appointment'],$source['request'],self::actor(1))['case']['id'],'active case for another request cannot displace exact terminal match');
            $_SERVER['REQUEST_URI']='/hr/arpa-appointments/requests/division/'.$source['request'];
            ob_start();try{(new App\Controllers\ArpaAppointmentController())->requestDetail('division',$source['request']);$html=$panel((string)ob_get_contents());}finally{ob_end_clean();}
            $this->same(true,str_contains($html,$original['case']['baseline_hash']),'controller shows exact matching case baseline');$this->same(false,str_contains($html,$foreign),'controller never exposes mismatched case');
            $this->pdo->prepare("UPDATE arpa_appointment_historical_approval_case SET workflow_status='REJECTED' WHERE id=?")->execute([$foreign]);
            $active=$clone($source['request'],$this->embeddedSources[2]['correction'],'DRAFT');
            $this->same($active,$read->requestCase($source['appointment'],$source['request'],self::actor(1))['case']['id'],'unique exact active case selected over terminal history');
            $this->same(true,str_contains($panel($render()),hash('sha256',$active)),'panel renders selected exact active case baseline');
            $this->context(11);$this->same(null,$read->requestCase($source['appointment'],$source['request'],self::actor(11)),'case details unavailable to viewer');
            $html=$panel($render());foreach([$active,$id,hash('sha256',$active),$original['case']['baseline_hash'],'Sensitive fixture case','ASC review:','NATIONAL_APPROVED'] as $private)$this->same(false,str_contains($html,$private),'inaccessible panel does not expose '.$private);
            $this->context(9);$this->same(null,$read->requestCase($source['appointment'],$source['request'],self::actor(9)),'inaccessible active case does not fall back to readable terminal history');
            $this->context(1);$this->pdo->prepare("UPDATE arpa_appointment_historical_approval_case SET workflow_status='REJECTED' WHERE id=?")->execute([$active]);
            $this->same(null,$read->requestCase($source['appointment'],$source['request'],self::actor(1)),'multiple exact terminal cases require explicit administrative review');
            $html=$panel($render());$this->same(true,str_contains($html,'unavailable in your current Working Context'),'ambiguous selection handled safely');
            foreach([$active,$id,'Sensitive fixture case'] as $private)$this->same(false,str_contains($html,$private),'ambiguous selection reveals no case detail');
        }finally{$this->pdo->rollBack();$this->context(1);}
        [$status]=$this->route('GET','/hr/arpa-appointments/requests/division/'.$source['request'],[],10);$this->same(403,$status,'wrong District cannot open original request');
    }
    private function stageHistoryDisplay():void
    {
        $render=static function(array $events,bool $accessible=true):string{
            $request=['officer_number'=>'UAT','officer_name'=>'Synthetic Officer'];$status='CREATED';$administrativelyDeleted=false;
            $historicalPanel=['detail'=>$accessible?['case'=>['id'=>'case','workflow_status'=>'NATIONAL_APPROVED','baseline_hash'=>str_repeat('a',64)],'baseline'=>[],'actions'=>[],'events'=>$events,'source_stale'=>false,'display'=>['actors'=>['reviewer'=>'Reviewer']]]:null];
            ob_start();try{require BASE_PATH.'/app/Views/arpa_appointments/historical_request_panel.php';return (string)ob_get_contents();}finally{ob_end_clean();}
        };
        $event=static fn(string $stage,string $action,string $next,string $case='case'):array=>['approval_case_id'=>$case,'stage'=>$stage,'action'=>$action,'new_status'=>$next,'user_id'=>'reviewer','action_at'=>'2026-01-01 12:00:00','reason'=>'Synthetic review','after_json'=>'{}'];
        $stage=static function(string $html,string $name):string{preg_match('~<li[^>]*data-historical-stage="'.$name.'"[^>]*>(.*?)</li>~s',$html,$m);return strip_tags($m[1]??'');};
        $events=[];foreach(['ASC','DISTRICT','NATIONAL'] as $level){$events[]=$event($level,'VERIFY',$level.'_VERIFIED');$events[]=$event($level,'APPROVE',$level.'_APPROVED');}
        $html=$render($events);foreach(['ASC','DISTRICT','NATIONAL'] as $level){$text=$stage($html,$level);$this->same(true,str_contains($text,'Verification: Verified - Reviewer'),'actual verification shown for '.$level);$this->same(true,str_contains($text,'Approval: Approved - Reviewer'),'actual approval shown separately for '.$level);}
        $html=$render([$event('ASC','VERIFY','ASC_VERIFIED'),$event('DISTRICT','VERIFY','DISTRICT_VERIFIED'),$event('ASC','EDIT_DRAFT','ASC_APPROVED'),$event('ASC','APPROVE','ASC_APPROVED','different-case'),$event('NATIONAL','APPROVE','DISTRICT_APPROVED')]);
        foreach(['ASC','DISTRICT','NATIONAL'] as $level)$this->same(true,str_contains($stage($html,$level),'Approval: Not recorded'),'unrelated events/statuses do not infer '.$level.' approval');
        $this->same(true,str_contains($stage($html,'ASC'),'Verification: Verified'),'unrelated last event does not replace actual verification');
        $events[]=$event('DISTRICT','RETURN_FOR_CORRECTION','RETURNED');$html=$render($events);
        foreach(['ASC','DISTRICT','NATIONAL'] as $level)$this->same(false,str_contains($stage($html,$level),'Approved - Reviewer'),'return invalidates earlier-cycle approval display');
        $events[]=$event('CREATOR','SUBMIT','SUBMITTED');$events[]=$event('ASC','VERIFY','ASC_VERIFIED');$html=$render($events);
        $this->same(true,str_contains($stage($html,'ASC'),'Verification: Verified'),'new-cycle verification is shown');$this->same(true,str_contains($stage($html,'ASC'),'Approval: Not recorded'),'previous-cycle ASC approval not reused');
        $this->same(true,str_contains($stage($html,'DISTRICT'),'Verification: Not recorded'),'resubmission resets downstream verification');
        $this->same(false,str_contains($render($events,false),'Approved - Reviewer'),'inaccessible detail never infers progress from case status');
    }
    private function queues():void
    {
        $read=new ArpaHistoricalApprovalReadService($this->pdo);
        foreach([1,4,5,6,7,8,9,10] as $actor){$this->context($actor);$q=$read->query(self::actor($actor));$s=$this->pdo->prepare($q['sql']);$s->execute($q['params']);$rows=$s->fetchAll();$data=$this->table('arpa-historical-approvals-pending');$this->same(count($rows),$data['recordsTotal'],'historical table population equals shared count for role '.$actor);$this->same(count($rows),$read->count(self::actor($actor)),'historical count agrees with records');$this->same($this->table('arpa-submitted-appointments')['recordsTotal'],(new ArpaWorkflowQueuePolicy($this->pdo))->actionableCount(self::actor($actor)),'unified pending total reconciles with dashboard');
            $expected=match($actor){1=>['DRAFT','SUBMITTED'],4=>['DRAFT','SUBMITTED'],5=>['ASC_VERIFIED'],6=>['ASC_APPROVED'],7=>['DISTRICT_VERIFIED'],8=>['DISTRICT_APPROVED'],9=>['NATIONAL_VERIFIED'],default=>[]};
            $statuses=array_values(array_unique(array_column($rows,'historical_status')));sort($statuses);sort($expected);$this->same($expected,$statuses,'only the authorized stage is listed for role '.$actor);
            if($actor===10)$this->same(0,count($rows),'other District has no actionable cases');
        }
        $this->context(1);$page=$this->table('arpa-historical-approvals-pending',[],['length'=>10,'search'=>['value'=>'Synthetic']]);$this->same(true,$page['recordsFiltered']>0,'server search works');$this->same(true,count($page['data'])<=10,'pagination retained');
        $this->context(6);$summary=$this->table('arpa-submitted-appointments-summary');$sum=0;foreach($summary['data'] as $r)$sum+=(int)strip_tags($r['total_count']);$this->same((new ArpaWorkflowQueuePolicy($this->pdo))->actionableCount(self::actor(6)),$sum,'District ASC summary includes native plus historical cases');
        $this->context(8);$summary=$this->table('arpa-submitted-appointments-district-summary');$sum=0;foreach($summary['data'] as $r)$sum+=(int)strip_tags($r['total_count']);$this->same((new ArpaWorkflowQueuePolicy($this->pdo))->actionableCount(self::actor(8)),$sum,'National District summary reconciles');
        [$status,$body]=$this->route('GET','/hr/arpa-appointments/submitted',[],8);$this->same(200,$status,'existing National pending interface renders');$this->same(false,str_contains($body,'dems-arpa-historical-approvals-pending-table'),'no second historical table on the pending page');
        [$status]=$this->route('GET','/api/datatables/arpa-historical-approvals-pending?asc_id=10000000-0000-4000-8000-000000000002',[],1);$this->same(403,$status,'forged ASC filter denied by actual JSON endpoint');
        [$status]=$this->route('GET','/api/datatables/arpa-historical-approvals-pending',[],11);$this->same(403,$status,'Viewer cannot query historical queues');
        [$status]=$this->route('GET','/exports/arpa-historical-approvals-pending',[],1);$this->same(404,$status,'no unrequested export surface introduced');
    }
    private function combinedQueues():void
    {
        $read=new ArpaHistoricalApprovalReadService($this->pdo);$source=$this->cases[0];$nativeIds=[];
        $before=$this->call($this->fixture,'operational');$this->pdo->beginTransaction();
        try{
            $insert=$this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,created_by) VALUES(?,'NATIVE','APPOINTMENT',?,'PERMANENT',?,?,'2026-01-01',?,?)");
            foreach(['SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED'] as $i=>$status){
                $id='90000000-0000-4000-8000-'.str_pad((string)$i,12,'0',STR_PAD_LEFT);$nativeIds[$status]=$id;
                $insert->execute([$id,$source['officer'],self::ASC,$source['division'],$status,self::actor(1)]);
            }
            for($i=10;$i<45;$i++)$insert->execute(['90000000-0000-4000-8000-'.str_pad((string)$i,12,'0',STR_PAD_LEFT),$source['officer'],self::ASC,$source['division'],'SUBMITTED',self::actor(1)]);
            foreach([4,5,6,7,8,9] as $actor){
                $this->context($actor);$all=$this->table('arpa-submitted-appointments',[],['length'=>100]);
                $n=$this->table('arpa-submitted-appointments',[],['filters'=>['record_kind'=>'Native'],'length'=>100]);
                $h=$this->table('arpa-submitted-appointments',[],['filters'=>['record_kind'=>'Historical Appointment'],'length'=>100]);
                $this->same($n['recordsFiltered']+$h['recordsFiltered'],$all['recordsTotal'],'unified count includes both independently authorized populations for actor '.$actor);
                $this->same($read->count(self::actor($actor)),$h['recordsFiltered'],'historical population is unchanged inside unified list');
                $this->same($all['recordsTotal'],(new ArpaWorkflowQueuePolicy($this->pdo))->actionableCount(self::actor($actor)),'unified pending equals dashboard count');
                foreach($h['data'] as $row)$this->same(true,str_contains($row['actions'],'/historical-approvals/'),'historical rows keep case detail links');
                foreach($n['data'] as $row)$this->same(true,str_contains($row['actions'],'/requests/division/'),'native rows keep original detail links');
            }
            $this->context(4);$full=$this->table('arpa-submitted-appointments',[],['length'=>100,'order'=>[['column'=>1,'dir'=>'asc']]]);$pages=[];
            for($offset=0;$offset<$full['recordsTotal'];$offset+=10){$p=$this->table('arpa-submitted-appointments',[],['length'=>10,'start'=>$offset,'order'=>[['column'=>1,'dir'=>'asc']]]);$this->same($full['recordsTotal'],$p['recordsFiltered'],'total is calculated before SQL pagination');array_push($pages,...$p['data']);}
            $this->same($full['data'],$pages,'cross-kind SQL sorting and all pages reproduce complete ordered result');
            $this->same(count($pages),count(array_unique(array_column($pages,'actions'))),'no duplicate request/case rows across pages');
            $definition=DataTableRegistry::definition('arpa-submitted-appointments');$csv=iterator_to_array((new DataTableQuery($this->pdo,$definition,new DataTableRequest(['length'=>10,'start'=>10,'order'=>[['column'=>1,'dir'=>'asc']]])))->exportRows());
            $this->same($full['recordsTotal'],count($csv),'existing CSV export uses the complete unified result, not one page');$this->same(array_column($full['data'],'reference'),array_map(static fn($r)=>substr($r['reference'],0,8),$csv),'CSV reference values and sorting match displayed native/case references');$this->same(count($csv),count(array_unique(array_column($csv,'reference'))),'export retains every distinct source identity');
            $search=$this->table('arpa-submitted-appointments',[],['search'=>['value'=>'90000000'],'filters'=>['appointment_type'=>'PERMANENT','date_from'=>'2026-01-01'],'length'=>10]);
            $this->same(36,$search['recordsFiltered'],'server search and existing type/date filters work on unified source');$this->same(10,count($search['data']),'search applies before LIMIT');
            $scoped=$this->table('arpa-submitted-appointments',['asc_id'=>self::ASC]);$this->same($full['recordsTotal'],$scoped['recordsTotal'],'ASC drill-down filters both populations');
            foreach([2,10] as $actor){$this->context($actor);$this->same(0,$this->table('arpa-submitted-appointments')['recordsTotal'],'other ASC/District cannot see either population');}
            $event=$this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,action_at,record_origin,previous_status,new_status) VALUES(?,'VERIFY','ASC',?,NOW(),?,'SUBMITTED','ASC_VERIFIED')");
            foreach([[4,'NATIVE'],[5,'NATIVE'],[4,'LEGACY_IMPORT']] as [$actor,$origin])$event->execute([$nativeIds['ASC_VERIFIED'],self::actor($actor),$origin]);
            $this->context(4);$n=$this->table('arpa-approval-verification',[],['filters'=>['record_kind'=>'Native']]);$this->same(1,$n['recordsFiltered'],'completed union retains exact native acting-user and native-origin filters');
            $all=$this->table('arpa-approval-verification',[],['length'=>100]);$this->same(1+$read->count(self::actor(4),true),$all['recordsTotal'],'completed union counts own historical actions only');
            foreach($all['data'] as $row)$this->same(true,str_contains($row['action'],'VERIFY'),'completed rows retain current role/action filters');
            $this->context(4,'ASC_ADMIN');$this->same(0,$this->table('arpa-approval-verification',[],['filters'=>['record_kind'=>'Native']])['recordsFiltered'],'switching context does not show verifier actions as approval actions');
            $this->context(4);$this->same(1,$this->table('arpa-approval-verification',[],['filters'=>['record_kind'=>'Native']])['recordsFiltered'],'switching back restores own verification history');
            $this->parameterizedQueues($source);
        }finally{$this->pdo->rollBack();$this->context(1);}
        $this->same($before,$this->call($this->fixture,'operational'),'unified reads and rolled-back fixture inserts preserve original evidence');
        foreach(['/hr/arpa-appointments/submitted','/hr/arpa-appointments/approval'] as $page)foreach([4,6,8] as $actor){
            [$status,$html]=$this->route('GET',$page,[],$actor);$this->same(200,$status,'unified queue page renders for ASC/District/National');$this->same(true,str_contains($html,'Record Kind'),'one table presents a record-kind column for every review level');
            $this->same(false,str_contains($html,'dems-arpa-historical-approvals-'),'no second standalone historical DataTable');
        }
        foreach(['/api/datatables/arpa-submitted-appointments','/api/datatables/arpa-approval-verification'] as $url){
            [$status]=$this->route('GET',$url,[],11);$this->same(403,$status,'viewer cannot query unified queues');
            [$status]=$this->route('GET',$url.'?asc_id=10000000-0000-4000-8000-000000000002',[],1);$this->same(403,$status,'forged ASC filter fails closed for whole unified endpoint');
        }
        $this->hierarchyNavigation();
    }
    private function parameterizedQueues(array $source):void
    {
        $this->same(true,$this->pdo->inTransaction(),'parameter fixtures are inside the existing rollback-only test transaction');
        $foreignAsc='10000000-0000-4000-8000-000000000002';$foreignDivision='93000000-0000-4000-8000-000000000001';
        $this->pdo->prepare("INSERT INTO location(id,location_type_id,dad_number,name_en) SELECT ?,location_type_id,'SYNTHETIC-PARAM-FOREIGN','Synthetic foreign parameter division' FROM location WHERE id=?")->execute([$foreignDivision,$source['division']]);
        $this->pdo->prepare("INSERT INTO location_relationship(id,parent_location_id,child_location_id,relationship_type,effective_from) VALUES(UUID(),?,?,'ASC_ARPA_DIVISION','2020-01-01')")->execute([$foreignAsc,$foreignDivision]);
        $insert=$this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,requested_effective_to,workflow_status,created_by) VALUES(?,'NATIVE','APPOINTMENT',?,'PERMANENT',?,?,'2026-01-01',?,?,?)");
        $event=$this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,action_at,record_origin,timestamp_provenance,previous_status,new_status) VALUES(?,?,?,?,NOW(),?,'NATIVE_RECORDED',?,?)");
        $steps=[4=>['ASC','VERIFY','SUBMITTED','ASC_VERIFIED'],5=>['ASC','APPROVE','ASC_VERIFIED','ASC_APPROVED'],6=>['DISTRICT','VERIFY','ASC_APPROVED','DISTRICT_VERIFIED'],7=>['DISTRICT','APPROVE','DISTRICT_VERIFIED','DISTRICT_APPROVED'],8=>['NATIONAL','VERIFY','DISTRICT_APPROVED','NATIONAL_VERIFIED'],9=>['NATIONAL','APPROVE','NATIONAL_VERIFIED','NATIONAL_APPROVED']];
        foreach($steps as $actor=>[$stage,$action,$previous,$next]){
            $own=sprintf('91000000-0000-4000-8000-%012d',$actor);$foreign=sprintf('91000000-0000-4000-8000-%012d',100+$actor);
            foreach([[$own,self::ASC,$source['division']],[$foreign,$foreignAsc,$foreignDivision]] as [$id,$asc,$division]){
                $insert->execute([$id,$source['officer'],$asc,$division,$actor%2===0?'2026-12-31':null,$next,self::actor(1)]);
                $event->execute([$id,$action,$stage,self::actor($actor),'NATIVE',$previous,$next]);
            }
            if($actor===6){ // Old-cycle actions must not reappear after a return boundary.
                $event->execute([$own,'RETURN_FOR_CORRECTION',$stage,self::actor(7),'NATIVE',$next,'RETURNED']);
                $event->execute([$own,$action,$stage,self::actor($actor),'NATIVE',$previous,$next]);
            }
            $event->execute([$own,$action,$stage,self::actor($actor===4?5:4),'NATIVE',$previous,$next]);
            $event->execute([$own,$action,$stage,self::actor($actor),'LEGACY_IMPORT',$previous,$next]);
        }
        // Ensure even the National approver has real, nonempty Historical completed history.
        $this->context(9);$service=new ArpaHistoricalAppointmentApprovalService($this->pdo);$id=$this->cases[6]['case']['case']['id'];$case=$service->get($id,self::actor(9));
        $service->review($id,'APPROVE',(int)$case['case']['version'],self::actor(9),'Synthetic scoped approval','Synthetic National evidence');
        // Two genuine Historical VERIFY events for one case must remain two owned actions.
        $repeat=$this->cases[1]['case']['case']['id'];$this->context(4);$c=$service->get($repeat,self::actor(4));$c=$service->review($repeat,'VERIFY',(int)$c['case']['version'],self::actor(4),'First review cycle','Synthetic evidence');
        $this->context(5);$c=$service->review($repeat,'RETURN_FOR_CORRECTION',(int)$c['case']['version'],self::actor(5),'Recheck existing evidence','Synthetic return note');
        $this->context(1);$c=$service->submit($repeat,(int)$c['case']['version'],self::actor(1));$this->context(4);$service->review($repeat,'VERIFY',(int)$c['case']['version'],self::actor(4),'Fresh review cycle','Synthetic evidence');
        $this->same(2,$this->table('arpa-approval-verification',[],['search'=>['value'=>$repeat],'filters'=>['record_kind'=>'Historical Appointment']])['recordsFiltered'],'completed grain preserves distinct owned events, not one row per case');
        $read=new ArpaHistoricalApprovalReadService($this->pdo);$district='19000000-0000-4000-8000-000000000001';
        foreach($steps as $actor=>[$stage,$action]){
            $this->context($actor);$user=self::actor($actor);$policy=new ArpaWorkflowQueuePolicy($this->pdo);$access=$policy->completedAccess($user);
            $scope=$stage==='NATIONAL'?[]:App\Core\ScopeService::arpaWorkflowScopeParams($user);
            $this->same($scope,$access['params'],'completed access parameters belong to the leading scope CTE, actor '.$actor);
            $this->same(0,substr_count($access['where'],'?'),'completed stage WHERE has no positional values');
            if($scope!==[]){$this->same(2,count($scope),'ASC/District test exercises role and scope assignment placeholders');$this->same(false,in_array($user,$scope,true),'scope identifiers differ from acting-user identifier');}
            $native=(new ReflectionMethod(DataTableRegistry::class,'arpaApprovalVerification'))->invoke(null);
            $this->same([...$scope,$user],$native['baseParams'],'CTE values correctly precede w.user_id despite its first WHERE position');
            $nativeSql=($native['with']??'').' SELECT COUNT(DISTINCT '.$native['count'].') FROM '.$native['from'].' WHERE '.implode(' AND ',$native['baseWhere']);
            $this->same(count($native['baseParams']),substr_count($nativeSql,'?'),'native SQL placeholder count matches parameter count');
            $s=$this->pdo->prepare($nativeSql);$s->execute($native['baseParams']);$this->same(true,(int)$s->fetchColumn()>0,'correct parameter order returns nonempty owned actions');
            if($scope!==[]){$s->execute([$user,...$scope]);$this->same(0,(int)$s->fetchColumn(),'putting owner before CTE parameters would lose these records');}
            $historical=$read->query($user,true);$this->same([...$scope,$user],$historical['params'],'Historical completed CTE precedes event ownership');$this->same(true,$read->count($user,true)>0,'Historical completed history is nonempty for every review level');
            foreach([false,true] as $completed)foreach([[],['asc_id'=>self::ASC],['district_id'=>$district],['asc_id'=>self::ASC,'district_id'=>$district]] as $context){
                $key=$completed?'arpa-approval-verification':'arpa-submitted-appointments';
                $n=(new ReflectionMethod(DataTableRegistry::class,$completed?'arpaApprovalVerification':'arpaSubmittedAppointments'))->invoke(null);$h=$read->query($user,$completed,$context);$d=DataTableRegistry::definition($key,$context);
                $expected=[...$n['baseParams'],...$h['params']];if(isset($context['district_id']))$expected[]=$district;if(isset($context['asc_id']))$expected[]=self::ASC;
                $this->same($expected,$d['baseParams'],'native + historical + outer District + outer ASC parameter order');
                $where=$d['baseWhere']===[]?'':' WHERE '.implode(' AND ',$d['baseWhere']);$sql='SELECT COUNT(DISTINCT u.row_key) FROM '.$d['from'].$where;
                $this->same(count($expected),substr_count($sql,'?'),'all CTE, owner and repeated geographic placeholders accounted for');
                $np=$n['baseParams'];$nw=$n['baseWhere'];
                if(isset($context['asc_id'])){$nw[]='r.asc_location_id=?';$np[]=self::ASC;}
                if(isset($context['district_id'])){$nw[]="EXISTS(SELECT 1 FROM location_relationship dr WHERE dr.child_location_id=r.asc_location_id AND dr.parent_location_id=? AND dr.relationship_type='DISTRICT_ASC' AND dr.active=1 AND dr.approval_status='APPROVED' AND dr.effective_from<=CURRENT_DATE() AND (dr.effective_to IS NULL OR dr.effective_to>=CURRENT_DATE()))";$np[]=$district;}
                $s=$this->pdo->prepare(($n['with']??'').' SELECT COUNT(DISTINCT '.$n['count'].') FROM '.$n['from'].' WHERE '.implode(' AND ',$nw));$s->execute($np);$count=(int)$s->fetchColumn();
                $s=$this->pdo->prepare('SELECT COUNT(*) FROM ('.$h['sql'].') source_history');$s->execute($h['params']);$count+=(int)$s->fetchColumn();
                $all=$this->table($key,$context,['length'=>100,'order'=>[['column'=>1,'dir'=>'asc']]]);$this->same($count,$all['recordsTotal'],'unified total equals separately scoped populations');$this->same($count,$all['recordsFiltered'],'unfiltered total agrees');$this->same($count,count($all['data']),'no duplicate/missing union rows');
                $s=$this->pdo->prepare('SELECT u.row_key FROM '.$d['from'].$where);$s->execute($expected);$identities=$s->fetchAll(PDO::FETCH_COLUMN);$this->same($count,count(array_unique($identities)),'distinct source event/request keys, not detail URLs, define row identity');
                if($completed){
                    $filtered=$this->table($key,$context,['search'=>['value'=>'91000000'],'filters'=>['record_kind'=>'Native'],'length'=>100]);
                    $this->same($stage==='NATIONAL'&&$context===[]?2:1,$filtered['recordsFiltered'],'ownership, origin, review-cycle and geographic filters exclude wrong actions');
                    foreach($filtered['data'] as $row)$this->same(true,str_contains($row['actions'],sprintf('91000000-0000-4000-8000-%012d',$actor))||($stage==='NATIONAL'&&str_contains($row['actions'],sprintf('91000000-0000-4000-8000-%012d',100+$actor))),'only actual acting-user request identities are returned');
                }
                foreach(['asc','desc'] as $direction){
                    $s=$this->pdo->prepare('SELECT COALESCE(u.case_id,u.request_id) reference FROM '.$d['from'].$where.' ORDER BY u.effective_to '.strtoupper($direction).',u.row_key ASC');$s->execute($expected);$oracle=$s->fetchAll(PDO::FETCH_COLUMN);
                    $page=$this->table($key,$context,['length'=>10,'start'=>1,'order'=>[['column'=>9,'dir'=>$direction]]]);
                    $this->same(array_map(static fn($id)=>substr($id,0,8),array_slice($oracle,1,10)),array_column($page['data'],'reference'),'SQL NULL/date ordering and page boundaries agree with independent oracle');$this->same($count,$page['recordsTotal'],'pagination retains complete scoped count');
                    $identities=array_map(static function($row){preg_match('~/(?:requests/division|historical-approvals)/([a-f0-9-]{36})~i',$row['actions'],$match);return $match[1]??null;},$page['data']);
                    $this->same(array_slice($oracle,1,10),$identities,'full request/case identities match the page oracle even when displayed reference prefixes coincide');
                }
            }
            if($stage!=='ASC')foreach([false,true] as $completed){
                $base=$completed?'arpa-approval-verification':'arpa-submitted-appointments';$summary=$this->table($base.($stage==='NATIONAL'?'-district-summary':'-summary'));
                $total=array_sum(array_map(static fn($r)=>(int)strip_tags((string)$r['total_count']),$summary['data']));$this->same($this->table($base)['recordsTotal'],$total,'hierarchy counts equal unified counts with nonempty native/historical histories');
                if($stage==='NATIONAL'){$summary=$this->table($base.'-summary',['district_id'=>$district]);$total=array_sum(array_map(static fn($r)=>(int)strip_tags((string)$r['total_count']),$summary['data']));$this->same($this->table($base,['district_id'=>$district])['recordsTotal'],$total,'selected District ASC summary matches filtered unified total');}
            }
        }
    }
    private function hierarchyNavigation():void
    {
        $district='19000000-0000-4000-8000-000000000001';
        foreach(['/hr/arpa-appointments/submitted','/hr/arpa-appointments/approval'] as $page){
            foreach([6,8] as $actor){
                [$status,$body]=$this->route('GET',$page,[],$actor);$this->same(200,$status,'default page remains unified');$this->same(true,str_contains($body,$page.'?view=hierarchy'),'scoped hierarchy is discoverable from unified list');
                [$status,$body]=$this->route('GET',$page.'?view=hierarchy',[],$actor);$this->same(200,$status,'existing scoped hierarchy renders');$this->same(true,str_contains($body,$actor===8?'District Summary':'ASC Summary'),'correct National/District hierarchy level');$this->same(false,str_contains($body,'dems-arpa-historical-approvals-'),'hierarchy has no separate historical table');
            }
            [$status,$body]=$this->route('GET',$page.'/district/'.$district,[],8);$this->same(200,$status,'National District-to-ASC navigation preserved');$this->same(true,str_contains($body,$page.'?view=hierarchy'),'District back link returns to District hierarchy');
            [$status,$body]=$this->route('GET',$page.'/district/'.$district.'/asc/'.self::ASC,[],8);$this->same(200,$status,'National ASC drill-down renders unified records');$this->same(true,str_contains($body,'Record Kind'),'ASC drill-down is one combined table');$this->same(true,str_contains($body,'href="'.url(ltrim($page,'/').'/district/'.$district).'"'),'National ASC back link retains selected District');
            [$status,$body]=$this->route('GET',$page.'/asc/'.self::ASC,[],6);$this->same(200,$status,'District ASC drill-down preserved');$this->same(true,str_contains($body,'Record Kind'),'District ASC records stay unified');$this->same(true,str_contains($body,$page.'?view=hierarchy'),'District ASC back link returns to ASC summary');
            [$status]=$this->route('GET',$page.'/district/'.$district.'/asc/'.self::ASC,[],6);$this->same(403,$status,'District context cannot use National drill-down route');
            [$status]=$this->route('GET',$page.'/asc/'.self::ASC,[],10);$this->same(403,$status,'other District cannot browse this ASC');
            [$status,$body]=$this->route('GET',$page.'?view=hierarchy',[],4);$this->same(200,$status,'ASC context safely retains its own unified list');$this->same(true,str_contains($body,'Record Kind'),'ASC cannot switch to broader hierarchy');$this->same(false,str_contains($body,'Browse Districts / ASCs')||str_contains($body,'Browse ASCs'),'no broader navigation offered to ASC');
            [$status]=$this->route('GET',$page.'?view=hierarchy',[],11);$this->same(403,$status,'hierarchy does not bypass workflow permission');
        }
    }
    private function optionalVerify():void
    {
        $service=new ArpaHistoricalAppointmentApprovalService($this->pdo);$before=$this->call($this->fixture,'operational');
        foreach($this->verifyCases as [$fixture,$actor,$stage]){
            $id=$fixture['case']['id'];$url='/hr/arpa-appointments/historical-approvals/'.$id;
            $this->context($actor);$detail=$service->interfaceGet($id,self::actor($actor));$version=(string)$detail['case']['version'];
            [$status,$html]=$this->route('GET',$url,[],$actor);$this->same(200,$status,'verification form available for '.$stage);
            preg_match_all('~<form\b[^>]*>.*?</form>~s',$html,$forms);$verifyForm='';$adverseForm='';foreach($forms[0] as $form){if(str_contains($form,'value="VERIFY"'))$verifyForm=$form;if(str_contains($form,'value="REJECT"')||str_contains($form,'value="RETURN_FOR_CORRECTION"'))$adverseForm=$form;}
            foreach(['reason','evidence_reference'] as $field){preg_match('~<(?:input|textarea)\b[^>]*name="'.$field.'"[^>]*>~',$verifyForm,$tag);$this->same(true,isset($tag[0]),'verification field exists');$this->same(false,str_contains($tag[0],'required'),'VERIFY '.$field.' is optional in HTML');if($detail['actions']['return']||$detail['actions']['reject']){preg_match('~<(?:input|textarea)\b[^>]*name="'.$field.'"[^>]*>~',$adverseForm,$tag);$this->same(true,isset($tag[0])&&str_contains($tag[0],'required'),'adverse action retains required '.$field);}}
            $post=['expected_version'=>$version,'action'=>'VERIFY','reason'=>'','evidence_reference'=>''];
            if($stage==='DISTRICT')$post['reason']=" \t";
            if($stage==='NATIONAL')unset($post['reason'],$post['evidence_reference']);
            [$status]=$this->route('POST',$url.'/review',$post,$actor,null,'invalid');$this->same(419,$status,'blank verification still requires CSRF');
            [$status]=$this->route('POST',$url.'/review',$post,2);$this->same(403,$status,'blank verification cannot bypass scope');
            [$status]=$this->route('POST',$url.'/review',$post+['officer_id'=>'forged'],$actor);$this->same(422,$status,'protected fields still rejected');
            foreach(["invalid\x00text",str_repeat('x',1001)] as $invalid){[$status]=$this->route('POST',$url.'/review',array_replace($post,['reason'=>$invalid]),$actor);$this->same(422,$status,'optional VERIFY text still enforces length and control-character validation');}
            if($stage==='ASC'){
                // Simulate an installation still on 067: audit failure must roll back the transition.
                $this->pdo->exec('ALTER TABLE arpa_appointment_historical_approval_event ADD CONSTRAINT fixture_old_verify_check CHECK(CHAR_LENGTH(TRIM(reason))>0 AND CHAR_LENGTH(TRIM(evidence_reference))>0)');
                try{[$status,$body]=$this->route('POST',$url.'/review',$post,$actor);$this->same(503,$status,'missing optional-evidence capability fails closed');$this->same(false,str_contains($body,'SQLSTATE'),'constraint failure does not expose SQL');$this->same($version,(string)$service->get($id,self::actor($actor))['case']['version'],'audit insertion failure rolls back case version');}
                finally{$this->pdo->exec('ALTER TABLE arpa_appointment_historical_approval_event DROP CHECK fixture_old_verify_check');}
            }
            [$status]=$this->route('POST',$url.'/review',$post,$actor);$this->same(302,$status,'empty VERIFY pair accepted at '.$stage);
            $this->context($actor);$after=$service->get($id,self::actor($actor));$last=end($after['events']);
            foreach(['action'=>'VERIFY','stage'=>$stage,'user_id'=>self::actor($actor),'reason'=>'','evidence_reference'=>'','new_status'=>$stage.'_VERIFIED'] as $key=>$value)$this->same($value,$last[$key],'exact verification audit '.$key);
            $this->same(true,!empty($last['action_at']),'real verification timestamp recorded');$audit=json_decode($last['after_json'],true);$this->same(self::actor($actor),$audit['case']['updated_by'],'actual verifier recorded in structured audit');
            [$status]=$this->route('POST',$url.'/review',$post,$actor);$this->same(409,$status,'duplicate/stale verification cannot append another event');
            $approver=$actor+1;$p=['expected_version'=>(string)$after['case']['version'],'action'=>'APPROVE','reason'=>'','evidence_reference'=>''];
            foreach(['APPROVE','REJECT','RETURN_FOR_CORRECTION'] as $action){$p['action']=$action;[$status]=$this->route('POST',$url.'/review',$p,$approver);$this->same(422,$status,'blank '.$action.' remains rejected');}
            $this->same(count($after['events']),count($service->get($id,self::actor($actor))['events']),'denied actions leave no audit events');
            try{$this->pdo->prepare("INSERT INTO arpa_appointment_historical_approval_event(approval_case_id,action,stage,user_id,previous_status,new_status,reason,evidence_reference,after_json) VALUES(?,'APPROVE',?,?,'X','Y','','','{}')")->execute([$id,$stage,self::actor($approver)]);throw new RuntimeException('Database accepted empty approval evidence.');}catch(PDOException){$this->same(true,true,'migration 068 still rejects empty non-VERIFY event evidence');}
            if($stage==='NATIONAL'){
                [$status]=$this->route('POST',$url.'/review',['expected_version'=>(string)$after['case']['version'],'action'=>'APPROVE','reason'=>'Final authorized review','evidence_reference'=>'Synthetic final approval evidence','confirm_final'=>'1'],9);$this->same(302,$status,'National approval still requires and accepts explicit evidence/confirmation');
                $q=(new App\Services\ArpaAppointmentApprovalQualificationPolicy($this->pdo))->qualify($after['case']['appointment_id'],[self::ASC]);$this->same('APPROVED',$q['classification'],'genuine final approval qualifies even when verification text was omitted');
            }
        }
        $this->same($before,$this->call($this->fixture,'operational'),'optional VERIFY changes only case/events, never source records');$this->context(1);
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
        [$status,$html]=$this->route('GET','/hr/arpa-appointments/approval',[],9);$this->same(200,$status,'existing completed-action interface renders');$this->same(false,str_contains($html,'dems-arpa-historical-approvals-completed-table'),'completed page has no second historical table');
        $native=(new ReflectionMethod(DataTableRegistry::class,'arpaApprovalVerification'))->invoke(null);$this->same(true,in_array("r.record_origin='NATIVE'",$native['baseWhere'],true)&&in_array("w.record_origin='NATIVE'",$native['baseWhere'],true),'native-only completed source filters remain unchanged');
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
            $total=(new ArpaWorkflowQueuePolicy($this->pdo))->actionableCount(self::actor($actor));$this->same($this->table('arpa-submitted-appointments')['recordsTotal'],$total,'dashboard count equals unified pending queue');
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
            foreach([[4,'ASC_SUBJECT_OFFICER'],[5,'ASC_ADMIN']] as [$actor,$role]){$this->context($actor,$role);$this->same(1,$this->table('arpa-approval-verification',[],['filters'=>['record_kind'=>'Native']])['recordsFiltered'],'native completed action retained and imported action excluded for '.$role);}
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
            $this->same(1,$this->table('arpa-submitted-appointments',[],['filters'=>['record_kind'=>'Native']])['recordsFiltered'],'native pending ASC approval remains unchanged after historical retirement');
            foreach([[4,'ASC_SUBJECT_OFFICER'],[5,'ASC_ADMIN']] as [$actor,$role]){$this->context($actor,$role);$this->same(1,$this->table('arpa-approval-verification',[],['filters'=>['record_kind'=>'Native']])['recordsFiltered'],'native completed filters unchanged after historical retirement for '.$role);}
        }finally{$this->pdo->rollBack();$this->context(1);}
        $this->same($operational,$this->call($this->fixture,'operational'),'queue/detail inspection and rolled-back synthetic transitions preserve all operational records');
    }
    private function profile():void
    {
        $this->context(1);$s=$this->cases[0];$service=new App\Services\OfficerProfileService($this->pdo);$r=$this->call($service,'operationalAppointments',$s['officer'],[self::ASC]);$rows=$r[0];$this->same('CREATED',$rows[0]['workflow_status'],'profile retains original imported workflow status');$this->same('NATIONAL_APPROVED',$rows[0]['historical_approvals'][0]['workflow_status'],'profile has separate historical status');
        $line='';foreach(file(BASE_PATH.'/app/Views/officers/show.php') as $l)if(str_starts_with($l,'<?php $renderOperational='))$line=$l;
$dash='—';$canAdminDateCorrect=false;eval('use App\\Core\\DataTableFormat; '.substr(trim($line),5,-2));ob_start();$renderOperational('Synthetic Profile',$rows,[]);$html=ob_get_clean();foreach(['Original Workflow Status','Historical Approval Status','Recorded Effective Period / Assignment Status','Historical Correction Provenance','View Case','National Approved'] as $text)$this->same(true,str_contains($html,$text),'profile renders '.$text);
        $this->context(11);$rows=$this->call($service,'operationalAppointments',$s['officer'],[self::ASC])[0];$this->same(0,(int)$rows[0]['historical_approvals'][0]['can_read_case'],'Viewer can see profile status but cannot open restricted case evidence');
    }
    private function profilePeriods():void
    {
        $this->context(1);$source=$this->cases[8];$service=new App\Services\OfficerProfileService($this->pdo);
        $today=(string)$this->pdo->query('SELECT CURRENT_DATE()')->fetchColumn();$date=new DateTimeImmutable($today);$tomorrow=$date->modify('+1 day')->format('Y-m-d');$yesterday=$date->modify('-1 day')->format('Y-m-d');
        $before=$this->call($this->fixture,'operational');
        $saved=[];foreach(['arpa_division_appointment'=>'appointment','arpa_division_appointment_request'=>'request'] as $table=>$key){$q=$this->pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$source[$key]]);$saved[$table]=$q->fetch();}
        $q=$this->pdo->prepare('SELECT * FROM arpa_division_appointment_closure WHERE appointment_id=?');$q->execute([$source['appointment']]);$savedClosures=$q->fetchAll();
        try{
            $scenarios=[
                ['imported CREATED open','LEGACY_IMPORT',0,'2024-02-26',null,'PENDING_APPROVAL','Current / Open'],
                ['imported future','LEGACY_IMPORT',0,$tomorrow,null,'PENDING_APPROVAL','Scheduled'],
                ['imported closed','LEGACY_IMPORT',0,'2024-02-26',$yesterday,'PENDING_APPROVAL','Ended'],
                ['imported history-only','LEGACY_IMPORT',1,'2024-02-26',null,'HISTORICAL_ONLY','Historical Exception'],
                ['imported future history-only','LEGACY_IMPORT',1,$tomorrow,null,'HISTORICAL_ONLY','Historical Exception'],
                ['native current','NATIVE',0,'2024-02-26',null,'PENDING_APPROVAL','Current / Open'],
                ['native future','NATIVE',0,$tomorrow,null,'PENDING_APPROVAL','Scheduled'],
                ['native closed','NATIVE',0,'2024-02-26',$yesterday,'PENDING_APPROVAL','Ended'],
                ['closure effective today','LEGACY_IMPORT',0,'2024-02-26',$today,'PENDING_APPROVAL','Current'],
                ['future closure','LEGACY_IMPORT',0,'2024-02-26',$tomorrow,'PENDING_APPROVAL','Current'],
            ];
            foreach($scenarios as [$label,$origin,$historyOnly,$from,$to,$expected,$display]){
                $status=$origin==='LEGACY_IMPORT'?'CREATED':'ASC_APPROVED';
                $this->pdo->prepare('DELETE FROM arpa_division_appointment_closure WHERE appointment_id=?')->execute([$source['appointment']]);
                $this->pdo->prepare('UPDATE arpa_division_appointment SET record_origin=?,legacy_history_only=?,effective_from=?,approved_at=NULL WHERE id=?')->execute([$origin,$historyOnly,$from,$source['appointment']]);
                $this->pdo->prepare('UPDATE arpa_division_appointment_request SET record_origin=?,workflow_status=?,requested_effective_from=? WHERE id=?')->execute([$origin,$status,$from,$source['request']]);
                if($to!==null)$this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_source,data_correction_id,context_snapshot_json,approval_timestamp_provenance) VALUES(UUID(),'LEGACY_IMPORT',?,?,?,'18000000-0000-4000-8000-000000000001','DATA_ISSUE_CORRECTION',?,'{}','UNAVAILABLE_FROM_LEGACY_SOURCE')")->execute([$source['appointment'],$source['request'],$to,$source['correction']]);
                $state=$this->call($this->fixture,'operational');[$rows]=$this->call($service,'operationalAppointments',$source['officer'],[self::ASC]);
                $this->same(1,count($rows),$label.' remains one canonical row');$this->same($source['appointment'],$rows[0]['id'],$label.' preserves identity');$this->same($expected,$rows[0]['profile_period'],$label.' uses authority and operational dates');$this->same($display,$rows[0]['display_status'],$label.' has consistent operational wording');$this->same($status,$rows[0]['workflow_status'],$label.' retains original workflow status');
                foreach(['CURRENT','FUTURE','PREVIOUS','PENDING_APPROVAL','MANUAL_REVIEW','HISTORICAL_ONLY'] as $period)$this->same($period===$expected?1:0,count($this->call($service,'period',$rows,$period)),$label.' belongs only to the correct profile section');
                $this->same($state,$this->call($this->fixture,'operational'),$label.' viewing performs no source or audit writes');
            }
            // Restore the open imported scenario before checking scope under real Working Contexts.
            $this->pdo->prepare('DELETE FROM arpa_division_appointment_closure WHERE appointment_id=?')->execute([$source['appointment']]);$this->pdo->prepare("UPDATE arpa_division_appointment SET effective_from='2024-02-26' WHERE id=?")->execute([$source['appointment']]);
            $this->same([],$this->call($service,'operationalAppointments',$source['officer'],[])[0],'empty ASC scope cannot expose appointments');
            $this->context(2);$otherAsc=(string)Auth::activeContext(false)['location_id'];$this->same([],$this->call($service,'operationalAppointments',$source['officer'],[$otherAsc])[0],'unrelated ASC Working Context cannot expose the appointment');
            foreach([1,5] as $actor){$this->context($actor);$rows=$this->call($service,'operationalAppointments',$source['officer'],[self::ASC])[0];$this->same('PENDING_APPROVAL',$rows[0]['profile_period'],'authorized ASC role '.$actor.' sees unapproved record separately');$this->same('CREATED',$rows[0]['workflow_status'],'authorized ASC role '.$actor.' does not imply approval');}
            $this->same('PENDING_APPROVAL',$this->call($service,'operationalAppointments',$source['officer'],null)[0][0]['profile_period'],'unrestricted scope does not bypass approval');
        }finally{
            $this->pdo->prepare('DELETE FROM arpa_division_appointment_closure WHERE appointment_id=?')->execute([$source['appointment']]);
            foreach($saved as $table=>$row){$id=$row['id'];unset($row['id']);$this->pdo->prepare('UPDATE '.$table.' SET '.implode(',',array_map(fn($c)=>$c.'=?',array_keys($row))).' WHERE id=?')->execute([...array_values($row),$id]);}
            foreach($savedClosures as $row)$this->pdo->prepare('INSERT INTO arpa_division_appointment_closure('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
            $this->context(1);
        }
        $this->same($before,$this->call($this->fixture,'operational'),'isolated period fixtures leave original appointment, correction and workflow data unchanged');
    }
    private function missingSchema():void
    {
        $id=$this->cases[0]['case']['case']['id'];$this->pdo->exec('RENAME TABLE arpa_appointment_historical_approval_event TO fixture_hidden_event,arpa_appointment_historical_approval_case TO fixture_hidden_case');
        try{[$status,$body]=$this->route('GET','/hr/arpa-appointments/historical-approvals/'.$id);$this->same(503,$status,'direct route without migration fails closed');$this->same(false,str_contains($body,'SQLSTATE')||str_contains($body,'Stack trace'),'no SQL or stack trace exposed');[$status]=$this->route('POST','/hr/arpa-appointments/historical-approvals/'.$id.'/submit',['expected_version'=>'8']);$this->same(503,$status,'valid-CSRF POST without schema cannot write');$this->context(1);$this->same(0,(new ArpaHistoricalApprovalReadService($this->pdo))->count(self::actor(1)),'schema-absent dashboard remains compatible');$this->same(0,count($this->call(new App\Services\OfficerProfileService($this->pdo),'operationalAppointments',$this->cases[0]['officer'],[self::ASC])[0][0]['historical_approvals']),'schema-absent profile remains compatible');[$status,$body]=$this->route('GET','/hr/arpa-appointments/submitted',[],8);$this->same(200,$status,'existing queue page works without migration 067');$this->same(false,str_contains($body,'arpa-historical-approvals-pending'),'schema-absent page does not render an unusable historical table');}
        finally{$this->pdo->exec('RENAME TABLE fixture_hidden_event TO arpa_appointment_historical_approval_event,fixture_hidden_case TO arpa_appointment_historical_approval_case');}
        $this->pdo->exec('RENAME TABLE arpa_appointment_historical_approval_event TO fixture_hidden_event');
        try{[$status,$body]=$this->route('GET','/hr/arpa-appointments/requests/division/'.$this->cases[0]['request']);
            $this->same(200,$status,'original request remains viewable when historical schema is unavailable');
            foreach([$id,'SQLSTATE','Stack trace','/attest'] as $private)$this->same(false,str_contains($body,$private),'unavailable historical panel does not expose '.$private);
        }finally{$this->pdo->exec('RENAME TABLE fixture_hidden_event TO arpa_appointment_historical_approval_event');}
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
    private static function connect(string $db):PDO{if(!preg_match('/^dems_test_hist_ui_[a-f0-9]{10}$/D',$db))throw new RuntimeException('Unsafe UI fixture database.');$c=config('database');return new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port'],$db),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true,PDO::MYSQL_ATTR_INIT_COMMAND=>'SET SESSION default_storage_engine=InnoDB']);}
    private function same(mixed $e,mixed $a,string $m):void{$this->assertions++;if($e!==$a)throw new RuntimeException($m.'; expected '.var_export($e,true).', got '.var_export($a,true));}
}
if(!defined('ARPA_HISTORICAL_UI_FIXTURE_ONLY'))try{if(isset($argv[2]))ArpaHistoricalApprovalUiIsolatedTest::childMain($argv);else(new ArpaHistoricalApprovalUiIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
