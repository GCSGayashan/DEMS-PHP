<?php
declare(strict_types=1);
namespace App\Services;

use DateTimeImmutable;
use DomainException;
use PDO;
use Throwable;

/** Approval evidence only: never an activation, vacancy, overlap or authorization policy.
 * No feature toggle: effective approval evidence must remain readable with amendment UI OFF.
 * Existing permission checks and server-derived ASC scope remain the caller's responsibility.
 */
final class ArpaAppointmentApprovalQualificationPolicy
{
    public const APPROVED='APPROVED';
    public const PENDING_APPROVAL='PENDING_APPROVAL';
    public const MANUAL_REVIEW='MANUAL_REVIEW';
    public const HISTORICAL_ONLY='HISTORICAL_ONLY';
    public const MAX_BATCH_SIZE=500;
    private const APPOINTMENT_COLUMNS='id,request_id,officer_id,asc_location_id,arpa_division_location_id,appointment_type,record_origin,legacy_history_only,effective_from';
    private bool $connectionUnusable=false;

    public function __construct(private readonly PDO $pdo){}

    /** Caller retains existing view permission checks and supplies its server-derived
     * ASC scope. null is explicitly unrestricted; [] denies all. Never use request
     * input as scope. Inaccessible/missing IDs return null without evidence disclosure.
     * Closed/future appointments may be APPROVED: consumers still apply period rules.
     * @return array{classification:string,reason:string,approval_source:?string}|null
     */
    public function qualify(string $appointmentId,?array $allowedAscIds):?array
    {
        $result=$this->qualifyBatch([$appointmentId],$allowedAscIds);
        return $result===[]?null:reset($result);
    }

    /** Operational use only; does not grant or attest final National authorization. */
    public function qualifyOperational(string $appointmentId,?array $allowedAscIds):?array
    {
        return $this->withCandidateQualification($allowedAscIds,static fn(callable $qualify):?array=>$qualify([$appointmentId])[$appointmentId]??null,true);
    }

    /** Mutation caller owns the transaction and source-request lock; never opens a read-only snapshot. */
    public function qualifyOperationalForMutation(string $appointmentId,array $allowedAscIds):?array
    {
        $this->validate($allowedAscIds,200);$this->uuid($appointmentId);
        if(!$this->pdo->inTransaction())throw new DomainException('Operational mutation qualification requires an active transaction.',409);
        if($allowedAscIds===[])return null;
        [$scope,$params]=$this->scope($allowedAscIds);
        $rows=$this->rows('SELECT '.self::APPOINTMENT_COLUMNS.' FROM arpa_division_appointment WHERE id=?'.$scope.' FOR UPDATE',[$appointmentId,...$params]);
        return $this->classifyBatch($rows,true,true)[$appointmentId]??null;
    }

    /** Qualify the complete candidate ID set, NOT a previously paginated page.
     * Missing/inaccessible IDs are omitted. Duplicates collapse by canonical ID.
     * Queries/evidence are bounded per batch; the returned ID map itself is O(N).
     * For large populations and atomic counts/page projections use withQualifiedScan().
     * @return array<string,array{classification:string,reason:string,approval_source:?string}>
     */
    public function qualifyBatch(array $appointmentIds,?array $allowedAscIds,int $batchSize=200):array
    {
        $this->validate($allowedAscIds,$batchSize);foreach($appointmentIds as $id)$this->uuid($id);
        if($appointmentIds===[]||$allowedAscIds===[])return [];
        return $this->withQualifiedBatch($appointmentIds,$allowedAscIds,static fn(array $result):array=>$result,$batchSize);
    }

    /** Individual/profile read adapter: projection and qualification share one snapshot.
     * Candidate IDs may be selected beforehand; newly discovered projection rows without
     * qualification must fail closed. The callback must not start/end transactions.
     */
    public function withQualifiedBatch(array $appointmentIds,?array $allowedAscIds,callable $reader,int $batchSize=200):mixed
    {
        $this->validate($allowedAscIds,$batchSize);foreach($appointmentIds as $id)$this->uuid($id);
        return $this->snapshot(function()use($appointmentIds,$allowedAscIds,$batchSize,$reader):mixed{
            $out=[];[$scope,$params]=$this->scope($allowedAscIds);
            foreach($allowedAscIds===[]?[]:array_chunk(array_values(array_unique($appointmentIds)),$batchSize) as $ids){
                $rows=$this->rows('SELECT '.self::APPOINTMENT_COLUMNS.' FROM arpa_division_appointment WHERE id IN('.$this->marks($ids).')'.$scope.' ORDER BY id',[...$ids,...$params]);
                $out+=$this->classifyBatch($rows);
            }
            return $reader($out);
        });
    }

    /** Run a read-only listing adapter inside ONE consistent snapshot.
     * $reader receives lazy bounded maps (canonical ID => qualification), keyset ordered
     * by ID. Fully consume the candidate population BEFORE final counts/LIMIT/OFFSET.
     * Existing search/date/sort predicates still belong to the listing adapter. It may
     * build an ID map then query its authorized projections before returning, while
     * this same snapshot is open. Never filter an already paginated result in PHP.
     * Do not return the lazy iterator: it deliberately expires when the callback ends.
     */
    public function withQualifiedScan(?array $allowedAscIds,callable $reader,int $batchSize=200):mixed
    {
        $this->validate($allowedAscIds,$batchSize);
        return $this->snapshot(function()use($allowedAscIds,$reader,$batchSize):mixed{
            $active=true;
            $batches=(function()use($allowedAscIds,$batchSize,&$active):\Generator{
                if($allowedAscIds===[])return;
                [$scope,$params]=$this->scope($allowedAscIds);$last='';
                while(true){
                    if(!$active||!$this->pdo->inTransaction())throw new DomainException('The approval qualification snapshot has ended.',409);
                    $rows=$this->rows('SELECT '.self::APPOINTMENT_COLUMNS.' FROM arpa_division_appointment WHERE id>?'.$scope.' ORDER BY id LIMIT '.$batchSize,[$last,...$params]);
                    if($rows===[])return;
                    yield $this->classifyBatch($rows);$last=$rows[array_key_last($rows)]['id'];
                }
            })();
            try{return $reader($batches);}finally{$active=false;unset($batches);}
        });
    }

    /** Bounded candidate reader for listings. The supplied closure qualifies at most
     * 200 server-selected IDs within this snapshot; it cannot escape or broaden scope.
     * No complete-population ID map is retained. Final evidence remains the default;
     * the explicit operational mode additionally admits proven ASC materialization.
     */
    public function withCandidateQualification(?array $allowedAscIds,callable $reader,bool $ascOperational=false):mixed
    {
        $this->validate($allowedAscIds,200);
        return $this->snapshot(function()use($allowedAscIds,$reader,$ascOperational):mixed{
            $active=true;
            $qualify=function(array $ids)use($allowedAscIds,$ascOperational,&$active):array{
                if(!$active||!$this->pdo->inTransaction())throw new DomainException('The qualification snapshot has ended.',409);
                if(count($ids)>200)throw new DomainException('Candidate qualification exceeds 200 records.',422);
                foreach($ids as $id)$this->uuid($id);
                if($ids===[]||$allowedAscIds===[])return [];
                [$scope,$params]=$this->scope($allowedAscIds);
                return $this->classifyBatch($this->rows('SELECT '.self::APPOINTMENT_COLUMNS.' FROM arpa_division_appointment WHERE id IN('.$this->marks($ids).')'.$scope,[...$ids,...$params]),$ascOperational);
            };
            try{return $reader($qualify);}finally{$active=false;}
        });
    }

    private function classifyBatch(array $appointments,bool $ascOperational=false,bool $lock=false):array
    {
        if($appointments===[])return [];
        $out=[];$needed=[];
        $operational=[];
        foreach($appointments as $a){
            if((int)$a['legacy_history_only']===1)$out[$a['id']]=$this->result(self::HISTORICAL_ONLY,'NON_AUTHORITATIVE_HISTORY');
            else $operational[]=$a;
        }
        if($operational===[])return $out;
        try{
            $e=$this->evidence($operational,$ascOperational,$lock);
            foreach($operational as $a){
                try{$out[$a['id']]=$this->classify($a,$e,$needed,$ascOperational);}
                catch(Throwable){$out[$a['id']]=$this->result(self::MANUAL_REVIEW,'APPROVAL_EVIDENCE_UNAVAILABLE');}
            }
            if($needed!==[]){
                try{
                    $valid=(new ArpaHistoricalAppointmentApprovalService($this->pdo))->hasCurrentApprovedBaselines(array_keys($needed));
                    foreach($needed as $caseId=>$appointmentId)if($valid[$caseId]??false)$out[$appointmentId]=$this->result(self::APPROVED,'HISTORICAL_NATIONAL_APPROVAL_COMPLETE','HISTORICAL_APPROVAL');
                }catch(Throwable){foreach($needed as $appointmentId)$out[$appointmentId]=$this->result(self::MANUAL_REVIEW,'APPROVAL_EVIDENCE_UNAVAILABLE');}
            }
        }catch(Throwable){foreach($operational as $a)$out[$a['id']]=$this->result(self::MANUAL_REVIEW,'APPROVAL_EVIDENCE_UNAVAILABLE');}
        ksort($out,SORT_STRING);
        return $out;
    }

    /** Set-based evidence loading, with no SQL in the per-appointment classifiers. */
    private function evidence(array $appointments,bool $ascOperational=false,bool $lock=false):array
    {
        $suffix=$lock?' FOR UPDATE':'';
        $ids=array_column($appointments,'id');$requests=array_values(array_unique(array_column($appointments,'request_id')));
        $e=['requests'=>array_column($this->rows('SELECT id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,requested_effective_to,workflow_status,legacy_history_only,deleted_at,finalized_by,finalized_at FROM arpa_division_appointment_request WHERE id IN('.$this->marks($requests).')'.$suffix,$requests),null,'id')];
        $e['canonical_counts']=array_column($this->rows('SELECT request_id,COUNT(*) n FROM arpa_division_appointment WHERE request_id IN('.$this->marks($requests).') GROUP BY request_id',$requests),'n','request_id');
        $e['closures']=$this->group($this->rows('SELECT appointment_id,effective_to FROM arpa_division_appointment_closure WHERE appointment_id IN('.$this->marks($ids).')'.$suffix,$ids),'appointment_id');
        $e['final_actions']=$this->group($this->rows("SELECT request_id,previous_status,user_id,action_at,record_origin,timestamp_provenance FROM arpa_appointment_workflow_action WHERE request_id IN(".$this->marks($requests).") AND action='APPROVE' AND stage='NATIONAL' AND new_status='NATIONAL_APPROVED'".$suffix,$requests),'request_id');
        if($ascOperational)$e['asc_actions']=$this->group($this->rows("SELECT request_id,previous_status,user_id,action_at,record_origin,timestamp_provenance FROM arpa_appointment_workflow_action WHERE request_id IN(".$this->marks($requests).") AND action='APPROVE' AND stage='ASC' AND new_status='ASC_APPROVED'".$suffix,$requests),'request_id');
        $e['historical_schema']=false;
        if(array_filter($appointments,fn($a)=>$a['record_origin']==='LEGACY_IMPORT'&&($e['requests'][$a['request_id']]['workflow_status']??null)==='CREATED')===[])return $e;
        $e['historical_schema']=ArpaAppointmentCorrectionDecision::lineageAvailable($this->pdo)&&(new ArpaHistoricalApprovalReadService($this->pdo))->available();
        if(!$e['historical_schema'])return $e;
        try{
            $effective=ArpaAppointmentCorrectionDecision::effectivePredicate($this->pdo,'c');
            $history=$this->rows('SELECT c.id,c.appointment_id,c.request_id,c.officer_id,c.asc_location_id,c.correction_action,c.resolution_status,c.supersedes_correction_id,c.amendment_request_id,('.$effective.') effective_decision FROM arpa_appointment_data_correction c WHERE c.appointment_id IN('.$this->marks($ids).') OR c.request_id IN('.$this->marks($requests).') ORDER BY c.id',[...$ids,...$requests]);
            $e['history_appointment']=$this->group($history,'appointment_id');$e['history_request']=$this->group($history,'request_id');
            $cases=$this->rows('SELECT id,appointment_id,request_id,officer_id,asc_location_id,source_correction_id,active_appointment_id,workflow_status,finalized_by,finalized_at FROM arpa_appointment_historical_approval_case WHERE appointment_id IN('.$this->marks($ids).') OR request_id IN('.$this->marks($requests).') ORDER BY id',[...$ids,...$requests]);
            $e['cases_appointment']=$this->group($cases,'appointment_id');$e['cases_request']=$this->group($cases,'request_id');
            $caseIds=array_column($cases,'id');
            $e['historical_actions']=$caseIds===[]?[]:$this->group($this->rows("SELECT approval_case_id,previous_status,user_id,action_at FROM arpa_appointment_historical_approval_event WHERE approval_case_id IN(".$this->marks($caseIds).") AND action='APPROVE' AND stage='NATIONAL' AND new_status='NATIONAL_APPROVED'",$caseIds),'approval_case_id');
            $amendments=array_values(array_unique(array_filter(array_column($history,'amendment_request_id'))));
            $e['amendments']=$amendments===[]?[]:array_column($this->rows('SELECT id,appointment_id,officer_id,asc_location_id,source_correction_id,workflow_status,applied_correction_id FROM arpa_appointment_correction_amendment_request WHERE id IN('.$this->marks($amendments).')',$amendments),null,'id');
        }catch(Throwable){$e['historical_unavailable']=true;}
        return $e;
    }

    private function classify(array $a,array $e,array &$needed,bool $ascOperational=false):array
    {
        if((int)$a['legacy_history_only']===1)return $this->result(self::HISTORICAL_ONLY,'NON_AUTHORITATIVE_HISTORY');
        $r=$e['requests'][$a['request_id']]??null;
        if($r===null)return $this->result(self::MANUAL_REVIEW,'MISSING_SOURCE_REQUEST');
        if(!in_array($a['record_origin'],['NATIVE','LEGACY_IMPORT'],true)||$r['record_origin']!==$a['record_origin']||$r['deleted_at']!==null||(int)$r['legacy_history_only']!==0)return $this->result(self::MANUAL_REVIEW,'INVALID_SOURCE_AUTHORITY');
        foreach(['officer_id','asc_location_id','arpa_division_location_id','appointment_type'] as $field)if($a[$field]!==$r[$field])return $this->result(self::MANUAL_REVIEW,'SOURCE_IDENTITY_MISMATCH');
        if(!in_array($a['appointment_type'],ArpaAppointmentRules::APPOINTMENT_TYPES,true)||!in_array($r['request_type'],['APPOINTMENT','TRANSFER'],true)||($a['record_origin']==='LEGACY_IMPORT'&&$r['request_type']!=='APPOINTMENT'))return $this->result(self::MANUAL_REVIEW,'UNSUPPORTED_SOURCE');
        if((int)($e['canonical_counts'][$r['id']]??0)!==1)return $this->result(self::MANUAL_REVIEW,'AMBIGUOUS_CANONICAL_IDENTITY');
        $closures=$e['closures'][$a['id']]??[];
        if(count($closures)>1||!$this->date($a['effective_from'])||($closures!==[]&&(!$this->date($closures[0]['effective_to'])||$closures[0]['effective_to']<$a['effective_from'])))return $this->result(self::MANUAL_REVIEW,'INVALID_CANONICAL_PERIOD');

        if($ascOperational&&$r['request_type']==='APPOINTMENT'&&in_array($r['workflow_status'],['ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED'],true)){
            if($r['requested_effective_from']!==$a['effective_from']||($r['requested_effective_to']!==null&&$r['requested_effective_to']!==($closures[0]['effective_to']??null)))return $this->result(self::MANUAL_REVIEW,'SOURCE_PERIOD_MISMATCH');
            $events=$e['asc_actions'][$r['id']]??[];
            if(count($events)!==1||empty($events[0]['user_id']))return $this->result(self::MANUAL_REVIEW,'MISSING_OR_AMBIGUOUS_ASC_APPROVAL');$event=$events[0];
            // The importer can omit an unmapped verifier; its recorded ASC approval still needs an actor and legacy provenance.
            $native=$event['record_origin']==='NATIVE'&&$event['previous_status']==='ASC_VERIFIED'
                &&($a['record_origin']==='NATIVE'||(!empty($event['action_at'])&&$event['timestamp_provenance']==='NATIVE_RECORDED'));
            $legacy=$a['record_origin']==='LEGACY_IMPORT'&&$event['record_origin']==='LEGACY_IMPORT'
                &&in_array($event['previous_status'],['CREATED','ASC_VERIFIED'],true)
                &&in_array($event['timestamp_provenance'],['LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE'],true);
            if(!$native&&!$legacy)return $this->result(self::MANUAL_REVIEW,$a['record_origin']==='NATIVE'?'MISSING_OR_AMBIGUOUS_ASC_APPROVAL':'INVALID_ASC_APPROVAL_EVIDENCE');
            return $this->result(self::APPROVED,'ASC_APPROVED_OPERATIONAL','ASC_OPERATIONAL');
        }
        if($a['record_origin']==='NATIVE'||ArpaImportedAppointmentWorkflowPolicy::usesOriginalReview($r))return $this->original($r,$e);
        if($r['workflow_status']!=='CREATED')return $this->result(self::MANUAL_REVIEW,'UNSUPPORTED_IMPORTED_WORKFLOW');
        return $this->historical($a,$r,$e,$needed);
    }

    private function original(array $r,array $evidence):array
    {
        if(in_array($r['workflow_status'],['REJECTED','ADMIN_DELETED'],true))return $this->result(self::MANUAL_REVIEW,'REJECTED_OR_REMOVED_WORKFLOW');
        if($r['workflow_status']!=='NATIONAL_APPROVED'){
            if(!in_array($r['workflow_status'],['CREATED','SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','RETURNED'],true))return $this->result(self::MANUAL_REVIEW,'UNKNOWN_WORKFLOW_STATUS');
            return $this->result(self::PENDING_APPROVAL,'ORIGINAL_APPROVAL_INCOMPLETE');
        }
        $events=$evidence['final_actions'][$r['id']]??[];
        if(count($events)!==1||$events[0]['previous_status']!=='NATIONAL_VERIFIED'||empty($events[0]['user_id']))return $this->result(self::MANUAL_REVIEW,'MISSING_OR_AMBIGUOUS_FINAL_APPROVAL');$e=$events[0];
        if($e['record_origin']==='NATIVE'){
            if(empty($e['action_at'])||$e['timestamp_provenance']!=='NATIVE_RECORDED'||empty($r['finalized_by'])||empty($r['finalized_at'])||$r['finalized_by']!==$e['user_id'])return $this->result(self::MANUAL_REVIEW,'INVALID_FINAL_APPROVAL_EVIDENCE');
        }elseif($r['record_origin']!=='LEGACY_IMPORT'||$e['record_origin']!=='LEGACY_IMPORT'||!in_array($e['timestamp_provenance'],['LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE'],true))return $this->result(self::MANUAL_REVIEW,'INVALID_FINAL_APPROVAL_EVIDENCE');
        return $this->result(self::APPROVED,'ORIGINAL_NATIONAL_APPROVAL_COMPLETE','ORIGINAL_WORKFLOW');
    }

    private function historical(array $a,array $r,array $e,array &$needed):array
    {
        // Do not use effectivePredicate's legacy fallback to assert completion.
        if(!$e['historical_schema'])return $this->result(self::MANUAL_REVIEW,'HISTORICAL_EVIDENCE_SCHEMA_UNAVAILABLE');
        if($e['historical_unavailable']??false)return $this->result(self::MANUAL_REVIEW,'APPROVAL_EVIDENCE_UNAVAILABLE');
        $history=$this->merge($e['history_appointment'][$a['id']]??[],$e['history_request'][$r['id']]??[]);
        $decisions=array_values(array_filter($history,static fn(array $c):bool=>(int)$c['effective_decision']===1&&in_array($c['correction_action'],['RESOLVE_CANONICAL_ASSIGNMENT','KEEP_AS_HISTORICAL_EXCEPTION'],true)));
        if(count($decisions)!==1||$decisions[0]['correction_action']!=='RESOLVE_CANONICAL_ASSIGNMENT'||$decisions[0]['resolution_status']!=='RESOLVED_BY_CORRECTION')return $this->result(self::MANUAL_REVIEW,'MISSING_OR_AMBIGUOUS_EFFECTIVE_CORRECTION');$c=$decisions[0];
        if(!$this->lineage($c,$history,$a,$e))return $this->result(self::MANUAL_REVIEW,'INVALID_CORRECTION_LINEAGE');
        $cases=$this->merge($e['cases_appointment'][$a['id']]??[],$e['cases_request'][$r['id']]??[]);
        foreach($cases as $case)if(!$this->identity($case,$a))return $this->result(self::MANUAL_REVIEW,'HISTORICAL_CASE_IDENTITY_MISMATCH');
        $applicable=array_values(array_filter($cases,static fn(array $case):bool=>$case['source_correction_id']===$c['id']));
        if($applicable===[])return $this->result(self::PENDING_APPROVAL,'HISTORICAL_CASE_NOT_REGISTERED');
        if(count($applicable)!==1||array_filter($cases,static fn(array $case):bool=>$case['source_correction_id']!==$c['id']&&$case['active_appointment_id']!==null)!==[])return $this->result(self::MANUAL_REVIEW,'AMBIGUOUS_HISTORICAL_CASE');$case=$applicable[0];
        if(in_array($case['workflow_status'],['DRAFT','SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','RETURNED'],true))return $this->result(self::PENDING_APPROVAL,'HISTORICAL_APPROVAL_INCOMPLETE');
        if($case['workflow_status']!=='NATIONAL_APPROVED')return $this->result(self::MANUAL_REVIEW,'REJECTED_OR_UNKNOWN_HISTORICAL_CASE');
        $events=$e['historical_actions'][$case['id']]??[];
        if(count($events)!==1||$events[0]['previous_status']!=='NATIONAL_VERIFIED'||empty($case['finalized_by'])||empty($case['finalized_at'])||$events[0]['user_id']!==$case['finalized_by']||empty($events[0]['action_at']))return $this->result(self::MANUAL_REVIEW,'MISSING_OR_AMBIGUOUS_HISTORICAL_FINAL_APPROVAL');
        $needed[$case['id']]=$a['id'];
        return $this->result(self::MANUAL_REVIEW,'STALE_HISTORICAL_APPROVAL');
    }

    private function lineage(array $source,array $history,array $a,array $e):bool
    {
        $map=array_column($history,null,'id');$seen=[];$c=$source;
        while(true){
            if(isset($seen[$c['id']])||!$this->identity($c,$a))return false;$seen[$c['id']]=true;$previous=$c['supersedes_correction_id'];
            if($c['amendment_request_id']!==null){
                $m=$e['amendments'][$c['amendment_request_id']]??null;
                if($m===null||!$this->identity($m,$a,false)||$previous===null||$m['source_correction_id']!==$previous||$m['workflow_status']!=='NATIONAL_APPROVED'||$m['applied_correction_id']!==$c['id'])return false;
            }
            if($previous===null)return true;
            if(!isset($map[$previous]))return false;$c=$map[$previous];
        }
    }
    private function identity(array $row,array $a,bool $request=true):bool
    {
        foreach(['appointment_id'=>'id','officer_id'=>'officer_id','asc_location_id'=>'asc_location_id'] as $field=>$key)if($row[$field]!==$a[$key])return false;
        return !$request||$row['request_id']===$a['request_id'];
    }
    private function date(mixed $value):bool{if(!is_string($value))return false;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $d&&$d->format('Y-m-d')===$value;}
    private function result(string $classification,string $reason,?string $source=null):array{return ['classification'=>$classification,'reason'=>$reason,'approval_source'=>$source];}
    private function rows(string $sql,array $params):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    private function marks(array $values):string{return implode(',',array_fill(0,count($values),'?'));}
    private function group(array $rows,string $key):array{$out=[];foreach($rows as $row)$out[$row[$key]][]=$row;return $out;}
    private function merge(array $a,array $b):array{$rows=array_column([...$a,...$b],null,'id');ksort($rows,SORT_STRING);return array_values($rows);}
    private function scope(?array $ids):array{return $ids===null?['',[]]:[' AND asc_location_id IN('.$this->marks($ids).')',array_values($ids)];}
    private function validate(?array $scope,int $size):void
    {
        if($size<1||$size>self::MAX_BATCH_SIZE)throw new DomainException('Qualification batch size must be between 1 and 500.',422);
        foreach($scope??[] as $id)$this->uuid($id);
    }

    /** No next-transaction SET settings are left armed if initialization fails. */
    private function snapshot(callable $reader):mixed
    {
        if($this->connectionUnusable)throw new DomainException('Discard the failed approval qualification connection.',503);
        if($this->pdo->inTransaction())throw new DomainException('Approval qualification requires its own consistent read-only snapshot.',409);
        try{$settings=$this->pdo->query('SELECT @@session.transaction_isolation isolation_level,@@session.autocommit autocommit_mode')->fetch(PDO::FETCH_ASSOC);}
        catch(Throwable){throw new DomainException('Approval qualification connection settings are unavailable.',503);}
        $isolation=str_replace('-',' ',$settings['isolation_level']);
        if(!in_array($isolation,['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'],true)||(int)$settings['autocommit_mode']!==1)throw new DomainException('Approval qualification requires an autocommit connection with known isolation.',409);
        try{
            $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $result=$reader();
            if(!$this->pdo->inTransaction())throw new DomainException('The approval qualification snapshot ended unexpectedly.',409);
            return $result;
        }catch(DomainException $e){throw $e;}
        catch(Throwable){throw new DomainException('Approval qualification is temporarily unavailable.',503);}
        finally{
            try{
                if($this->pdo->inTransaction())$this->pdo->rollBack();
                $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
            }catch(Throwable){$this->connectionUnusable=true;throw new DomainException('Approval qualification cleanup failed; discard this connection.',503);}
        }
    }
    private function uuid(mixed $id):void{if(!is_string($id)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$id))throw new DomainException('A full canonical location/appointment ID is required.',422);}
}
