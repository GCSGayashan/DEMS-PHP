<?php
declare(strict_types=1);

define('ARPA_HISTORICAL_APPROVAL_FIXTURE_ONLY',true);
require __DIR__.'/ArpaHistoricalAppointmentApprovalIsolatedTest.php';

use App\Core\{Auth,Database};
use App\Services\{ArpaAppointmentApprovalQualificationPolicy as Policy,ArpaHistoricalAppointmentApprovalService,ArpaHistoricalApprovalReadService,ArpaAppointmentReadService};

/** Counts actual PDO SELECT preparations and snapshot boundaries; can inject failures. */
final class ApprovalQualificationCountingPdo extends PDO
{
    public int $reads=0;
    public int $starts=0;
    public int $rollbacks=0;
    public ?string $failSql=null;
    public bool $failAfterExecution=false;
    public function resetMetrics():void{$this->reads=$this->starts=$this->rollbacks=0;}
    private function fail(string $sql):void
    {
        if($this->failSql!==null&&str_contains($sql,$this->failSql)){$this->failSql=null;throw new RuntimeException('Synthetic connection failure.');}
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false{$this->fail($query);if(preg_match('/^(SELECT|WITH)\b/i',$query))$this->reads++;return parent::prepare($query,$options);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{$this->fail($query);if(preg_match('/^(SELECT|WITH)\b/i',$query))$this->reads++;return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);}
    public function exec(string $statement):int|false
    {
        if(str_starts_with($statement,'START TRANSACTION'))$this->starts++;
        if(!$this->failAfterExecution)$this->fail($statement);
        $result=parent::exec($statement);
        if($this->failAfterExecution)$this->fail($statement);
        return $result;
    }
    public function rollBack():bool{$this->rollbacks++;return parent::rollBack();}
}

/** Uses the existing historical lifecycle fixtures, never the working database. */
final class ArpaAppointmentApprovalQualificationIsolatedTest
{
    private const ASC='10000000-0000-4000-8000-000000000001';
    private const OTHER_ASC='10000000-0000-4000-8000-000000000002';
    private const OWNER='00000000-0000-4000-8000-000000000001';
    private PDO $pdo;
    private Policy $policy;
    private ArpaHistoricalAppointmentApprovalIsolatedTest $fixture;
    private ArpaHistoricalAppointmentApprovalService $service;
    private int $assertions=0;

    public function run():void
    {
        $c=config('database');
        if(!in_array($c['host'],['localhost','127.0.0.1'],true)||!str_starts_with($c['database'],'dems_test_'))throw new RuntimeException('Explicit local isolated-test configuration required.');
        $server=new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4',$c['host'],$c['port']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $name='dems_test_approval_qualification_'.bin2hex(random_bytes(5));$created=false;
        $property=new ReflectionProperty(Database::class,'pdo');$old=$property->getValue();$session=$_SESSION??[];
        try{
            $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
            $this->pdo=new ApprovalQualificationCountingPdo(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port'],$name),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
            if($this->pdo->query('SELECT DATABASE()')->fetchColumn()!==$name||!str_starts_with($name,'dems_test_approval_qualification_'))throw new RuntimeException('Wrong target before fixture writes.');
            $property->setValue(null,$this->pdo);
            $draft=new ArpaAppointmentCorrectionAmendmentDraftTest();(new ReflectionProperty($draft,'pdo'))->setValue($draft,$this->pdo);
            $this->invoke($draft,'createFixtureSchema');
            $this->pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/062_arpa_appointment_correction_amendment_foundation.sql'));
            $this->invoke($draft,'insertFixtures');$this->invoke($draft,'exerciseHistoricalPeerDecisions');
            if(!(new ArpaHistoricalApprovalReadService($this->pdo))->available())$this->pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/067_arpa_historical_approval_foundation.sql'));
            $this->pdo->exec("INSERT INTO designation VALUES('historical-test-arpa','ARPA_OFFICER');ALTER TABLE arpa_division_appointment ADD UNIQUE KEY uq_test_request(request_id);
                ALTER TABLE arpa_division_appointment_request ADD created_by CHAR(36) NULL,ADD finalized_by CHAR(36) NULL,ADD finalized_at DATETIME NULL");
            $this->service=new ArpaHistoricalAppointmentApprovalService($this->pdo);$this->policy=new Policy($this->pdo);
            $this->fixture=new ArpaHistoricalAppointmentApprovalIsolatedTest();
            foreach(['pdo'=>$this->pdo,'service'=>$this->service] as $key=>$value)(new ReflectionProperty($this->fixture,$key))->setValue($this->fixture,$value);
            $this->originalWorkflow();$this->historicalWorkflow();$this->correctionLineage();$this->historicalBatchScale();$this->baselineBatchParity();$this->scopeAndReadOnly();$this->batches();$this->transactionFailures();$this->missingSchema();
            echo 'ArpaAppointmentApprovalQualificationIsolatedTest: '.$this->assertions.' assertions passed; MySQL '.$server->getAttribute(PDO::ATTR_SERVER_VERSION)."; disposable database removed.\n";
        }finally{$property->setValue(null,$old);$_SESSION=$session;Auth::forgetRequestCache();if($created)$server->exec('DROP DATABASE `'.$name.'`');}
    }

    private function originalWorkflow():void
    {
        $s=$this->source();$this->native($s);
        foreach(['CREATED','SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','RETURNED'] as $status){
            $this->set('arpa_division_appointment_request',$s['request'],'workflow_status',$status);
            $this->classification($s,Policy::PENDING_APPROVAL,'native '.$status.' is not completed National approval');
        }
        $this->set('arpa_division_appointment',$s['appointment'],'approved_at','2026-01-01 12:00:00');
        $this->classification($s,Policy::PENDING_APPROVAL,'canonical approved_at and ASC activation are insufficient');
        $this->set('arpa_division_appointment_request',$s['request'],'workflow_status','NATIONAL_APPROVED');
        $this->classification($s,Policy::MANUAL_REVIEW,'final status without final action fails closed');
        $event=$this->national($s);
        $this->classification($s,Policy::APPROVED,'native completed National approval');
        $this->same('ORIGINAL_WORKFLOW',$this->read($s)['approval_source'],'native evidence source');
        $this->set('arpa_appointment_workflow_action',$event,'stage','ASC');
        $this->classification($s,Policy::MANUAL_REVIEW,'ASC action cannot attest National approval');
        $this->set('arpa_appointment_workflow_action',$event,'stage','NATIONAL');
        $this->set('arpa_appointment_workflow_action',$event,'user_id',self::OWNER);
        $this->classification($s,Policy::MANUAL_REVIEW,'final actor mismatch fails closed');
        $this->set('arpa_appointment_workflow_action',$event,'user_id',$this->actor(9));
        $this->set('arpa_appointment_workflow_action',$event,'action_at',null);
        $this->classification($s,Policy::MANUAL_REVIEW,'native missing actual action time fails closed');
        $this->set('arpa_appointment_workflow_action',$event,'action_at','2026-01-01 12:00:00');
        $duplicate=$this->national($s);$this->classification($s,Policy::MANUAL_REVIEW,'ambiguous duplicate final approval');
        $this->pdo->prepare('DELETE FROM arpa_appointment_workflow_action WHERE id=?')->execute([$duplicate]);
        $this->classification($s,Policy::APPROVED,'unambiguous native final evidence restored');
        $this->set('arpa_division_appointment_request',$s['request'],'deleted_at','2026-01-02 00:00:00');
        $this->classification($s,Policy::MANUAL_REVIEW,'deleted request never approved');
        $this->set('arpa_division_appointment_request',$s['request'],'deleted_at',null);
        $this->set('arpa_division_appointment',$s['appointment'],'legacy_history_only',1);
        $this->classification($s,Policy::HISTORICAL_ONLY,'history-only overrides workflow completion');
        $this->set('arpa_division_appointment',$s['appointment'],'legacy_history_only',0);
        $this->set('arpa_division_appointment_request',$s['request'],'officer_id',self::OWNER);
        $this->classification($s,Policy::MANUAL_REVIEW,'request/canonical identity mismatch');

        $imported=$this->source();
        $this->set('arpa_division_appointment_request',$imported['request'],'workflow_status','DISTRICT_APPROVED');
        $this->classification($imported,Policy::PENDING_APPROVAL,'original imported District approval is incomplete');
        $this->national($imported,'LEGACY_IMPORT');
        $this->classification($imported,Policy::APPROVED,'imported original National action with unavailable legacy timestamp');
        $this->same(null,$this->row('arpa_division_appointment',$imported['appointment'])['approved_at'],'no fabricated canonical approval time');
        $this->same('NATIONAL_APPROVED',$this->row('arpa_division_appointment_request',$imported['request'])['workflow_status'],'original completed status unchanged');
        $closed=$this->source('PERMANENT',null,'2020-01-01','2020-12-31');$this->native($closed);$this->national($closed);
        $this->classification($closed,Policy::APPROVED,'approval completion is independent of closed period');
        $future=$this->source('PERMANENT',null,'2099-01-01');$this->native($future);$this->national($future);
        $this->classification($future,Policy::APPROVED,'future approved period still requires consumer date filtering');
        foreach(['ACTING','DUTY_COVERING','ATTEND_TO_DUTY'] as $type){$typed=$this->source($type);$this->native($typed);$this->national($typed);$this->classification($typed,Policy::APPROVED,'completed original workflow for '.$type);}
    }

    private function historicalWorkflow():void
    {
        $s=$this->source();$this->classification($s,Policy::PENDING_APPROVAL,'canonical imported CREATED alone is not approved');
        $this->same('HISTORICAL_CASE_NOT_REGISTERED',$this->read($s)['reason'],'missing historical case explained');
        $this->invoke($this->fixture,'context');$c=$this->service->register($s['correction'],$this->input(),self::OWNER);
        $this->classification($s,Policy::PENDING_APPROVAL,'historical Draft is pending');
        $c=$this->service->submit($c['case']['id'],0,self::OWNER);
        $this->classification($s,Policy::PENDING_APPROVAL,'historical Submitted is pending');
        foreach([[4,'VERIFY'],[5,'APPROVE'],[6,'VERIFY'],[7,'APPROVE'],[8,'VERIFY'],[9,'APPROVE']] as [$actor,$action]){
            $c=$this->invoke($this->fixture,'advance',$c,$actor,$action);
            $this->classification($s,$actor===9?Policy::APPROVED:Policy::PENDING_APPROVAL,'actual historical stage '.$c['case']['workflow_status']);
        }
        $this->same('HISTORICAL_APPROVAL',$this->read($s)['approval_source'],'historical evidence source');
        $this->same('CREATED',$this->row('arpa_division_appointment_request',$s['request'])['workflow_status'],'historical approval never changes imported original status');
        $this->same(null,$this->row('arpa_division_appointment',$s['appointment'])['approved_at'],'historical approval does not fabricate operational approval time');
        foreach([
            ['arpa_division_appointment_request',$s['request'],'version',1],
            ['arpa_division_appointment',$s['appointment'],'effective_from','2020-01-02'],
            ['arpa_appointment_data_correction',$s['correction'],'evidence_reference','New conflicting evidence'],
        ] as [$table,$id,$field,$value]){
            $old=$this->row($table,$id)[$field];$this->set($table,$id,$field,$value);
            $this->classification($s,Policy::MANUAL_REVIEW,'complete baseline detects changed '.$field);
            $this->set($table,$id,$field,$old);$this->classification($s,Policy::APPROVED,'exact baseline restored');
        }
        $caseId=$c['case']['id'];$this->set('arpa_appointment_historical_approval_case',$caseId,'officer_id',$this->source()['officer']);
        $this->classification($s,Policy::MANUAL_REVIEW,'unrelated case identity cannot approve appointment');
        $this->set('arpa_appointment_historical_approval_case',$caseId,'officer_id',$s['officer']);
        $this->set('arpa_appointment_historical_approval_case',$caseId,'baseline_hash',str_repeat('0',64));
        $this->classification($s,Policy::MANUAL_REVIEW,'tampered baseline hash fails closed');
        $this->set('arpa_appointment_historical_approval_case',$caseId,'baseline_hash',$c['case']['baseline_hash']);
        $this->classification($s,Policy::APPROVED,'valid completed baseline restored');
        $successor=$this->uuid();
        $this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin,supersedes_correction_id) SELECT ?,CONCAT(issue_row_key,':replacement'),issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin,id FROM arpa_appointment_data_correction WHERE id=?")->execute([$successor,$s['correction']]);
        $this->classification($s,Policy::PENDING_APPROVAL,'superseded source approval does not approve new effective decision');
        $this->same('HISTORICAL_CASE_NOT_REGISTERED',$this->read($s)['reason'],'replacement decision needs its own applicable approval');

        $rejected=$this->source();$this->invoke($this->fixture,'context');$r=$this->service->register($rejected['correction'],$this->input(),self::OWNER);$r=$this->service->submit($r['case']['id'],0,self::OWNER);$r=$this->invoke($this->fixture,'advance',$r,4,'VERIFY');$this->invoke($this->fixture,'advance',$r,5,'REJECT');
        $this->classification($rejected,Policy::MANUAL_REVIEW,'rejected historical case is not approved');
        $returned=$this->source();$this->invoke($this->fixture,'context');$r=$this->service->register($returned['correction'],$this->input(),self::OWNER);$r=$this->service->submit($r['case']['id'],0,self::OWNER);$r=$this->invoke($this->fixture,'advance',$r,4,'VERIFY');$this->invoke($this->fixture,'advance',$r,5,'RETURN_FOR_CORRECTION');
        $this->classification($returned,Policy::PENDING_APPROVAL,'returned case is pending, not completed');
        $this->set('arpa_appointment_historical_approval_case',$r['case']['id'],'workflow_status','NATIONAL_APPROVED');
        $this->classification($returned,Policy::MANUAL_REVIEW,'status alone without final historical action is insufficient');
        $missing=$this->source();$this->set('arpa_appointment_data_correction',$missing['correction'],'request_id',null);
        $this->classification($missing,Policy::MANUAL_REVIEW,'matching correction identity required');
        $this->set('arpa_appointment_data_correction',$missing['correction'],'correction_action','KEEP_AS_HISTORICAL_EXCEPTION');
        $this->classification($missing,Policy::MANUAL_REVIEW,'KEEP cannot attest canonical approval');

        // A real completed, closed historical period remains approved, and closures are baselined.
        $ended=$this->source('PERMANENT',null,'2020-01-01','2020-12-31');$this->invoke($this->fixture,'context');$e=$this->service->register($ended['correction'],$this->input(),self::OWNER);$e=$this->service->submit($e['case']['id'],0,self::OWNER);$this->invoke($this->fixture,'complete',$e);
        $this->classification($ended,Policy::APPROVED,'valid closed historical National completion');
        $this->pdo->prepare("UPDATE arpa_division_appointment_closure SET effective_to='2020-12-30' WHERE appointment_id=?")->execute([$ended['appointment']]);
        $this->classification($ended,Policy::MANUAL_REVIEW,'changed recorded closure invalidates baseline');
        $this->pdo->prepare("UPDATE arpa_division_appointment_closure SET effective_to='2020-12-31' WHERE appointment_id=?")->execute([$ended['appointment']]);
        $this->classification($ended,Policy::APPROVED,'restored recorded closure');
        $this->completed=$ended;
    }

    private function correctionLineage():void
    {
        // Mere proposals, including rejected/withdrawn ones, cannot supersede an effective decision.
        $s=$this->completed;$proposal=$this->amendment($s);
        foreach(['DRAFT','NATIONAL_VERIFIED','REJECTED','WITHDRAWN'] as $status){
            $this->set('arpa_appointment_correction_amendment_request',$proposal,'workflow_status',$status);
            $this->classification($s,Policy::APPROVED,'unapplied '.$status.' amendment does not retire original approval');
        }
        $s=$this->source();$amendment=$this->amendment($s);$successor=$this->uuid();
        $this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin,supersedes_correction_id,amendment_request_id) SELECT ?,CONCAT(issue_row_key,':amended'),issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin,id,? FROM arpa_appointment_data_correction WHERE id=?")->execute([$successor,$amendment,$s['correction']]);
        $this->pdo->prepare("UPDATE arpa_appointment_correction_amendment_request SET workflow_status='NATIONAL_APPROVED',applied_correction_id=? WHERE id=?")->execute([$successor,$amendment]);
        $this->invoke($this->fixture,'context');$c=$this->service->register($successor,$this->input(),self::OWNER);$c=$this->service->submit($c['case']['id'],0,self::OWNER);$this->invoke($this->fixture,'complete',$c);
        $this->classification($s,Policy::APPROVED,'effective applied amendment lineage with its own completed historical approval');
        $this->set('arpa_appointment_correction_amendment_request',$amendment,'workflow_status','REJECTED');
        $this->classification($s,Policy::MANUAL_REVIEW,'invalid applied lineage fails closed');
        $this->set('arpa_appointment_correction_amendment_request',$amendment,'workflow_status','NATIONAL_APPROVED');
        $this->set('arpa_appointment_correction_amendment_request',$amendment,'applied_correction_id',null);
        $this->classification($s,Policy::MANUAL_REVIEW,'unlinked amendment cannot attest replacement decision');
        $this->set('arpa_appointment_correction_amendment_request',$amendment,'applied_correction_id',$successor);
        $this->classification($s,Policy::APPROVED,'explicit validated lineage restored');
        $foreign=$this->source();$this->set('arpa_appointment_data_correction',$successor,'supersedes_correction_id',$foreign['correction']);
        $this->classification($s,Policy::MANUAL_REVIEW,'cross-appointment correction predecessor fails closed');
        $this->set('arpa_appointment_data_correction',$successor,'supersedes_correction_id',$s['correction']);
        $this->classification($s,Policy::APPROVED,'exact predecessor restored');
        $pending=$this->source();$this->set('arpa_appointment_data_correction',$pending['correction'],'appointment_id',null);$this->set('arpa_appointment_data_correction',$pending['correction'],'request_id',null);
        $this->classification($pending,Policy::MANUAL_REVIEW,'imported CREATED without correction proof fails closed');
    }

    private function amendment(array $s):string
    {
        $id=$this->uuid();$this->pdo->prepare("INSERT INTO arpa_appointment_correction_amendment_request(id,source_correction_id,appointment_id,officer_id,asc_location_id,proposed_action,baseline_json,baseline_hash,proposed_json,correction_reason,created_by) VALUES(?,?,?,?,?,'RESOLVE_CANONICAL_ASSIGNMENT','{}',?,'{}','Synthetic amendment evidence',?)")->execute([$id,$s['correction'],$s['appointment'],$s['officer'],self::ASC,hash('sha256','{}'),self::OWNER]);return $id;
    }

    private array $completed;
    private function historicalBatchScale():void
    {
        $sources=[];
        for($i=0;$i<3;$i++){
            $s=$this->source('PERMANENT',null,'2020-01-01','2020-12-31');
            $this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,record_origin,new_status) VALUES(?,'VERIFY','ASC',?,'LEGACY_IMPORT','ASC_VERIFIED')")->execute([$s['request'],self::OWNER]);
            $this->invoke($this->fixture,'context');$c=$this->service->register($s['correction'],$this->input(),self::OWNER);$c=$this->service->submit($c['case']['id'],0,self::OWNER);$this->invoke($this->fixture,'complete',$c);$sources[]=$s;
        }
        $ids=array_column($sources,'appointment');$before=$this->snapshot();$this->pdo->resetMetrics();$one=$this->policy->qualifyBatch([$ids[0]],[self::ASC]);$queries=$this->pdo->reads;
        $this->pdo->resetMetrics();$many=$this->policy->qualifyBatch($ids,[self::ASC]);
        $this->same($queries,$this->pdo->reads,'complete historical baselines use constant queries within a batch');$this->same(1,$this->pdo->starts,'historical batch uses one snapshot');
        foreach($many as $id=>$q){$this->same(Policy::APPROVED,$q['classification'],'closed historical approval with original workflow evidence');$this->same($this->policy->qualify($id,[self::ASC]),$q,'historical single/batch parity');}
        $this->same($one[$ids[0]],$many[$ids[0]],'same full historical evidence in multi-record batch');
        $this->same($before,$this->snapshot(),'historical batch is read-only');
        echo 'Historical query evidence: 1/3 completed cases = '.$queries." SELECTs, including full baseline evidence.\n";
    }

    private function baselineBatchParity():void
    {
        $cases=$this->pdo->query("SELECT id FROM arpa_appointment_historical_approval_case WHERE workflow_status='NATIONAL_APPROVED' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $check=function()use($cases):array{
            return $this->policy->withQualifiedScan([self::ASC],function()use($cases):array{
                $batch=$this->service->hasCurrentApprovedBaselines($cases);
                foreach($cases as $id){try{$single=$this->service->hasCurrentApprovedBaseline($id);}catch(DomainException){$single=false;}$this->same($single,$batch[$id],'batched complete bundle matches original individual reader');}
                return $batch;
            });
        };
        $check();$s=$this->completed;
        $source=$this->pdo->prepare('SELECT id FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=?');$source->execute([$s['request']]);$sourceId=$source->fetchColumn();
        foreach([
            ['legacy_arpa_appointment_source_reference',$sourceId,'source_table','changed_provenance'],
            ['officer',$s['officer'],'arpa_service_permanency','NOT_PERMANENT_IN_SERVICE'],
            ['location_relationship',$this->pdo->query("SELECT id FROM location_relationship WHERE child_location_id='{$s['division']}' LIMIT 1")->fetchColumn(),'effective_from','2020-02-01'],
        ] as [$table,$id,$field,$value]){
            $old=$this->row($table,$id)[$field];$this->set($table,$id,$field,$value);$check();
            $this->classification($s,Policy::MANUAL_REVIEW,'batch includes complete '.$table.' evidence');
            $this->set($table,$id,$field,$old);$this->classification($s,Policy::APPROVED,'restored complete evidence');
        }
        $this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,record_origin,new_status) VALUES(?,'VERIFY','ASC',?,'LEGACY_IMPORT','ASC_VERIFIED')")->execute([$s['request'],self::OWNER]);$event=$this->pdo->lastInsertId();
        $check();$this->classification($s,Policy::MANUAL_REVIEW,'batch includes original workflow evidence');$this->pdo->prepare('DELETE FROM arpa_appointment_workflow_action WHERE id=?')->execute([$event]);
        $this->classification($s,Policy::APPROVED,'original workflow evidence restored');
        $relatedRequest=$this->uuid();
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status) VALUES(?,'NATIVE','APPOINTMENT',?,'ACTING',?,?,'2020-06-01','SUBMITTED')")->execute([$relatedRequest,$s['officer'],self::ASC,$s['division']]);
        $check();$this->classification($s,Policy::MANUAL_REVIEW,'new related reservation changes the full baseline');
        $this->pdo->prepare("UPDATE arpa_division_appointment_request SET requested_effective_from='2021-01-01' WHERE id=?")->execute([$relatedRequest]);
        $this->classification($s,Policy::APPROVED,'non-overlapping request is outside the attested period');
        $relatedAppointment=$this->uuid();
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,asc_location_id,arpa_division_location_id,effective_from) VALUES(?,'NATIVE',?,?,'ACTING',?,?,'2020-06-01')")->execute([$relatedAppointment,$relatedRequest,$s['officer'],self::ASC,$s['division']]);
        $check();$this->classification($s,Policy::MANUAL_REVIEW,'new overlapping canonical evidence changes the full baseline');
        $this->pdo->prepare('DELETE FROM arpa_division_appointment WHERE id=?')->execute([$relatedAppointment]);$this->pdo->prepare('DELETE FROM arpa_division_appointment_request WHERE id=?')->execute([$relatedRequest]);
        $this->classification($s,Policy::APPROVED,'related fixture removed without changing original data');
        $unrelated=$this->uuid();
        $this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin) SELECT ?,CONCAT(issue_row_key,':other'),issue_type,officer_id,appointment_id,request_id,asc_location_id,corrected_by,'SET_EFFECTIVE_TO',resolution_status,correction_reason,before_json,after_json,record_origin FROM arpa_appointment_data_correction WHERE id=?")->execute([$unrelated,$s['correction']]);
        $check();$this->classification($s,Policy::MANUAL_REVIEW,'unrelated correction is not a supersession but does change the complete baseline');
        $this->pdo->prepare('DELETE FROM arpa_appointment_data_correction WHERE id=?')->execute([$unrelated]);$this->classification($s,Policy::APPROVED,'complete correction history restored');
    }

    private function batches():void
    {
        $pdo=$this->pdo;$s=$this->source();$this->native($s);$this->national($s);
        $pending=$this->source();$this->native($pending);$this->set('arpa_division_appointment_request',$pending['request'],'workflow_status','ASC_APPROVED');
        $history=$this->source();$this->set('arpa_division_appointment',$history['appointment'],'legacy_history_only',1);
        $foreign=$this->source();foreach(['arpa_division_appointment_request'=>$foreign['request'],'arpa_division_appointment'=>$foreign['appointment']] as $table=>$id)$this->set($table,$id,'asc_location_id',self::OTHER_ASC);
        $ids=[$s['appointment'],$pending['appointment'],$history['appointment'],$foreign['appointment'],$this->completed['appointment']];
        $before=$this->snapshot();$mixed=$this->policy->qualifyBatch([...$ids,$s['appointment']],[self::ASC]);
        $this->same(4,count($mixed),'mixed batch deduplicates and excludes inaccessible ASC');
        foreach($ids as $id)$this->same($this->policy->qualify($id,[self::ASC]),$mixed[$id]??null,'mixed single/batch equality');
        $this->same($this->read($s),$this->policy->qualify(strtoupper($s['appointment']),[self::ASC]),'single API preserves case-insensitive UUID lookup');
        $pdo->resetMetrics();$this->same([],$this->policy->qualifyBatch([],[self::ASC]),'empty input');$this->same([],$this->policy->qualifyBatch($ids,[]),'empty scope');
        $this->same(0,$pdo->reads,'empty inputs issue no SELECTs');$this->same(0,$pdo->starts,'empty inputs start no transactions');
        foreach([0,501] as $size){try{$this->policy->qualifyBatch($ids,[self::ASC],$size);throw new RuntimeException('Expected invalid batch size.');}catch(DomainException $e){$this->same(422,$e->getCode(),'bounded batch-size validation');}}
        $this->same($before,$this->snapshot(),'mixed qualification never changes any records');

        // High-volume synthetic proof rows; fixture writes occur before read metrics start.
        $bulk=[];
        $request=$pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,finalized_by,finalized_at) SELECT ?,'NATIVE','APPOINTMENT',officer_id,appointment_type,asc_location_id,arpa_division_location_id,effective_from,'NATIONAL_APPROVED',?,'2026-01-01 12:00:00' FROM arpa_division_appointment WHERE id=?");
        $appointment=$pdo->prepare('INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,asc_location_id,arpa_division_location_id,effective_from) SELECT ?,record_origin,?,officer_id,appointment_type,asc_location_id,arpa_division_location_id,effective_from FROM arpa_division_appointment WHERE id=?');
        $event=$pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,action_at,record_origin,previous_status,new_status,timestamp_provenance) VALUES(?,'APPROVE','NATIONAL',?,'2026-01-01 12:00:00','NATIVE','NATIONAL_VERIFIED','NATIONAL_APPROVED','NATIVE_RECORDED')");
        for($i=0;$i<525;$i++){$r=$this->uuid();$a=$this->uuid();$request->execute([$r,$this->actor(9),$s['appointment']]);$appointment->execute([$a,$r,$s['appointment']]);$event->execute([$r,$this->actor(9)]);$bulk[]=$a;}
        $before=$this->snapshot();$pdo->resetMetrics();$this->policy->qualifyBatch([$bulk[0]],[self::ASC]);$one=$pdo->reads;
        $pdo->resetMetrics();$hundred=$this->policy->qualifyBatch(array_slice($bulk,0,100),[self::ASC]);
        $this->same($one,$pdo->reads,'one and 100 native records use the same query count');$this->same(1,$pdo->starts,'one snapshot for a batch');$this->same(1,$pdo->rollbacks,'one cleanup for a batch');
        $this->same(100,count(array_filter($hundred,fn($q)=>$q['classification']===Policy::APPROVED)),'all 100 native proofs qualified');
        $pdo->resetMetrics();$large=$this->policy->qualifyBatch($bulk,[self::ASC],100);
        $this->same(525,count($large),'large input crosses six bounded chunks without losing rows');$this->same(1,$pdo->starts,'large input uses one transaction, not one per appointment or chunk');
        $this->same(1+6*($one-1),$pdo->reads,'query growth follows chunks, not appointments');
        foreach($large as $q)$this->same(Policy::APPROVED,$q['classification'],'large batch approval evidence');
        echo 'Qualification query evidence: 1/100 native records = '.$one.' SELECTs; 525 records / 100-size chunks = '.$pdo->reads." SELECTs, one snapshot.\n";

        // Fully qualify before applying OFFSET/LIMIT, and query the projection in the SAME snapshot.
        $page=$this->policy->withQualifiedScan([self::ASC],function(iterable $batches)use($bulk):array{
            $eligible=[];$scope=array_fill_keys($bulk,true);
            foreach($batches as $batch){$this->same(true,count($batch)<=100,'scan bounds each evidence batch');foreach($batch as $id=>$q)if(isset($scope[$id])&&$q['classification']===Policy::APPROVED)$eligible[]=$id;}
            $this->same(true,$this->pdo->inTransaction(),'projection/count adapter retains consistent snapshot');
            $rows=$this->pdo->prepare('SELECT id FROM arpa_division_appointment WHERE id IN('.implode(',',array_fill(0,count($eligible),'?')).') ORDER BY id LIMIT 2 OFFSET 1');$rows->execute($eligible);
            return ['count'=>count($eligible),'page'=>$rows->fetchAll(PDO::FETCH_COLUMN)];
        },100);
        sort($bulk,SORT_STRING);$this->same(525,$page['count'],'full qualified count before pagination');$this->same(array_slice($bulk,1,2),$page['page'],'page uses qualification before LIMIT/OFFSET');
        $this->same(false,$pdo->inTransaction(),'scan cleans up snapshot');
        $this->same($before,$this->snapshot(),'large scan/count/page leaves all data unchanged');
        $c=config('database');$db=$pdo->query('SELECT DATABASE()')->fetchColumn();
        if(!str_starts_with($db,'dems_test_approval_qualification_'))throw new RuntimeException('Unsafe concurrent fixture target.');
        $other=new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$db};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->same($db,$other->query('SELECT DATABASE()')->fetchColumn(),'concurrent writer verified isolated target');
        $target=end($bulk);$requestId=$this->row('arpa_division_appointment',$target)['request_id'];$updated=false;$observed=null;
        try{
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->policy->withQualifiedScan([self::ASC],function($batches)use($other,$requestId,$target,&$updated,&$observed){
                foreach($batches as $batch){
                    if(!$updated){$this->same(false,isset($batch[$target]),'concurrent target belongs to a later batch');$other->prepare("UPDATE arpa_division_appointment_request SET workflow_status='ASC_APPROVED' WHERE id=?")->execute([$requestId]);$updated=true;}
                    if(isset($batch[$target]))$observed=$batch[$target]['classification'];
                }
            },100);
            $this->same(Policy::APPROVED,$observed,'all chunks see the same pre-update snapshot');
            $this->same(Policy::PENDING_APPROVAL,$this->policy->qualify($target,[self::ASC])['classification'],'new snapshot observes concurrent status change');
        }finally{$other->prepare("UPDATE arpa_division_appointment_request SET workflow_status='NATIONAL_APPROVED' WHERE id=?")->execute([$requestId]);}
        $this->same($before,$this->snapshot(),'concurrent fixture restored with operational data intact');
        $escaped=$this->policy->withQualifiedScan([self::ASC],fn($batches)=>$batches);
        try{$escaped->current();throw new RuntimeException('Escaped iterator must fail.');}catch(DomainException $e){$this->same(409,$e->getCode(),'lazy scan cannot escape its snapshot');}
    }

    private function transactionFailures():void
    {
        $pdo=$this->pdo;$s=$this->completed;$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $settings=fn()=>$pdo->query('SELECT @@session.transaction_isolation,@@session.transaction_read_only,@@session.autocommit')->fetch(PDO::FETCH_NUM);
        $original=$settings();
        try{
            foreach([false,true] as $after){
                $pdo->failSql='START TRANSACTION';$pdo->failAfterExecution=$after;
                try{$this->policy->qualifyBatch([$s['appointment']],[self::ASC]);throw new RuntimeException('Expected initialization failure.');}catch(DomainException $e){$this->same(503,$e->getCode(),'controlled initialization failure');}
                $this->same(false,$pdo->inTransaction(),'failed initialization leaves no transaction');$this->same($original,$settings(),'failed initialization restores session settings');
            }
            $pdo->failAfterExecution=false;$pdo->failSql='FROM arpa_division_appointment_request WHERE id IN';
            $this->classification($s,Policy::MANUAL_REVIEW,'failed evidence query cannot approve');
            $this->same(false,$pdo->inTransaction(),'evidence failure cleaned up');$this->same($original,$settings(),'evidence failure restores session settings');
            $native=$this->source();$this->native($native);$this->national($native);
            foreach([' effective_decision FROM arpa_appointment_data_correction c','WITH baseline_targets AS'] as $failure){
                $pdo->failSql=$failure;$mixed=$this->policy->qualifyBatch([$s['appointment'],$native['appointment']],[self::ASC]);
                $this->same(Policy::MANUAL_REVIEW,$mixed[$s['appointment']]['classification'],'historical SQL failure is fail-closed');
                $this->same(Policy::APPROVED,$mixed[$native['appointment']]['classification'],'historical SQL failure cannot poison independent native proof');
            }
            try{$this->policy->withQualifiedScan([self::ASC],function($batches){foreach($batches as $batch)throw new RuntimeException('Synthetic listing error.');});throw new RuntimeException('Expected callback failure.');}catch(DomainException $e){$this->same(503,$e->getCode(),'callback error fails closed');}
            $this->same(false,$pdo->inTransaction(),'callback error cleaned up');$this->same($original,$settings(),'callback error restores settings');
            $this->classification($s,Policy::APPROVED,'connection remains usable after recoverable failures');
            $this->same($original,$settings(),'successful qualification preserves settings');
            $this->policy->withQualifiedScan([self::ASC],function(){try{$this->pdo->exec("UPDATE officer SET dad_number=dad_number");throw new RuntimeException('Snapshot must be read only.');}catch(PDOException $e){$this->same('25006',$e->getCode(),'database enforces read-only policy snapshot');}});
            $faulty=new Policy($pdo);$pdo->failSql='SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED';$pdo->failAfterExecution=true;
            try{$faulty->qualify($s['appointment'],[self::ASC]);throw new RuntimeException('Expected cleanup failure.');}catch(DomainException $e){$this->same(503,$e->getCode(),'cleanup failure never returns successful qualification');}
            $this->same(false,$pdo->inTransaction(),'synthetic post-cleanup failure leaves no transaction');$this->same($original,$settings(),'post-cleanup failure reports a restored connection');
            try{$faulty->qualify($s['appointment'],[self::ASC]);throw new RuntimeException('Failed policy connection must not be reused.');}catch(DomainException $e){$this->same(503,$e->getCode(),'failed-cleanup instance refuses further use');}
        }finally{$pdo->failSql=null;$pdo->failAfterExecution=false;$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');}
    }

    private function scopeAndReadOnly():void
    {
        $s=$this->source();$before=$this->snapshot();
        $this->same(null,$this->policy->qualify($s['appointment'],[]),'empty ASC scope denies');
        $this->same(null,$this->policy->qualify($s['appointment'],[self::OTHER_ASC]),'cross-ASC evidence not disclosed');
        $this->same(null,$this->policy->qualify('ffffffff-ffff-4fff-8fff-ffffffffffff',[self::ASC]),'missing ID not disclosed');
        $this->same(Policy::PENDING_APPROVAL,$this->policy->qualify($s['appointment'],null)['classification'],'explicit trusted unrestricted scope');
        $this->invoke($this->fixture,'context',$this->actor(5));$this->classification($s,Policy::PENDING_APPROVAL,'ASC Admin scoped read');
        $this->invoke($this->fixture,'context');$this->classification($s,Policy::PENDING_APPROVAL,'ASC Subject scoped read');
        $this->same($before,$this->snapshot(),'all tables byte-equivalent after policy reads');
        $this->pdo->beginTransaction();try{
            try{$this->policy->qualify($s['appointment'],[self::ASC]);throw new RuntimeException('Nested transaction must be rejected.');}catch(DomainException $e){$this->same(409,$e->getCode(),'caller-owned transaction not repurposed');}
            $this->same(true,$this->pdo->inTransaction(),'caller transaction left open');
        }finally{$this->pdo->rollBack();}
        try{(new ArpaAppointmentReadService($this->pdo))->assertDivisionPeriodDoesNotOverlap($s['division'],'2025-01-01');throw new RuntimeException('Pending canonical must still block division.');}catch(DomainException){$this->same(true,true,'pending approval never releases canonical occupancy');}
        $this->same($before,$this->snapshot(),'overlap inspection and qualification do not write');
    }

    private function missingSchema():void
    {
        $s=$this->source();$native=$this->source();$this->native($native);$this->national($native);
        $this->pdo->exec('RENAME TABLE arpa_appointment_historical_approval_event TO hidden_historical_event');
        try{$this->classification($s,Policy::MANUAL_REVIEW,'missing historical schema fails closed');$this->classification($native,Policy::APPROVED,'native final proof independent of historical schema');}finally{$this->pdo->exec('RENAME TABLE hidden_historical_event TO arpa_appointment_historical_approval_event');}
        $this->pdo->exec('RENAME TABLE arpa_appointment_correction_amendment_request TO hidden_amendment_request');
        try{$this->classification($this->completed,Policy::MANUAL_REVIEW,'missing lineage never uses legacy effective-decision fallback as approval');$this->classification($native,Policy::APPROVED,'native unchanged without lineage schema');}finally{$this->pdo->exec('RENAME TABLE hidden_amendment_request TO arpa_appointment_correction_amendment_request');}
        $this->classification($this->completed,Policy::APPROVED,'schema restoration preserves completed supersession evidence');
    }

    private function national(array $s,string $origin='NATIVE'):string
    {
        $this->pdo->prepare("UPDATE arpa_division_appointment_request SET workflow_status='NATIONAL_APPROVED',finalized_by=?,finalized_at='2026-01-01 12:00:00' WHERE id=?")->execute([$this->actor(9),$s['request']]);
        $this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,action_at,record_origin,previous_status,new_status,timestamp_provenance) VALUES(?,'APPROVE','NATIONAL',?,?,?,'NATIONAL_VERIFIED','NATIONAL_APPROVED',?)")->execute([$s['request'],$this->actor(9),$origin==='NATIVE'?'2026-01-01 12:00:00':null,$origin,$origin==='NATIVE'?'NATIVE_RECORDED':'UNAVAILABLE_FROM_LEGACY_SOURCE']);
        return $this->pdo->lastInsertId();
    }
    private function native(array $s):void{foreach(['arpa_division_appointment_request'=>$s['request'],'arpa_division_appointment'=>$s['appointment']] as $table=>$id)$this->set($table,$id,'record_origin','NATIVE');}
    private function classification(array $s,string $expected,string $label):void{$this->same($expected,$this->read($s)['classification'],$label);}
    private function read(array $s):array{return $this->policy->qualify($s['appointment'],[self::ASC])??throw new RuntimeException('Scoped fixture inaccessible.');}
    private function source(...$args):array{return $this->invoke($this->fixture,'source',...$args);}
    private function invoke(object $o,string $method,...$args):mixed{return (new ReflectionMethod($o,$method))->invoke($o,...$args);}
    private function set(string $table,string $id,string $field,mixed $value):void{$this->pdo->prepare('UPDATE '.$table.' SET '.$field.'=? WHERE id=?')->execute([$value,$id]);}
    private function row(string $table,string $id):array{$s=$this->pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$s->execute([$id]);return $s->fetch();}
    private function snapshot():array{$out=[];foreach($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t)$out[$t]=$this->pdo->query('SELECT * FROM `'.$t.'` ORDER BY 1')->fetchAll();return $out;}
    private function actor(int $n):string{return '00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT);}
    private function input():array{return ['reason'=>'Synthetic approval qualification evidence','evidence_reference'=>'Synthetic appointment letter'];}
    private function uuid():string{return $this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $label):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($label.'; expected '.var_export($expected,true).', got '.var_export($actual,true));}
}

try{(new ArpaAppointmentApprovalQualificationIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
