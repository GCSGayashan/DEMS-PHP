<?php
declare(strict_types=1);
define('ARPA_CORRECTION_DRAFT_FIXTURE_ONLY',true);
require __DIR__.'/ArpaAppointmentCorrectionAmendmentDraftTest.php';

use App\Core\{Auth,Database};
use App\Services\{ArpaHistoricalAppointmentApprovalService,UserContextService};

final class ArpaHistoricalAppointmentApprovalIsolatedTest
{
    private const OWNER='00000000-0000-4000-8000-000000000001';
    private const ASC='10000000-0000-4000-8000-000000000001';
    private const CASES='arpa_appointment_historical_approval_case';
    private const EVENTS='arpa_appointment_historical_approval_event';
    private PDO $pdo;
    private string $name;
    private int $assertions=0;
    private ArpaHistoricalAppointmentApprovalService $service;

    public function run():void
    {
        $c=config('database');if(!in_array($c['host'],['localhost','127.0.0.1'],true)||!str_starts_with($c['database'],'dems_test_'))throw new RuntimeException('Use process-only isolated test configuration.');
        $this->name='dems_test_hist_approval_'.bin2hex(random_bytes(5));
        $server=new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4',$c['host'],$c['port']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $property=new ReflectionProperty(Database::class,'pdo');$old=$property->getValue();$session=$_SESSION??[];$created=false;
        try{
            $server->exec('CREATE DATABASE `'.$this->name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
            $this->pdo=self::connect($this->name);if($this->pdo->query('SELECT DATABASE()')->fetchColumn()!==$this->name)throw new RuntimeException('Wrong database before fixture writes.');
            $property->setValue(null,$this->pdo);
            $fixture=new ArpaAppointmentCorrectionAmendmentDraftTest();(new ReflectionProperty($fixture,'pdo'))->setValue($fixture,$this->pdo);
            (new ReflectionMethod($fixture,'createFixtureSchema'))->invoke($fixture);
            $this->pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/062_arpa_appointment_correction_amendment_foundation.sql'));
            (new ReflectionMethod($fixture,'insertFixtures'))->invoke($fixture);
            (new ReflectionMethod($fixture,'exerciseHistoricalPeerDecisions'))->invoke($fixture);
            if(!(new \App\Services\ArpaHistoricalApprovalReadService($this->pdo))->available())$this->pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/067_arpa_historical_approval_foundation.sql'));
            $this->pdo->exec("INSERT INTO designation VALUES('historical-test-arpa','ARPA_OFFICER');ALTER TABLE arpa_division_appointment ADD UNIQUE KEY uq_test_request(request_id);
                CREATE TABLE IF NOT EXISTS arpa_appointment_workflow_action(id BIGINT AUTO_INCREMENT PRIMARY KEY,request_id CHAR(36),action VARCHAR(50),stage VARCHAR(50),user_id CHAR(36),action_at DATETIME NULL,record_origin VARCHAR(40),previous_status VARCHAR(50),new_status VARCHAR(50)) ENGINE=InnoDB");
            $this->service=new ArpaHistoricalAppointmentApprovalService($this->pdo);
            $this->lifecycle();$this->security();$this->returnedAndStale();$this->lineage();$this->dependencies();$this->rollbackAndSchema();$this->concurrency();
            echo 'ArpaHistoricalAppointmentApprovalIsolatedTest: '.$this->assertions.' assertions passed; MySQL '.$server->getAttribute(PDO::ATTR_SERVER_VERSION)."; disposable schema removed.\n";
        }finally{$property->setValue(null,$old);$_SESSION=$session;Auth::forgetRequestCache();if($created)$server->exec('DROP DATABASE `'.$this->name.'`');}
    }
    private function lifecycle():void
    {
        $s=$this->source();$before=$this->operational();$this->context();
        $case=$this->service->register($s['correction'],$this->input(),self::OWNER);$id=$case['case']['id'];
        $this->same('DRAFT',$case['case']['workflow_status'],'registration creates a draft, never an approval');
        $again=$this->service->register($s['correction'],$this->input(),self::OWNER);$this->same($id,$again['case']['id'],'registration is idempotent');$this->same(1,count($again['events']),'no duplicate REGISTER event');
        $case=$this->service->submit($id,0,self::OWNER);$this->same('SUBMITTED',$case['case']['workflow_status'],'ASC submits existing historical appointment');
        $this->fails(fn()=>$this->service->submit($id,0,self::OWNER),'duplicate submission',409);
        $case=$this->complete($case);$this->same('NATIONAL_APPROVED',$case['case']['workflow_status'],'all six review stages complete');
        $this->same(8,count($case['events']),'one registration, one submission, six reviews');
        foreach($case['events'] as $event){$this->same(true,$event['action_at']!==null,'actual action timestamp');$this->same(true,$event['reason']!==''&&$event['evidence_reference']!==''&&$event['user_id']!=='','actor, reason and evidence recorded');$this->same($id,$event['approval_case_id'],'event identifies this case');$this->same(true,!empty(json_decode($event['after_json'],true)['active_context']['role_assignment_id']),'event retains actual Working Context evidence');}
        $this->same($before,$this->operational(),'entire lifecycle leaves all original operational and correction rows unchanged');
        $this->same('CREATED',$this->row('arpa_division_appointment_request',$s['request'])['workflow_status'],'imported workflow status preserved');
        $this->same(null,$this->row('arpa_division_appointment',$s['appointment'])['approved_at'],'unknown historical approval timestamp remains NULL');
        $this->same(0,(int)$this->pdo->query("SELECT COUNT(*) FROM ".self::EVENTS." WHERE approval_case_id='{$id}' AND DATE(action_at)<>CURRENT_DATE()")->fetchColumn(),'new workflow actions use the actual database date, not appointment start dates');
        $this->fails(fn()=>$this->service->review($id,'APPROVE',(int)$case['case']['version'],self::actor(9),'Retry','Evidence'),'terminal approval cannot repeat');
        $this->same(8,count($this->service->get($id,self::actor(9))['events']),'historical reviewer can read completed evidence without duplicate events');
        $this->context();$this->same($id,$this->service->register($s['correction'],$this->input(),self::OWNER)['case']['id'],'registration cannot reopen completed approval');
        // Bounded canonical closure, not a possibly open-ended original request, is the approval period.
        $ended=$this->source('PERMANENT',null,'2020-01-01','2020-12-31');$before=$this->operational();$c=$this->service->register($ended['correction'],$this->input(),self::OWNER);$c=$this->service->submit($c['case']['id'],0,self::OWNER);$this->complete($c);
        $this->same($before,$this->operational(),'pre-2025 closed history can be revalidated without native creation/date rules or changing closure');
    }
    private function security():void
    {
        $s=$this->source();$this->context();$case=$this->service->register($s['correction'],$this->input(),self::OWNER);$id=$case['case']['id'];
        foreach([2,5,7,9,11] as $n){$actor=self::actor($n);$this->context($actor);$this->fails(fn()=>$this->service->register($s['correction'],$this->input(),$actor),'wrong ASC/role cannot register',403);$this->fails(fn()=>$this->service->submit($id,0,$actor),'wrong ASC/role cannot submit',403);}
        $this->context(self::actor(2));$this->fails(fn()=>$this->service->get($id,self::actor(2)),'cross-ASC detail denied',403);
        $this->context();$this->fails(fn()=>$this->service->submit($id,0,self::actor(4)),'forged actor ID denied',403);
        foreach(['officer_id','asc_location_id','workflow_status','appointment_id','source_correction_id','created_by'] as $field){$input=$this->input();$input[$field]='forged';$this->fails(fn()=>$this->service->register($s['correction'],$input,self::OWNER),'protected '.$field.' rejected',422);}
        foreach(['reason','evidence_reference'] as $field){$input=$this->input();unset($input[$field]);$this->fails(fn()=>$this->service->register($s['correction'],$input,self::OWNER),'mandatory '.$field,422);}
        $optional=$this->input();$optional['remarks']='';$this->same(null,$this->service->register($s['correction'],$optional,self::OWNER)['case']['remarks'],'blank optional remarks are accepted');
        $this->fails(fn()=>$this->service->review($id,'APPROVE',0,self::OWNER,'Reason','Proof'),'cannot skip stages');
        $c=$this->service->submit($id,0,self::OWNER);$this->context(self::actor(4),'ASC_SUBJECT_OFFICER');$c=$this->service->review($id,'VERIFY',1,self::actor(4),'Verified','ASC evidence');
        $this->context(self::actor(4),'ASC_ADMIN');$this->fails(fn()=>$this->service->review($id,'APPROVE',2,self::actor(4),'Approve','Proof'),'verifier cannot approve by switching roles',403);
        $this->context(self::actor(5));$this->fails(fn()=>$this->service->review($id,'APPROVE',1,self::actor(5),'Approve','Proof'),'stale version rejected',409);
        $this->fails(fn()=>$this->service->review($id,'APPROVE',2,self::actor(5),'','Proof'),'review reason mandatory',422);
        $this->fails(fn()=>$this->service->review($id,'APPROVE',2,self::actor(5),'Approve',''),'review evidence mandatory',422);
        $this->pdo->beginTransaction();try{$this->pdo->exec("UPDATE arpa_appointment_data_correction SET corrected_by='".self::actor(5)."' WHERE id='{$s['correction']}'");$this->fails(fn()=>(new ReflectionMethod($this->service,'makerChecker'))->invoke($this->service,$c['case'],'ASC',self::actor(5)),'source correction maker cannot approve',403);}finally{$this->pdo->rollBack();}
        $this->context();unset($_SESSION['access_context']);Auth::forgetRequestCache();$this->fails(fn()=>$this->service->submit($id,2,self::OWNER),'explicit Working Context required',403);
        $this->context();$this->pdo->beginTransaction();try{$this->pdo->prepare('UPDATE user_account_role SET active=0 WHERE user_id=?')->execute([self::OWNER]);Auth::forgetRequestCache();$this->fails(fn()=>$this->service->get($id,self::OWNER),'inactive role cannot inspect historical case',403);}finally{$this->pdo->rollBack();}$this->context();
        $ownRole=$this->uuid();$this->pdo->prepare("INSERT INTO user_account_role VALUES(?,?,'12000000-0000-4000-8000-000000000002',1,'APPROVED',CURRENT_DATE(),NULL)")->execute([$ownRole,self::OWNER]);$this->pdo->prepare("INSERT INTO user_account_scope VALUES(?,?,?,'ASC','EXACT',?,1,'APPROVED',CURRENT_DATE(),NULL)")->execute([$this->uuid(),self::OWNER,$ownRole,self::ASC]);
        $this->context(self::OWNER,'ASC_ADMIN');$this->fails(fn()=>$this->service->review($id,'APPROVE',2,self::OWNER,'Approve','Proof'),'case maker cannot approve in a different role',403);
        $this->context(self::actor(5));$c=$this->service->review($id,'APPROVE',2,self::actor(5),'Approve','Proof');$this->context(self::actor(10));$this->fails(fn()=>$this->service->get($id,self::actor(10)),'other District cannot inspect case',403);$this->fails(fn()=>$this->service->review($id,'VERIFY',3,self::actor(10),'Verify','Proof'),'other District cannot act',403);
    }
    private function returnedAndStale():void
    {
        $s=$this->source();$this->context();$c=$this->service->register($s['correction'],$this->input(),self::OWNER);$c=$this->service->submit($c['case']['id'],0,self::OWNER);$c=$this->advance($c,4,'VERIFY');$c=$this->advance($c,5,'APPROVE');
        $before=$this->operational();$c=$this->advance($c,6,'RETURN_FOR_CORRECTION');$this->same('RETURNED',$c['case']['workflow_status'],'District can return governance without undoing ASC or canonical authority');
        $this->same($before,$this->operational(),'return leaves source data unchanged');$this->context();
        $c=$this->service->updateDraft($c['case']['id'],$this->input(),(int)$c['case']['version'],$this->service->get($c['case']['id'],self::OWNER)['current_source_hash'],self::OWNER);
        $c=$this->service->submit($c['case']['id'],(int)$c['case']['version'],self::OWNER);$this->same('SUBMITTED',$c['case']['workflow_status'],'returned resubmission restarts ASC verification');$this->complete($c);
        $this->context();$s=$this->source();$c=$this->service->register($s['correction'],$this->input(),self::OWNER);$c=$this->service->submit($c['case']['id'],0,self::OWNER);$c=$this->advance($c,4,'VERIFY');
        $before=$this->operational();$c=$this->advance($c,5,'REJECT');$this->same('REJECTED',$c['case']['workflow_status'],'rejection is terminal, not RETURNED');$this->same($before,$this->operational(),'rejection cannot close, demote, delete or alter canonical source');
        $this->context();$this->same('REJECTED',$this->service->register($s['correction'],$this->input(),self::OWNER)['case']['workflow_status'],'idempotent registration cannot erase rejection');
        $s=$this->source();$c=$this->service->register($s['correction'],$this->input(),self::OWNER);
        $this->pdo->prepare('UPDATE arpa_division_appointment_request SET version=version+1 WHERE id=?')->execute([$s['request']]);
        $this->fails(fn()=>$this->service->submit($c['case']['id'],0,self::OWNER),'changed source baseline rejected',409);
        $this->fails(fn()=>$this->service->updateDraft($c['case']['id'],$this->input(),0,$c['case']['baseline_hash'],self::OWNER),'rebase needs current hash acknowledgment',409);
        $freshRead=$this->service->get($c['case']['id'],self::OWNER);$this->same(1,(int)$freshRead['current_baseline']['request']['version'],'authorized read supplies the complete changed baseline, not merely its hash');$fresh=$freshRead['current_source_hash'];$c=$this->service->updateDraft($c['case']['id'],$this->input(),0,$fresh,self::OWNER);
        $c=$this->service->submit($c['case']['id'],1,self::OWNER);$c=$this->advance($c,4,'VERIFY');
        $this->pdo->prepare('UPDATE arpa_division_appointment SET legacy_exception_codes_json=? WHERE id=?')->execute(['["NEW_EVIDENCE"]',$s['appointment']]);$this->context(self::actor(5));
        $before=$this->row(self::CASES,$c['case']['id']);$this->fails(fn()=>$this->service->review($c['case']['id'],'APPROVE',(int)$c['case']['version'],self::actor(5),'Reason','Evidence'),'source changed after verification blocks approval',409);$this->same($before,$this->row(self::CASES,$c['case']['id']),'stale approval changes no case state');
        $this->pdo->prepare('UPDATE arpa_division_appointment SET legacy_exception_codes_json=NULL WHERE id=?')->execute([$s['appointment']]);
        $this->pdo->beginTransaction();try{$this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,record_origin,new_status) VALUES(?,'VERIFY','ASC','LEGACY_IMPORT','ASC_VERIFIED')")->execute([$s['request']]);$this->fails(fn()=>$this->service->review($c['case']['id'],'APPROVE',(int)$c['case']['version'],self::actor(5),'Reason','Evidence'),'changed original workflow evidence blocks approval',409);}finally{$this->pdo->rollBack();}
        $this->pdo->beginTransaction();try{$this->pdo->prepare("UPDATE legacy_arpa_appointment_source_reference SET source_table='changed_evidence' WHERE target_appointment_request_id=?")->execute([$s['request']]);$this->fails(fn()=>$this->service->review($c['case']['id'],'APPROVE',(int)$c['case']['version'],self::actor(5),'Reason','Evidence'),'changed imported provenance blocks approval',409);}finally{$this->pdo->rollBack();}
    }
    private function lineage():void
    {
        $s=$this->source();$this->context();$c=$this->service->register($s['correction'],$this->input(),self::OWNER);
        $successor=$this->uuid();$this->pdo->prepare('INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin,supersedes_correction_id) SELECT ?,CONCAT(issue_row_key,\':successor\'),issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin,id FROM arpa_appointment_data_correction WHERE id=?')->execute([$successor,$s['correction']]);
        $this->fails(fn()=>$this->service->submit($c['case']['id'],0,self::OWNER),'explicit supersession retires source eligibility',409);
        $this->fails(fn()=>$this->service->register($successor,$this->input(),self::OWNER),'one active case per canonical appointment',409);
        $before=$this->operational();$c=$this->advance($c,5,'REJECT');$this->same('REJECTED',$c['case']['workflow_status'],'independent ASC approver can retire stale draft');
        $this->same($before,$this->operational(),'stale retirement does not modify correction chain');$this->context();$replacement=$this->service->register($successor,$this->input(),self::OWNER);$this->same($successor,$replacement['case']['source_correction_id'],'new source gets a separate linked case, never rebinds old case');
        $other=$this->source();$draft=$this->service->register($other['correction'],$this->input(),self::OWNER);$this->context(self::actor(5));$this->fails(fn()=>$this->service->review($draft['case']['id'],'REJECT',0,self::actor(5),'Retire','Evidence'),'valid Draft cannot use stale retirement',409);
        $this->context();$this->pdo->beginTransaction();try{$this->pdo->prepare("UPDATE arpa_appointment_data_correction SET correction_action='KEEP_AS_HISTORICAL_EXCEPTION',resolution_status='KEPT_HISTORICAL_EXCEPTION' WHERE id=?")->execute([$other['correction']]);$this->fails(fn()=>$this->service->register($other['correction'],$this->input(),self::OWNER),'KEEP is not canonical revalidation authority',409);}finally{$this->pdo->rollBack();}
        foreach(["UPDATE arpa_division_appointment SET record_origin='NATIVE' WHERE id='{$other['appointment']}'","UPDATE arpa_division_appointment SET legacy_history_only=1 WHERE id='{$other['appointment']}'","UPDATE arpa_division_appointment_request SET deleted_at=NOW() WHERE id='{$other['request']}'","UPDATE arpa_appointment_data_correction SET request_id='40000000-0000-4000-8000-000000000001' WHERE id='{$other['correction']}'"] as $sql){$this->pdo->beginTransaction();try{$this->pdo->exec($sql);$this->fails(fn()=>$this->service->register($other['correction'],$this->input(),self::OWNER),'unsafe or mismatched source cannot register',409);}finally{$this->pdo->rollBack();}}
    }
    private function dependencies():void
    {
        $foundation=$this->source();$acting=$this->source('ACTING',$foundation['officer'],'2025-01-01');$this->context();
        $f=$this->service->register($foundation['correction'],$this->input(),self::OWNER);$a=$this->service->register($acting['correction'],$this->input(),self::OWNER);$a=$this->service->submit($a['case']['id'],0,self::OWNER);
        foreach([[4,'VERIFY'],[5,'APPROVE'],[6,'VERIFY'],[7,'APPROVE'],[8,'VERIFY']] as [$n,$action])$a=$this->advance($a,$n,$action);
        $this->context(self::actor(9));$this->fails(fn()=>$this->service->review($a['case']['id'],'APPROVE',(int)$a['case']['version'],self::actor(9),'Final','Evidence'),'dependent final approval waits for historical Permanent revalidation',409);
        $this->context();$f=$this->service->submit($f['case']['id'],0,self::OWNER);$this->complete($f);$this->context(self::actor(9));$a=$this->service->review($a['case']['id'],'APPROVE',(int)$a['case']['version'],self::actor(9),'Final','Evidence');$this->same('NATIONAL_APPROVED',$a['case']['workflow_status'],'dependent approval succeeds after valid foundation completes');
        // New valid dependent records do not invalidate an already attested Permanent source.
        $dutyValid=$this->source('DUTY_COVERING',$foundation['officer'],'2025-06-01');$this->context();$d=$this->service->register($dutyValid['correction'],$this->input(),self::OWNER);$d=$this->service->submit($d['case']['id'],0,self::OWNER);$d=$this->complete($d);$this->same('NATIONAL_APPROVED',$d['case']['workflow_status'],'valid Duty Covering revalidation uses completed Permanent foundation');
        $base=$this->source();$child=$this->source('ACTING',$base['officer'],'2025-01-01');$this->context();$b=$this->service->register($base['correction'],$this->input(),self::OWNER);$a=$this->service->register($child['correction'],$this->input(),self::OWNER);$a=$this->service->submit($a['case']['id'],0,self::OWNER);$a=$this->advance($a,4,'VERIFY');
        $before=$this->row(self::CASES,$a['case']['id']);$this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,effective_to,legacy_reason_text,context_snapshot_json,approval_timestamp_provenance) VALUES(?,'LEGACY_IMPORT',?,'2024-12-31','Synthetic corrected end','{}','UNAVAILABLE_FROM_LEGACY_SOURCE')")->execute([$this->uuid(),$base['appointment']]);$this->context(self::actor(5));$this->fails(fn()=>$this->service->review($a['case']['id'],'APPROVE',(int)$a['case']['version'],self::actor(5),'Approve','Proof'),'changed related Permanent closure after review is stale',409);$this->same($before,$this->row(self::CASES,$a['case']['id']),'related stale approval appends no state change');
        $base=$this->source();$child=$this->source('ACTING',$base['officer'],'2025-01-01');$this->context();$b=$this->service->register($base['correction'],$this->input(),self::OWNER);$b=$this->service->submit($b['case']['id'],0,self::OWNER);$b=$this->advance($b,4,'VERIFY');$this->advance($b,5,'REJECT');$this->context();$a=$this->service->register($child['correction'],$this->input(),self::OWNER);$a=$this->service->submit($a['case']['id'],0,self::OWNER);foreach([[4,'VERIFY'],[5,'APPROVE'],[6,'VERIFY'],[7,'APPROVE'],[8,'VERIFY']] as [$n,$act])$a=$this->advance($a,$n,$act);$this->context(self::actor(9));$this->fails(fn()=>$this->service->review($a['case']['id'],'APPROVE',(int)$a['case']['version'],self::actor(9),'Final','Proof'),'rejected foundation cannot certify dependent final approval',409);
        $missing=$this->source('ACTING');$this->context();$m=$this->service->register($missing['correction'],$this->input(),self::OWNER);$this->fails(fn()=>$this->service->submit($m['case']['id'],0,self::OWNER),'missing Permanent prerequisite rejected');
        $duty=$this->source('DUTY_COVERING');$d=$this->service->register($duty['correction'],$this->input(),self::OWNER);$this->fails(fn()=>$this->service->submit($d['case']['id'],0,self::OWNER),'Duty Covering prerequisites preserved');
        $overlap=$this->source();$duplicate=$this->source('PERMANENT',null,'2021-01-01',null,$overlap['division']);$o=$this->service->register($overlap['correction'],$this->input(),self::OWNER);$this->fails(fn()=>$this->service->submit($o['case']['id'],0,self::OWNER),'real canonical overlap remains blocked');
        $s=$this->source();$case=$this->service->register($s['correction'],$this->input(),self::OWNER);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status) VALUES(?,'NATIVE','APPOINTMENT',?,'ACTING',?,?,?,'SUBMITTED')")->execute([$this->uuid(),$s['officer'],self::ASC,$s['division'],'2025-01-01']);
        $hash=$this->service->get($case['case']['id'],self::OWNER)['current_source_hash'];$case=$this->service->updateDraft($case['case']['id'],$this->input(),0,$hash,self::OWNER);$this->fails(fn()=>$this->service->submit($case['case']['id'],1,self::OWNER),'genuine pending reservation remains protected');
    }
    private function rollbackAndSchema():void
    {
        $s=$this->source();$this->context();$c=$this->service->register($s['correction'],$this->input(),self::OWNER);$id=$c['case']['id'];$before=$this->row(self::CASES,$id);
        $this->pdo->exec("CREATE TRIGGER force_hist_event_failure BEFORE INSERT ON ".self::EVENTS." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic event failure'");
        try{$this->fails(fn()=>$this->service->submit($id,0,self::OWNER),'event failure rolls back submission',null,true);}finally{$this->pdo->exec('DROP TRIGGER force_hist_event_failure');}
        $this->same($before,$this->row(self::CASES,$id),'submission rollback restores version, status and actor timestamps');$this->same(1,count($this->service->get($id,self::OWNER)['events']),'failed submission leaves no event');
        $this->pdo->beginTransaction();try{
            $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,source_ip) VALUES(?,'fixture.parent','TEST',?,'{}','INFO','CLI')")->execute([self::OWNER,$id]);
            $this->fails(fn()=>$this->service->submit($id,99,self::OWNER),'nested failure rolls back only the service savepoint',409);
            $this->same(true,$this->pdo->inTransaction(),'outer transaction remains open');$this->same(1,(int)$this->pdo->query("SELECT COUNT(*) FROM audit_event WHERE action_key='fixture.parent' AND target_id='{$id}'")->fetchColumn(),'caller work remains intact after nested failure');
        }finally{$this->pdo->rollBack();}
        $c=$this->service->submit($id,0,self::OWNER);foreach([[4,'VERIFY'],[5,'APPROVE'],[6,'VERIFY'],[7,'APPROVE'],[8,'VERIFY']] as [$n,$act])$c=$this->advance($c,$n,$act);
        $before=$this->row(self::CASES,$id);$this->context(self::actor(9));$this->pdo->exec("CREATE TRIGGER force_hist_event_failure BEFORE INSERT ON ".self::EVENTS." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic final event failure'");
        try{$this->fails(fn()=>$this->service->review($id,'APPROVE',(int)$c['case']['version'],self::actor(9),'Final','Evidence'),'final event failure rolls back final approval',null,true);}finally{$this->pdo->exec('DROP TRIGGER force_hist_event_failure');}
        $this->same($before,$this->row(self::CASES,$id),'finalization rollback restores all earlier case writes');
        foreach(['UPDATE '.self::EVENTS." SET reason='tampered' WHERE approval_case_id='{$id}'",'DELETE FROM '.self::EVENTS." WHERE approval_case_id='{$id}'", "DELETE FROM arpa_division_appointment WHERE id='{$s['appointment']}'","DELETE FROM arpa_appointment_data_correction WHERE id='{$s['correction']}'"] as $sql)$this->fails(fn()=>$this->pdo->exec($sql),'append-only events and FK source evidence protected',null,true);
        $columns=$this->pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='".self::CASES."' AND index_name IN('uq_hist_approval_registration','uq_hist_approval_active')")->fetchColumn();$this->same(3,(int)$columns,'registration and active uniqueness indexes installed');
        $this->context();$state=$this->operational();$read=$this->service->get($id,self::OWNER);$this->same($state,$this->operational(),'authorized get is read-only');
        $this->pdo->exec('RENAME TABLE arpa_appointment_correction_amendment_request TO fixture_hidden_amendment');
        try{$this->fails(fn()=>$this->service->register($s['correction'],$this->input(),self::OWNER),'missing lineage schema fails closed',503);}finally{$this->pdo->exec('RENAME TABLE fixture_hidden_amendment TO arpa_appointment_correction_amendment_request');}
    }
    private function concurrency():void
    {
        $s=$this->source();$this->context();$out=$this->workers('register',$s['correction'],0,self::OWNER,$s['officer']);$this->same($out[0],$out[1],'concurrent registration returns one case');$id=$out[0];$this->same(1,count($this->service->get($id,self::OWNER)['events']),'concurrent registration creates one event');
        $out=$this->workers('submit',$id,0,self::OWNER,$s['officer']);sort($out);$this->same(['BLOCKED','SUBMITTED'],$out,'concurrent submission accepts exactly one action');$c=$this->service->get($id,self::OWNER);$this->same(2,count($c['events']),'one immutable submit event');
        foreach([[4,'VERIFY'],[5,'APPROVE'],[6,'VERIFY'],[7,'APPROVE'],[8,'VERIFY']] as [$n,$act])$c=$this->advance($c,$n,$act);
        $out=$this->workers('approve',$id,(int)$c['case']['version'],self::actor(9),$s['officer']);sort($out);$this->same(['BLOCKED','NATIONAL_APPROVED'],$out,'concurrent final approval applies once');$this->context();$this->same(8,count($this->service->get($id,self::OWNER)['events']),'no duplicate final event');
        $s=$this->source();$other=$this->uuid();$this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin) SELECT ?,CONCAT(issue_row_key,':independent'),issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin FROM arpa_appointment_data_correction WHERE id=?")->execute([$other,$s['correction']]);
        $out=$this->workers('register',$s['correction'],0,self::OWNER,$s['officer'],$other);$this->same(1,count(array_filter($out,static fn($v)=>$v==='BLOCKED')),'different correction decisions cannot concurrently open two active cases for one appointment');$this->same(1,(int)$this->pdo->query("SELECT COUNT(*) FROM ".self::CASES." WHERE appointment_id='{$s['appointment']}'")->fetchColumn(),'cross-source concurrency retains one active registration');
    }
    private function workers(string $mode,string $id,int $version,string $actor,string $officer,?string $secondId=null):array
    {
        $this->pdo->beginTransaction();$this->pdo->prepare('SELECT id FROM officer WHERE id=? FOR UPDATE')->execute([$officer]);$workers=[];
        try{for($i=0;$i<2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,'-d','xdebug.mode=off',__FILE__,'--worker',$this->name,$mode,$i===1&&$secondId!==null?$secondId:$id,(string)$version,$actor],[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);if(!is_resource($process))throw new RuntimeException('Cannot start isolated concurrent worker.');$workers[]=[$process,$pipes];}}finally{$this->pdo->rollBack();}
        $out=[];foreach($workers as [$p,$pipes]){$out[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$this->same(0,proc_close($p),'concurrent worker completed: '.$err);}return $out;
    }
    public static function worker(array $argv):void
    {
        $pdo=self::connect($argv[2]);(new ReflectionProperty(Database::class,'pdo'))->setValue(null,$pdo);$test=new self();$test->pdo=$pdo;$test->context($argv[6]);$s=new ArpaHistoricalAppointmentApprovalService($pdo);
        try{if($argv[3]==='register')echo $s->register($argv[4],$test->input(),$argv[6])['case']['id'];elseif($argv[3]==='submit')echo $s->submit($argv[4],(int)$argv[5],$argv[6])['case']['workflow_status'];else echo $s->review($argv[4],'APPROVE',(int)$argv[5],$argv[6],'Synthetic concurrent review','Synthetic evidence')['case']['workflow_status'];}catch(DomainException){echo 'BLOCKED';}
    }
    private function source(string $type='PERMANENT',?string $officer=null,string $from='2020-01-01',?string $to=null,?string $division=null):array
    {
        if($officer===null){$officer=$this->uuid();$this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,arpa_service_permanency,primary_designation_id) VALUES(?,'UAT-HIST','Synthetic Historical Officer','PERMANENT_IN_SERVICE','historical-test-arpa')")->execute([$officer]);$this->pdo->prepare("INSERT INTO officer_office_assignment VALUES(?,?,'1b000000-0000-4000-8000-000000000001',1,'APPROVED','2020-01-01',NULL)")->execute([$this->uuid(),$officer]);}
        if($division===null){$division=$this->uuid();$this->pdo->prepare("INSERT INTO location VALUES(?,'11000000-0000-4000-8000-000000000002','UAT-HIST-DIV','Synthetic Historical Division')")->execute([$division]);$this->pdo->prepare("INSERT INTO location_relationship VALUES(?,? ,?,'ASC_ARPA_DIVISION',1,'APPROVED','2024-01-05',NULL)")->execute([$this->uuid(),self::ASC,$division]);}
        $request=$this->uuid();$appointment=$this->uuid();$correction=$this->uuid();
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,legacy_history_only,legacy_exception,origin_metadata_json) VALUES(?,'LEGACY_IMPORT','APPOINTMENT',?,?,?,?,?,'CREATED',0,1,'{\"source\":\"synthetic\"}')")->execute([$request,$officer,$type,self::ASC,$division,$from]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,service_permanency_snapshot,service_permanency_source,asc_location_id,arpa_division_location_id,effective_from,legacy_history_only,legacy_exception,asc_name_snapshot,arpa_name_snapshot,approval_timestamp_provenance) VALUES(?,'LEGACY_IMPORT',?,?,?,'PERMANENT_IN_SERVICE','EXACT_PERMANENTED_DATE',?,?,?,0,1,'ASC One','Synthetic Division','UNAVAILABLE_FROM_LEGACY_SOURCE')")->execute([$appointment,$request,$officer,$type,self::ASC,$division,$from]);
        $this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,related_appointment_ids_json,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin) VALUES(?,?,'LEGACY_HISTORICAL_EXCEPTION',?,?,?,JSON_ARRAY(?),?,?,'RESOLVE_CANONICAL_ASSIGNMENT','RESOLVED_BY_CORRECTION','Synthetic source evidence','{}','{}','LEGACY_IMPORT')")->execute([$correction,'LEGACY_HISTORICAL_EXCEPTION:'.$request,$officer,$appointment,$request,$appointment,self::ASC,self::OWNER]);
        $this->pdo->prepare("INSERT INTO legacy_arpa_appointment_source_reference(id,target_appointment_request_id,target_appointment_id,source_system,source_table,legacy_appointment_id) VALUES(?,?,?,'synthetic','tbl_officer_apoint','UAT-only')")->execute([$this->uuid(),$request,$appointment]);
        if($to!==null)$this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_source,data_correction_id,context_snapshot_json,approval_timestamp_provenance) VALUES(?,'LEGACY_IMPORT',?,?,?,'18000000-0000-4000-8000-000000000001','DATA_ISSUE_CORRECTION',?,'{}','UNAVAILABLE_FROM_LEGACY_SOURCE')")->execute([$this->uuid(),$appointment,$request,$to,$correction]);
        return compact('request','appointment','correction','officer','division');
    }
    private function advance(array $c,int $actor,string $action):array{$this->context(self::actor($actor));return $this->service->review($c['case']['id'],$action,(int)$c['case']['version'],self::actor($actor),'Synthetic reviewed evidence','Synthetic evidence reference');}
    private function complete(array $c):array{foreach([[4,'VERIFY'],[5,'APPROVE'],[6,'VERIFY'],[7,'APPROVE'],[8,'VERIFY'],[9,'APPROVE']] as [$n,$action]){if($n===9){$this->context(self::actor(8),'NATIONAL_ADMIN');$this->fails(fn()=>$this->service->review($c['case']['id'],'APPROVE',(int)$c['case']['version'],self::actor(8),'Approve','Proof'),'National verifier cannot approve by switching roles',403);}$c=$this->advance($c,$n,$action);$this->same(ArpaAppointmentRulesForTest::status($n),$c['case']['workflow_status'],'stage order '.$n);}return $c;}
    private function context(string $actor=self::OWNER,?string $role=null):void{if($role===null&&in_array($actor,[self::OWNER,self::actor(4)],true))$role='ASC_SUBJECT_OFFICER';if($role===null&&$actor===self::actor(8))$role='NATIONAL_SUBJECT_OFFICER';$_SESSION=['user_id'=>$actor,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();$svc=new UserContextService($this->pdo);foreach($svc->availableContexts($actor) as $c)if($role===null||$c['role_code']===$role){$svc->select($actor,$c['role_assignment_id'],$c['scope_assignment_id']);Auth::forgetRequestCache();return;}throw new RuntimeException('Fixture context unavailable.');}
    private static function actor(int $n):string{return '00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT);}
    private function input():array{return ['reason'=>'Synthetic historical evidence confirms the recorded appointment','evidence_reference'=>'Synthetic appointment letter reference'];}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function row(string $table,string $id):array{$s=$this->pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC);}
    private function operational():array{$out=[];foreach(['officer','arpa_division_appointment','arpa_division_appointment_request','arpa_division_appointment_closure','arpa_appointment_data_correction','arpa_appointment_workflow_action','legacy_arpa_appointment_source_reference','audit_event'] as $table)$out[$table]=$this->pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();return $out;}
    private static function connect(string $db):PDO{if(!preg_match('/^dems_test_hist_approval_[a-f0-9]{10}$/D',$db))throw new RuntimeException('Unsafe test database.');$c=config('database');return new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port'],$db),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.'; expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function fails(callable $work,string $message,?int $code=null,bool $any=false):void{$this->assertions++;try{$work();}catch(Throwable $e){if(!$any&&!$e instanceof DomainException)throw $e;if($code!==null&&(int)$e->getCode()!==$code)throw new RuntimeException($message.'; unexpected code '.$e->getCode().' '.$e->getMessage());return;}throw new RuntimeException($message.'; expected rejection');}
}
final class ArpaAppointmentRulesForTest{public static function status(int $actor):string{return [4=>'ASC_VERIFIED',5=>'ASC_APPROVED',6=>'DISTRICT_VERIFIED',7=>'DISTRICT_APPROVED',8=>'NATIONAL_VERIFIED',9=>'NATIONAL_APPROVED'][$actor];}}
if(defined('ARPA_HISTORICAL_APPROVAL_FIXTURE_ONLY')&&ARPA_HISTORICAL_APPROVAL_FIXTURE_ONLY)return;
try{if(($argv[1]??'')==='--worker')ArpaHistoricalAppointmentApprovalIsolatedTest::worker($argv);else(new ArpaHistoricalAppointmentApprovalIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
