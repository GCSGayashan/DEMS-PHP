<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\ScopeService;
use DateTimeImmutable;
use DomainException;
use PDO;
use PDOException;
use Throwable;

final class ArpaAppointmentCorrectionAmendmentService
{
    private const INPUT_FIELDS=['proposed_action','proposed_json','correction_reason','remarks','evidence_reference'];
    private const ACTION_FIELDS=[
        'MARK_HISTORICAL_ONLY'=>[],
        'SET_EFFECTIVE_TO'=>['effective_to','end_reason_id'],
        'CORRECT_APPOINTMENT_TYPE'=>['appointment_type'],
        'CORRECT_ARPA_DIVISION'=>['arpa_division_location_id'],
        'CORRECT_EFFECTIVE_FROM'=>['effective_from'],
        'CORRECT_END_REASON'=>['end_reason_id'],
        'SELECT_CURRENT_RECORD'=>['appointment_id'],
        'KEEP_AS_HISTORICAL_EXCEPTION'=>[],
        'RESOLVE_CANONICAL_ASSIGNMENT'=>[],
        'CLEAR_EFFECTIVE_TO'=>[],
    ];
    private const APPOINTMENT_TYPES=['PERMANENT','ACTING','DUTY_COVERING','ATTEND_TO_DUTY'];
    private const EDITABLE_STATUSES=['DRAFT','RETURNED'];
    private const REVIEW_PROFILES=[
        'SUBMITTED'=>['stage'=>'ASC','action'=>'VERIFY','permission'=>'arpa.appointment.asc-verify'],
        'ASC_VERIFIED'=>['stage'=>'ASC','action'=>'APPROVE','permission'=>'arpa.appointment.asc-approve'],
        'ASC_APPROVED'=>['stage'=>'DISTRICT','action'=>'VERIFY','permission'=>'arpa.appointment.district-verify'],
        'DISTRICT_VERIFIED'=>['stage'=>'DISTRICT','action'=>'APPROVE','permission'=>'arpa.appointment.district-approve'],
        'DISTRICT_APPROVED'=>['stage'=>'NATIONAL','action'=>'VERIFY','permission'=>'arpa.appointment.national-verify'],
        'NATIONAL_VERIFIED'=>['stage'=>'NATIONAL','action'=>'APPROVE','permission'=>'arpa.appointment.national-approve'],
    ];

    public function __construct(private readonly PDO $pdo){}

    /** @return array<string,mixed> */
    public function createDraft(string $sourceCorrectionId,array $input,string $actorId):array
    {
        $this->assertInputFields($input);
        return $this->transaction(function()use($sourceCorrectionId,$input,$actorId):array{
            $bundle=$this->sourceBundle($sourceCorrectionId,true);
            $this->assertAuthorized($actorId,(string)$bundle['correction']['asc_location_id']);
            $this->assertSourceEligible($bundle,true);
            $proposal=$this->normalizeProposal($input,(string)$bundle['appointment']['id']);
            $baseline=$this->baseline($bundle);
            $baselineJson=$this->canonicalJson($baseline);
            $id=$this->uuid();
            try{
                $stmt=$this->pdo->prepare("INSERT INTO arpa_appointment_correction_amendment_request(
                    id,source_correction_id,appointment_id,officer_id,asc_location_id,
                    proposed_action,baseline_json,baseline_hash,proposed_json,
                    correction_reason,remarks,evidence_reference,workflow_status,created_by
                ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'DRAFT',?)");
                $stmt->execute([
                    $id,$bundle['correction']['id'],$bundle['appointment']['id'],$bundle['officer_id'],$bundle['asc_location_id'],
                    $proposal['proposed_action'],$baselineJson,hash('sha256',$baselineJson),$this->canonicalJson($proposal['proposed_json']),
                    $proposal['correction_reason'],$proposal['remarks'],$proposal['evidence_reference'],$actorId,
                ]);
            }catch(PDOException $e){
                if($this->isActiveDuplicate($e))throw new DomainException('An active amendment already exists for this correction decision.',0,$e);
                throw $e;
            }
            $request=$this->lockedRequest($id);
            $this->event($id,'CREATE_DRAFT','CREATOR',$actorId,null,'DRAFT',null,$this->eventSnapshot($request));
            return $this->present($request,$this->events($id));
        });
    }

    /** @return array<string,mixed> */
    public function get(string $amendmentId,string $actorId):array
    {
        $request=$this->request($amendmentId);
        $this->assertAuthorized($actorId,(string)$request['asc_location_id']);
        return $this->present($request,$this->events($amendmentId));
    }

    /**
     * Read-only UI eligibility. Authorization is checked before any source
     * decision, snapshot, or active-amendment detail is returned.
     *
     * @return array<string,mixed>
     */
    public function eligibility(string $sourceCorrectionId,string $actorId,bool $includeOptions=false):array
    {
        if(!$this->isUuid($sourceCorrectionId))throw new DomainException('The source correction was not found.');
        $stmt=$this->pdo->prepare('SELECT id,appointment_id,asc_location_id FROM arpa_appointment_data_correction WHERE id=?');
        $stmt->execute([$sourceCorrectionId]);$source=$stmt->fetch();
        if(!$source)throw new DomainException('The source correction was not found.');
        $this->assertAuthorized($actorId,(string)$source['asc_location_id']);
        if($source['appointment_id']===null){
            return ['eligible'=>false,'code'=>'REQUEST_ONLY','reason'=>'This correction is linked only to a workflow request and has no canonical appointment to amend.','active_amendment_id'=>null];
        }
        try{$bundle=$this->sourceBundle($sourceCorrectionId,false);}
        catch(DomainException $e){return ['eligible'=>false,'code'=>'SOURCE_MISMATCH','reason'=>$e->getMessage(),'active_amendment_id'=>null];}
        $result=['eligible'=>true,'code'=>'ELIGIBLE','reason'=>null,'active_amendment_id'=>null,'active_status'=>null,'baseline'=>$this->baseline($bundle)];
        if($bundle['superseded_by']!==null){
            $result=array_merge($result,['eligible'=>false,'code'=>'SUPERSEDED','reason'=>'This correction decision has already been superseded.']);
        }else{
            $stmt=$this->pdo->prepare('SELECT id,workflow_status FROM arpa_appointment_correction_amendment_request WHERE active_source_correction_id=? LIMIT 1');
            $stmt->execute([$sourceCorrectionId]);$active=$stmt->fetch();
            if($active)$result=array_merge($result,['eligible'=>false,'code'=>'ACTIVE_AMENDMENT','reason'=>'An active correction amendment already exists for this decision.','active_amendment_id'=>$active['id'],'active_status'=>$active['workflow_status']]);
        }
        if($includeOptions){
            $result['appointment_types']=self::APPOINTMENT_TYPES;
            $stmt=$this->pdo->query("SELECT id,system_key,name_en FROM arpa_appointment_end_reason WHERE active=1 ORDER BY display_order,name_en");
            $result['end_reasons']=$stmt->fetchAll();
            $stmt=$this->pdo->prepare("SELECT l.id,l.dad_number,l.name_en
                FROM location_relationship lr
                JOIN location l ON l.id=lr.child_location_id
                JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ARPA_DIVISION'
                WHERE lr.parent_location_id=? AND lr.relationship_type='ASC_ARPA_DIVISION'
                  AND lr.active=1 AND lr.approval_status='APPROVED'
                  AND lr.effective_from<=CURRENT_DATE() AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())
                ORDER BY l.name_en,l.dad_number");
            $stmt->execute([$bundle['asc_location_id']]);$result['arpa_divisions']=$stmt->fetchAll();
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function updateDraft(string $amendmentId,array $input,int $expectedVersion,string $actorId):array
    {
        $this->assertInputFields($input);
        if($expectedVersion<0)throw new DomainException('A valid amendment version is required.');
        return $this->transaction(function()use($amendmentId,$input,$expectedVersion,$actorId):array{
            $request=$this->lockedRequest($amendmentId);
            $this->assertAuthorized($actorId,(string)$request['asc_location_id']);
            $this->assertEditable($request);
            $this->assertVersion($request,$expectedVersion);
            $this->assertSourceNotSuperseded((string)$request['source_correction_id'],true);
            $proposal=$this->normalizeProposal($input,(string)$request['appointment_id']);
            $before=$this->eventSnapshot($request);
            $stmt=$this->pdo->prepare("UPDATE arpa_appointment_correction_amendment_request
                SET proposed_action=?,proposed_json=?,correction_reason=?,remarks=?,evidence_reference=?,
                    updated_by=?,updated_at=NOW(),version=version+1
                WHERE id=? AND version=? AND workflow_status IN('DRAFT','RETURNED')");
            $stmt->execute([
                $proposal['proposed_action'],$this->canonicalJson($proposal['proposed_json']),$proposal['correction_reason'],
                $proposal['remarks'],$proposal['evidence_reference'],$actorId,$amendmentId,$expectedVersion,
            ]);
            if($stmt->rowCount()!==1)throw new DomainException('The amendment changed while it was being edited. Reload it and try again.');
            $after=$this->lockedRequest($amendmentId);
            $this->event($amendmentId,'EDIT_DRAFT','CREATOR',$actorId,(string)$request['workflow_status'],(string)$after['workflow_status'],$before,$this->eventSnapshot($after));
            return $this->present($after,$this->events($amendmentId));
        });
    }

    /** @return array<string,mixed> */
    public function withdraw(string $amendmentId,int $expectedVersion,string $actorId,?string $comments=null):array
    {
        if($expectedVersion<0)throw new DomainException('A valid amendment version is required.');
        $comments=$this->optionalText($comments,'Withdrawal comments',1000);
        return $this->transaction(function()use($amendmentId,$expectedVersion,$actorId,$comments):array{
            $request=$this->lockedRequest($amendmentId);
            $this->assertAuthorized($actorId,(string)$request['asc_location_id']);
            $this->assertEditable($request);
            $this->assertVersion($request,$expectedVersion);
            $before=$this->eventSnapshot($request);
            $stmt=$this->pdo->prepare("UPDATE arpa_appointment_correction_amendment_request
                SET workflow_status='WITHDRAWN',updated_by=?,updated_at=NOW(),finalized_by=?,finalized_at=NOW(),version=version+1
                WHERE id=? AND version=? AND workflow_status IN('DRAFT','RETURNED') AND applied_correction_id IS NULL");
            $stmt->execute([$actorId,$actorId,$amendmentId,$expectedVersion]);
            if($stmt->rowCount()!==1)throw new DomainException('The amendment changed while it was being withdrawn. Reload it and try again.');
            $after=$this->lockedRequest($amendmentId);
            $this->event($amendmentId,'WITHDRAW','CREATOR',$actorId,(string)$request['workflow_status'],'WITHDRAWN',$before,$this->eventSnapshot($after),$comments);
            return $this->present($after,$this->events($amendmentId));
        });
    }

    /** @return array<string,mixed> */
    public function submit(string $amendmentId,int $expectedVersion,string $actorId):array
    {
        if($expectedVersion<0)throw new DomainException('A valid amendment version is required.');
        return $this->transaction(function()use($amendmentId,$expectedVersion,$actorId):array{
            $request=$this->lockedRequest($amendmentId);
            $this->assertAuthorized($actorId,(string)$request['asc_location_id']);
            if(!Auth::can('arpa.appointment.submit'))throw new DomainException('You are not authorized to submit correction amendments.');
            $this->assertEditable($request);
            $this->assertVersion($request,$expectedVersion);
            $transition=ArpaAppointmentRules::transition(
                (string)$request['workflow_status']==='DRAFT'?'CREATED':(string)$request['workflow_status'],
                'SUBMIT','CREATOR'
            );
            $this->assertProposalStillCurrent($request);
            $before=$this->eventSnapshot($request);
            $stmt=$this->pdo->prepare("UPDATE arpa_appointment_correction_amendment_request
                SET workflow_status=?,updated_by=?,updated_at=NOW(),submitted_by=?,submitted_at=NOW(),
                    finalized_by=NULL,finalized_at=NULL,version=version+1
                WHERE id=? AND version=? AND workflow_status IN('DRAFT','RETURNED') AND applied_correction_id IS NULL");
            $stmt->execute([$transition['status'],$actorId,$actorId,$amendmentId,$expectedVersion]);
            if($stmt->rowCount()!==1)throw new DomainException('The amendment changed while it was being submitted. Reload it and try again.');
            $after=$this->lockedRequest($amendmentId);
            $this->event($amendmentId,'SUBMIT','CREATOR',$actorId,(string)$request['workflow_status'],(string)$after['workflow_status'],$before,$this->eventSnapshot($after));
            return $this->present($after,$this->events($amendmentId));
        });
    }

    /** @return array<string,mixed> */
    public function reviewerGet(string $amendmentId,string $actorId):array
    {
        $request=$this->request($amendmentId);
        try{$this->assertReviewerAuthorized($request,$actorId);}
        catch(DomainException $current){
            if(!$this->isAuthorizedHistoricalReviewer($request,$actorId))throw $current;
        }
        return $this->present($request,$this->events($amendmentId));
    }

    /**
     * Read model for the amendment interface. All buttons are derived here from
     * the same authorization and maker-checker rules used by the mutations.
     *
     * @return array<string,mixed>
     */
    public function interfaceGet(string $amendmentId,string $actorId):array
    {
        $request=$this->request($amendmentId);$owner=false;$currentReviewer=false;$historicalReviewer=false;
        try{$this->assertAuthorized($actorId,(string)$request['asc_location_id']);$owner=true;}
        catch(DomainException){/* Reviewers are authorized independently below. */}
        try{$profile=$this->assertReviewerAuthorized($request,$actorId);$currentReviewer=true;}
        catch(DomainException){$profile=null;}
        if(!$owner&&!$currentReviewer)$historicalReviewer=$this->isAuthorizedHistoricalReviewer($request,$actorId);
        if(!$owner&&!$currentReviewer&&!$historicalReviewer){
            throw new DomainException('This correction amendment is outside your current role or scope.');
        }

        $actions=['edit'=>false,'withdraw'=>false,'submit'=>false,'submit_label'=>null,'primary_review'=>null,'return'=>false,'reject'=>false,'final_approval'=>false];
        if($owner&&in_array((string)$request['workflow_status'],self::EDITABLE_STATUSES,true)&&$request['applied_correction_id']===null){
            $actions['edit']=true;$actions['withdraw']=true;
            if(Auth::can('arpa.appointment.submit')){
                $actions['submit']=true;$actions['submit_label']=$request['workflow_status']==='RETURNED'?'Resubmit Correction Proposal':'Submit Correction Proposal';
            }
        }
        if($currentReviewer&&$profile!==null){
            $primary=(string)$profile['action'];$allowed=true;
            try{
                $this->assertReviewerAuthorized($request,$actorId,$primary);
                if($primary==='APPROVE')$this->assertMakerChecker($request,(string)$profile['stage'],$actorId);
            }catch(DomainException){$allowed=false;}
            if($allowed)$actions['primary_review']=['action'=>$primary,'stage'=>$profile['stage'],'label'=>$primary==='VERIFY'?'Verify':'Approve'];
            $actions['return']=Auth::can('arpa.appointment.return');
            $actions['reject']=Auth::can('arpa.appointment.reject');
            $actions['final_approval']=$allowed&&$primary==='APPROVE'&&(string)$request['workflow_status']==='NATIONAL_VERIFIED';
        }

        $result=$this->present($request,$this->events($amendmentId));
        $result['interface_actions']=$actions;
        $result['viewer_access']=$currentReviewer?'CURRENT_REVIEWER':($owner?'ASC_OWNER':'HISTORICAL_REVIEWER');
        $result['display_context']=$this->displayContext($request);
        return $result;
    }

    /** @return array<string,mixed> */
    public function review(string $amendmentId,string $action,int $expectedVersion,string $actorId,?string $reason=null):array
    {
        if($expectedVersion<0)throw new DomainException('A valid amendment version is required.');
        $action=strtoupper(trim($action));
        if(!in_array($action,['VERIFY','APPROVE','RETURN_FOR_CORRECTION','REJECT'],true))throw new DomainException('Unsupported correction amendment workflow action.');
        $reason=$this->optionalText($reason,'Workflow reason',1000);
        if(in_array($action,['RETURN_FOR_CORRECTION','REJECT'],true)&&$reason===null)throw new DomainException('A reason is required when returning or rejecting a correction amendment.');

        return $this->transaction(function()use($amendmentId,$action,$expectedVersion,$actorId,$reason):array{
            $request=$this->lockedRequest($amendmentId);
            $this->assertVersion($request,$expectedVersion);
            $profile=$this->assertReviewerAuthorized($request,$actorId,$action);
            $transition=ArpaAppointmentRules::transition((string)$request['workflow_status'],$action,$profile['stage']);
            if($action==='APPROVE')$this->assertMakerChecker($request,$profile['stage'],$actorId);
            if($action==='APPROVE'&&(string)$request['workflow_status']==='NATIONAL_VERIFIED'){
                $this->assertProposalStillCurrent($request);
                $application=(new ArpaAppointmentDataIssueCorrectionService($this->pdo))
                    ->applyApprovedAmendmentInTransaction($amendmentId,$actorId);
                $newStatus='NATIONAL_APPROVED';
                $before=$this->eventSnapshot($request);
                $stmt=$this->pdo->prepare("UPDATE arpa_appointment_correction_amendment_request
                    SET workflow_status='NATIONAL_APPROVED',applied_correction_id=?,updated_by=?,updated_at=NOW(),
                        finalized_by=?,finalized_at=NOW(),version=version+1
                    WHERE id=? AND version=? AND workflow_status='NATIONAL_VERIFIED' AND applied_correction_id IS NULL");
                $stmt->execute([$application['correction_id'],$actorId,$actorId,$amendmentId,$expectedVersion]);
                if($stmt->rowCount()!==1)throw new DomainException('The amendment changed while the final approval was being completed. Reload it and try again.');
                $after=$this->lockedRequest($amendmentId);
                $this->event($amendmentId,'APPROVE','NATIONAL',$actorId,'NATIONAL_VERIFIED',$newStatus,$before,$this->eventSnapshot($after),$reason);
                return $this->present($after,$this->events($amendmentId));
            }
            $newStatus=$action==='REJECT'?'REJECTED':$transition['status'];
            $before=$this->eventSnapshot($request);
            $terminal=$newStatus==='REJECTED';
            $stmt=$this->pdo->prepare("UPDATE arpa_appointment_correction_amendment_request
                SET workflow_status=?,updated_by=?,updated_at=NOW(),
                    finalized_by=".($terminal?'?':'finalized_by').",finalized_at=".($terminal?'NOW()':'finalized_at').",version=version+1
                WHERE id=? AND version=? AND workflow_status=? AND applied_correction_id IS NULL");
            $params=$terminal
                ?[$newStatus,$actorId,$actorId,$amendmentId,$expectedVersion,$request['workflow_status']]
                :[$newStatus,$actorId,$amendmentId,$expectedVersion,$request['workflow_status']];
            $stmt->execute($params);
            if($stmt->rowCount()!==1)throw new DomainException('The amendment changed while the workflow action was being completed. Reload it and try again.');
            $after=$this->lockedRequest($amendmentId);
            $this->event($amendmentId,$action,$profile['stage'],$actorId,(string)$request['workflow_status'],$newStatus,$before,$this->eventSnapshot($after),$reason);
            return $this->present($after,$this->events($amendmentId));
        });
    }

    /** @return array{correction:array<string,mixed>,appointment:array<string,mixed>,request:array<string,mixed>,closure:?array<string,mixed>,officer_id:string,asc_location_id:string,superseded_by:?string} */
    private function sourceBundle(string $sourceCorrectionId,bool $lock):array
    {
        if(!$this->isUuid($sourceCorrectionId))throw new DomainException('The source correction was not found.');
        $suffix=$lock?' FOR UPDATE':'';
        $stmt=$this->pdo->prepare('SELECT * FROM arpa_appointment_data_correction WHERE id=?'.$suffix);
        $stmt->execute([$sourceCorrectionId]);$correction=$stmt->fetch();
        if(!$correction)throw new DomainException('The source correction was not found.');
        if(empty($correction['appointment_id']))throw new DomainException('A request-only correction cannot be amended because it has no existing canonical appointment.');

        $stmt=$this->pdo->prepare('SELECT * FROM arpa_division_appointment WHERE id=?'.$suffix);
        $stmt->execute([$correction['appointment_id']]);$appointment=$stmt->fetch();
        if(!$appointment)throw new DomainException('The canonical appointment linked to this correction no longer exists.');
        $stmt=$this->pdo->prepare('SELECT * FROM arpa_division_appointment_request WHERE id=?'.$suffix);
        $stmt->execute([$appointment['request_id']]);$request=$stmt->fetch();
        if(!$request)throw new DomainException('The appointment request linked to this correction no longer exists.');
        $stmt=$this->pdo->prepare('SELECT * FROM arpa_division_appointment_closure WHERE appointment_id=?'.$suffix);
        $stmt->execute([$appointment['id']]);$closure=$stmt->fetch()?:null;

        if((string)$correction['officer_id']!==(string)$appointment['officer_id']
            ||(string)$correction['asc_location_id']!==(string)$appointment['asc_location_id']
            ||(string)($correction['request_id']??'')!==(string)$appointment['request_id']
            ||(string)$request['id']!==(string)$appointment['request_id']
            ||(string)$request['officer_id']!==(string)$appointment['officer_id']
            ||(string)($request['asc_location_id']??'')!==(string)$appointment['asc_location_id']){
            throw new DomainException('The source correction does not match its canonical appointment identity.');
        }
        $relatedWriteSet=$this->relatedWriteSet($correction,$appointment,$request,$closure,$lock);
        $supersededBy=$this->supersedingCorrection((string)$correction['id'],$lock);
        return [
            'correction'=>$correction,'appointment'=>$appointment,'request'=>$request,'closure'=>$closure,
            'related_write_set'=>$relatedWriteSet,
            'officer_id'=>(string)$appointment['officer_id'],'asc_location_id'=>(string)$appointment['asc_location_id'],
            'superseded_by'=>$supersededBy,
        ];
    }

    /** @param array<string,mixed> $bundle */
    private function assertSourceEligible(array $bundle,bool $lock):void
    {
        if($bundle['superseded_by']!==null)throw new DomainException('This correction decision has already been superseded.');
        $sql='SELECT id FROM arpa_appointment_correction_amendment_request WHERE active_source_correction_id=? LIMIT 1'.($lock?' FOR UPDATE':'');
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$bundle['correction']['id']]);
        if($stmt->fetchColumn())throw new DomainException('An active amendment already exists for this correction decision.');
    }

    private function assertSourceNotSuperseded(string $sourceCorrectionId,bool $lock):void
    {
        if($this->supersedingCorrection($sourceCorrectionId,$lock)!==null)throw new DomainException('This correction decision has already been superseded.');
    }

    private function supersedingCorrection(string $sourceCorrectionId,bool $lock):?string
    {
        $stmt=$this->pdo->prepare("SELECT successor.id
            FROM arpa_appointment_data_correction successor
            LEFT JOIN arpa_appointment_correction_amendment_request amendment
              ON amendment.id=successor.amendment_request_id
            WHERE successor.supersedes_correction_id=?
              AND (successor.amendment_request_id IS NULL
                OR (amendment.workflow_status='NATIONAL_APPROVED'
                  AND amendment.applied_correction_id=successor.id))
            LIMIT 1".($lock?' FOR UPDATE':''));
        $stmt->execute([$sourceCorrectionId]);$id=$stmt->fetchColumn();return $id===false?null:(string)$id;
    }

    /**
     * Freeze the complete source-decision write set. This is intentionally
     * broader than the primary appointment snapshot because actions such as
     * SELECT_CURRENT_RECORD update every related imported appointment.
     *
     * @param array<string,mixed> $correction
     * @param array<string,mixed> $primaryAppointment
     * @param array<string,mixed> $primaryRequest
     * @param array<string,mixed>|null $primaryClosure
     * @return array{appointment_ids:array<int,string>,appointments:array<int,array<string,mixed>>,requests:array<int,array<string,mixed>>,closures:array<int,array<string,mixed>>}
     */
    private function relatedWriteSet(array $correction,array $primaryAppointment,array $primaryRequest,?array $primaryClosure,bool $lock):array
    {
        $decoded=$this->decodeNullableJson($correction['related_appointment_ids_json']??null);
        if($decoded!==null&&!array_is_list($decoded))throw new DomainException('The source correction has invalid related appointment evidence.');
        $ids=[];
        foreach($decoded??[] as $id){
            if(!is_string($id)||!$this->isUuid($id))throw new DomainException('The source correction has invalid related appointment evidence.');
            $ids[$id]=$id;
        }
        $ids[(string)$primaryAppointment['id']]=(string)$primaryAppointment['id'];
        $ids=array_values($ids);sort($ids,SORT_STRING);

        $appointments=[(string)$primaryAppointment['id']=>$primaryAppointment];
        $requests=[(string)$primaryRequest['id']=>$primaryRequest];
        $closures=$primaryClosure===null?[]:[(string)$primaryClosure['appointment_id']=>$primaryClosure];
        $otherIds=array_values(array_filter($ids,static fn(string $id):bool=>$id!==(string)$primaryAppointment['id']));
        if($otherIds!==[]){
            $placeholders=implode(',',array_fill(0,count($otherIds),'?'));
            $suffix=$lock?' FOR UPDATE':'';
            $stmt=$this->pdo->prepare("SELECT * FROM arpa_division_appointment WHERE id IN ({$placeholders}) ORDER BY id{$suffix}");
            $stmt->execute($otherIds);foreach($stmt->fetchAll() as $row)$appointments[(string)$row['id']]=$row;
            if(count($appointments)!==count($ids))throw new DomainException('A related appointment in the source correction no longer exists.');
            $requestIds=[];foreach($appointments as $row)$requestIds[(string)$row['request_id']]=(string)$row['request_id'];
            unset($requestIds[(string)$primaryRequest['id']]);$requestIds=array_values($requestIds);sort($requestIds,SORT_STRING);
            if($requestIds!==[]){
                $requestPlaceholders=implode(',',array_fill(0,count($requestIds),'?'));
                $stmt=$this->pdo->prepare("SELECT * FROM arpa_division_appointment_request WHERE id IN ({$requestPlaceholders}) ORDER BY id{$suffix}");
                $stmt->execute($requestIds);foreach($stmt->fetchAll() as $row)$requests[(string)$row['id']]=$row;
            }
            $stmt=$this->pdo->prepare("SELECT * FROM arpa_division_appointment_closure WHERE appointment_id IN ({$placeholders}) ORDER BY appointment_id{$suffix}");
            $stmt->execute($otherIds);foreach($stmt->fetchAll() as $row)$closures[(string)$row['appointment_id']]=$row;
        }
        foreach($appointments as $row){
            $request=$requests[(string)$row['request_id']]??null;
            if($request===null||(string)$row['asc_location_id']!==(string)$correction['asc_location_id']
                ||(string)$request['officer_id']!==(string)$row['officer_id']
                ||(string)$request['asc_location_id']!==(string)$row['asc_location_id']){
                throw new DomainException('The source correction contains a related appointment outside its authorized ASC or identity.');
            }
        }
        ksort($appointments,SORT_STRING);ksort($requests,SORT_STRING);ksort($closures,SORT_STRING);
        return ['appointment_ids'=>$ids,'appointments'=>array_values($appointments),'requests'=>array_values($requests),'closures'=>array_values($closures)];
    }

    /** @param array<string,mixed> $bundle @return array<string,mixed> */
    private function baseline(array $bundle):array
    {
        $c=$bundle['correction'];$a=$bundle['appointment'];$r=$bundle['request'];$cl=$bundle['closure'];
        return [
            'source_correction'=>[
                'id'=>$c['id'],'issue_row_key'=>$c['issue_row_key'],'issue_type'=>$c['issue_type'],
                'correction_action'=>$c['correction_action'],'resolution_status'=>$c['resolution_status'],
                'appointment_id'=>$c['appointment_id'],'request_id'=>$c['request_id'],'officer_id'=>$c['officer_id'],
                'related_appointment_ids'=>$bundle['related_write_set']['appointment_ids'],
                'asc_location_id'=>$c['asc_location_id'],'record_origin'=>$c['record_origin'],
                'corrected_by'=>$c['corrected_by'],'corrected_at'=>$c['corrected_at'],
                'correction_reason'=>$c['correction_reason'],'remarks'=>$c['remarks'],'evidence_reference'=>$c['evidence_reference'],
                'before'=>$this->decodeJson((string)$c['before_json']),'after'=>$this->decodeJson((string)$c['after_json']),
                'source'=>$c['source'],'legacy_source_references'=>$this->decodeNullableJson($c['legacy_source_references_json']),
                'supersedes_correction_id'=>$c['supersedes_correction_id'],'amendment_request_id'=>$c['amendment_request_id'],
                'superseded_by_correction_id'=>$bundle['superseded_by'],
            ],
            'appointment'=>[
                'id'=>$a['id'],'request_id'=>$a['request_id'],'officer_id'=>$a['officer_id'],
                'appointment_type'=>$a['appointment_type'],'service_permanency_snapshot'=>$a['service_permanency_snapshot'],
                'service_permanency_source'=>$a['service_permanency_source'],'asc_location_id'=>$a['asc_location_id'],
                'arpa_division_location_id'=>$a['arpa_division_location_id'],'effective_from'=>$a['effective_from'],
                'record_origin'=>$a['record_origin'],'legacy_history_only'=>(int)$a['legacy_history_only'],
                'legacy_exception'=>(int)$a['legacy_exception'],'legacy_exception_codes'=>$this->decodeNullableJson($a['legacy_exception_codes_json']),
                'hierarchy_snapshot'=>$this->decodeNullableJson($a['hierarchy_snapshot_json']),
                'origin_metadata'=>$this->decodeNullableJson($a['origin_metadata_json']),
            ],
            'closure'=>$cl===null?null:[
                'id'=>$cl['id'],'appointment_id'=>$cl['appointment_id'],'request_id'=>$cl['request_id'],
                'effective_to'=>$cl['effective_to'],'end_reason_id'=>$cl['end_reason_id'],
                'legacy_reason_id'=>$cl['legacy_reason_id'],'legacy_reason_text'=>$cl['legacy_reason_text'],
                'closure_kind'=>$cl['closure_kind'],'closure_source'=>$cl['closure_source'],
                'data_correction_id'=>$cl['data_correction_id'],'record_origin'=>$cl['record_origin'],
                'remarks'=>$cl['remarks'],'context_snapshot'=>$this->decodeJson((string)$cl['context_snapshot_json']),
                'approved_by'=>$cl['approved_by'],'approved_at'=>$cl['approved_at'],
                'approval_timestamp_provenance'=>$cl['approval_timestamp_provenance'],
                'origin_metadata'=>$this->decodeNullableJson($cl['origin_metadata_json']),
            ],
            'request'=>[
                'id'=>$r['id'],'request_type'=>$r['request_type'],'officer_id'=>$r['officer_id'],
                'appointment_type'=>$r['appointment_type'],'source_appointment_id'=>$r['source_appointment_id'],
                'asc_location_id'=>$r['asc_location_id'],'arpa_division_location_id'=>$r['arpa_division_location_id'],
                'requested_effective_from'=>$r['requested_effective_from'],'requested_effective_to'=>$r['requested_effective_to'],
                'end_reason_id'=>$r['end_reason_id'],'workflow_status'=>$r['workflow_status'],'version'=>(int)$r['version'],
                'deleted_at'=>$r['deleted_at'],'deleted_by'=>$r['deleted_by'],'delete_reason'=>$r['delete_reason'],
                'record_origin'=>$r['record_origin'],'legacy_history_only'=>(int)$r['legacy_history_only'],
                'legacy_exception'=>(int)$r['legacy_exception'],'legacy_exception_codes'=>$this->decodeNullableJson($r['legacy_exception_codes_json']),
                'origin_metadata'=>$this->decodeNullableJson($r['origin_metadata_json']),
            ],
            'related_write_set'=>$bundle['related_write_set'],
        ];
    }

    /** @return array{proposed_action:string,proposed_json:array<string,mixed>,correction_reason:string,remarks:?string,evidence_reference:?string} */
    private function normalizeProposal(array $input,string $linkedAppointmentId):array
    {
        $action=strtoupper($this->requiredText($input['proposed_action']??null,'Proposed action',80));
        if(!array_key_exists($action,self::ACTION_FIELDS))throw new DomainException('Unsupported correction amendment action.');
        $proposed=$input['proposed_json']??null;
        if(!is_array($proposed))throw new DomainException('Proposed correction values must be provided as a structured object.');
        if(array_is_list($proposed)&&$proposed!==[])throw new DomainException('Proposed correction values must use named fields.');
        $allowed=self::ACTION_FIELDS[$action];
        foreach(array_keys($proposed) as $field){
            if(!is_string($field)||!in_array($field,$allowed,true))throw new DomainException('The proposed correction contains an unsupported or protected field.');
        }
        $required=match($action){
            'SET_EFFECTIVE_TO'=>['effective_to'],
            'CORRECT_APPOINTMENT_TYPE'=>['appointment_type'],
            'CORRECT_ARPA_DIVISION'=>['arpa_division_location_id'],
            'CORRECT_EFFECTIVE_FROM'=>['effective_from'],
            'CORRECT_END_REASON'=>['end_reason_id'],
            'SELECT_CURRENT_RECORD'=>['appointment_id'],
            default=>[],
        };
        foreach($required as $field)if(!array_key_exists($field,$proposed)||$proposed[$field]===null||$proposed[$field]==='')throw new DomainException('The proposed correction is missing '.$field.'.');
        foreach(['effective_from','effective_to'] as $field)if(array_key_exists($field,$proposed))$proposed[$field]=$this->date($proposed[$field],ucwords(str_replace('_',' ',$field)));
        foreach(['end_reason_id','arpa_division_location_id','appointment_id'] as $field){
            if(array_key_exists($field,$proposed)&&$proposed[$field]!==null){
                if(!is_string($proposed[$field])||!$this->isUuid($proposed[$field]))throw new DomainException(ucwords(str_replace('_',' ',$field)).' must be a valid identifier.');
            }
        }
        if(isset($proposed['appointment_id'])&&(string)$proposed['appointment_id']!==$linkedAppointmentId)throw new DomainException('The selected appointment must be the canonical appointment linked to the source correction.');
        if(array_key_exists('appointment_type',$proposed)){
            if(!is_string($proposed['appointment_type']))throw new DomainException('Appointment Type must be text.');
            $proposed['appointment_type']=strtoupper(trim($proposed['appointment_type']));
            if(!in_array($proposed['appointment_type'],self::APPOINTMENT_TYPES,true))throw new DomainException('Select a valid corrected appointment type.');
        }
        $reason=$this->requiredText($input['correction_reason']??null,'Reason for Correction',500);
        $remarks=$this->optionalText($input['remarks']??null,'Remarks',65535);
        $evidence=$this->optionalText($input['evidence_reference']??null,'Evidence reference',500);
        if($action==='CORRECT_ARPA_DIVISION'&&$evidence===null)throw new DomainException('Evidence reference is required to correct an ARPA Division.');
        return ['proposed_action'=>$action,'proposed_json'=>$proposed,'correction_reason'=>$reason,'remarks'=>$remarks,'evidence_reference'=>$evidence];
    }

    private function assertInputFields(array $input):void
    {
        foreach(array_keys($input) as $field){
            if(!is_string($field)||!in_array($field,self::INPUT_FIELDS,true))throw new DomainException('The amendment contains an unsupported or protected field.');
        }
    }

    private function assertAuthorized(string $actorId,string $ascLocationId):void
    {
        if(!Auth::isCurrentUser($actorId)||(new ArpaAppointmentDataIssueCorrectionService($this->pdo))->canCorrect($actorId,$ascLocationId)===false){
            throw new DomainException('Only the assigned ASC Subject Officer can access this correction amendment.');
        }
    }

    /** @param array<string,mixed> $request @return array{stage:string,action:string,permission:string} */
    private function assertReviewerAuthorized(array $request,string $actorId,?string $requestedAction=null):array
    {
        $status=(string)$request['workflow_status'];
        $profile=self::REVIEW_PROFILES[$status]??null;
        if($profile===null)throw new DomainException('This correction amendment is not awaiting review.');
        if(!Auth::isCurrentUser($actorId)||Auth::activeContextForUser($actorId)===null){
            throw new DomainException('An active working context is required to review this correction amendment.');
        }
        if(!Auth::can($profile['permission'])
            ||!ScopeService::canAccessCurrentArpaStage($actorId,$profile['stage'],(string)$request['asc_location_id'])){
            throw new DomainException('This correction amendment is outside your current review role or scope.');
        }
        if($requestedAction!==null){
            if(in_array($requestedAction,['VERIFY','APPROVE'],true)&&$requestedAction!==$profile['action']){
                throw new DomainException("Action {$requestedAction} is not valid from {$status}.");
            }
            if($requestedAction==='RETURN_FOR_CORRECTION'&&!Auth::can('arpa.appointment.return')){
                throw new DomainException('You are not authorized to return this correction amendment.');
            }
            if($requestedAction==='REJECT'&&!Auth::can('arpa.appointment.reject')){
                throw new DomainException('You are not authorized to reject this correction amendment.');
            }
        }
        return $profile;
    }

    /** @param array<string,mixed> $request */
    private function isAuthorizedHistoricalReviewer(array $request,string $actorId):bool
    {
        if(!Auth::isCurrentUser($actorId)||Auth::activeContextForUser($actorId)===null)return false;
        $stmt=$this->pdo->prepare("SELECT stage,action,previous_status FROM arpa_appointment_correction_amendment_event WHERE amendment_request_id=? AND user_id=? AND stage IN('ASC','DISTRICT','NATIONAL') ORDER BY id DESC");
        $stmt->execute([$request['id'],$actorId]);
        foreach($stmt->fetchAll() as $event){
            $profile=self::REVIEW_PROFILES[(string)$event['previous_status']]??null;
            if($profile===null||(string)$profile['stage']!==(string)$event['stage'])continue;
            if(Auth::can((string)$profile['permission'])&&ScopeService::canAccessCurrentArpaStage($actorId,(string)$event['stage'],(string)$request['asc_location_id']))return true;
        }
        return false;
    }

    /** @param array<string,mixed> $request */
    private function assertMakerChecker(array $request,string $stage,string $actorId):void
    {
        if($stage==='ASC'&&(string)$request['created_by']===$actorId){
            throw new DomainException('Maker-checker policy prevents the creator from approving this correction amendment.');
        }
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM arpa_appointment_correction_amendment_event WHERE amendment_request_id=? AND action='VERIFY' AND stage=? AND user_id=?");
        $stmt->execute([$request['id'],$stage,$actorId]);
        if((int)$stmt->fetchColumn()>0){
            throw new DomainException('Maker-checker policy prevents a verifier from approving the same correction amendment.');
        }
    }

    /** @param array<string,mixed> $request */
    private function assertProposalStillCurrent(array $request):void
    {
        $bundle=$this->sourceBundle((string)$request['source_correction_id'],true);
        if((string)$bundle['appointment']['id']!==(string)$request['appointment_id']
            ||(string)$bundle['officer_id']!==(string)$request['officer_id']
            ||(string)$bundle['asc_location_id']!==(string)$request['asc_location_id']){
            throw new DomainException('The correction amendment no longer matches its source appointment.');
        }
        $this->assertSourceNotSuperseded((string)$request['source_correction_id'],true);
        $this->normalizeProposal([
            'proposed_action'=>$request['proposed_action'],
            'proposed_json'=>$this->decodeJson((string)$request['proposed_json']),
            'correction_reason'=>$request['correction_reason'],
            'remarks'=>$request['remarks'],
            'evidence_reference'=>$request['evidence_reference'],
        ],(string)$request['appointment_id']);
        $currentJson=$this->canonicalJson($this->baseline($bundle));
        $storedJson=$this->canonicalJson($this->decodeJson((string)$request['baseline_json']));
        $storedHash=(string)$request['baseline_hash'];
        if(!hash_equals($storedHash,hash('sha256',$storedJson))
            ||!hash_equals($storedHash,hash('sha256',$currentJson))
            ||$storedJson!==$currentJson){
            throw new DomainException('The source correction or appointment changed after this proposal was drafted. Create a fresh correction proposal from the current data.');
        }
    }

    /** @param array<string,mixed> $request */
    private function assertEditable(array $request):void
    {
        if(!in_array((string)$request['workflow_status'],self::EDITABLE_STATUSES,true))throw new DomainException('Only a Draft or Returned correction amendment can be edited or withdrawn.');
        if($request['applied_correction_id']!==null)throw new DomainException('An applied correction amendment cannot be changed.');
    }

    /** @param array<string,mixed> $request */
    private function assertVersion(array $request,int $expectedVersion):void
    {
        if((int)$request['version']!==$expectedVersion)throw new DomainException('The amendment changed after it was loaded. Reload it and try again.');
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function eventSnapshot(array $request):array
    {
        return [
            'proposed_action'=>$request['proposed_action'],
            'proposed_json'=>$this->decodeJson((string)$request['proposed_json']),
            'correction_reason'=>$request['correction_reason'],
            'remarks'=>$request['remarks'],
            'evidence_reference'=>$request['evidence_reference'],
            'workflow_status'=>$request['workflow_status'],
            'version'=>(int)$request['version'],
        ];
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
    private function event(string $requestId,string $action,string $stage,string $actorId,?string $previousStatus,string $newStatus,?array $before,?array $after,?string $comments=null):void
    {
        $stmt=$this->pdo->prepare('INSERT INTO arpa_appointment_correction_amendment_event(amendment_request_id,action,stage,user_id,comments,previous_status,new_status,before_proposal_json,after_proposal_json) VALUES(?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$requestId,$action,$stage,$actorId,$comments,$previousStatus,$newStatus,$before===null?null:$this->canonicalJson($before),$after===null?null:$this->canonicalJson($after)]);
    }

    /** @return array<int,array<string,mixed>> */
    private function events(string $requestId):array
    {
        $stmt=$this->pdo->prepare("SELECT e.*,COALESCE(NULLIF(u.display_name,''),u.username) performed_by
            FROM arpa_appointment_correction_amendment_event e
            JOIN system_user u ON u.id=e.user_id
            WHERE e.amendment_request_id=? ORDER BY e.id");
        $stmt->execute([$requestId]);$rows=$stmt->fetchAll();
        foreach($rows as &$row){
            $row['before_proposal']=$row['before_proposal_json']===null?null:$this->decodeJson((string)$row['before_proposal_json']);
            $row['after_proposal']=$row['after_proposal_json']===null?null:$this->decodeJson((string)$row['after_proposal_json']);
        }
        unset($row);
        $lastCycleBoundary=0;
        foreach($rows as $row)if(in_array((string)$row['action'],['RETURN_FOR_CORRECTION','REJECT'],true))$lastCycleBoundary=max($lastCycleBoundary,(int)$row['id']);
        foreach($rows as &$row)$row['current_cycle']=(int)$row['id']>$lastCycleBoundary;
        unset($row);return $rows;
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function displayContext(array $request):array
    {
        $stmt=$this->pdo->prepare('SELECT o.dad_number officer_number,o.name_with_initials officer_name,l.dad_number asc_number,l.name_en asc_name FROM officer o JOIN location l ON l.id=? WHERE o.id=?');
        $stmt->execute([$request['asc_location_id'],$request['officer_id']]);$context=$stmt->fetch()?:[];
        $proposed=$this->decodeJson((string)$request['proposed_json']);$labels=[];
        if(!empty($proposed['arpa_division_location_id'])){
            $stmt=$this->pdo->prepare('SELECT CONCAT(dad_number,\' - \',name_en) FROM location WHERE id=?');$stmt->execute([$proposed['arpa_division_location_id']]);$labels['arpa_division_location_id']=$stmt->fetchColumn()?:'Selected ARPA Division';
        }
        if(!empty($proposed['end_reason_id'])){
            $stmt=$this->pdo->prepare('SELECT name_en FROM arpa_appointment_end_reason WHERE id=?');$stmt->execute([$proposed['end_reason_id']]);$labels['end_reason_id']=$stmt->fetchColumn()?:'Selected End Reason';
        }
        $context['proposal_labels']=$labels;return $context;
    }

    /** @param array<string,mixed> $request @param array<int,array<string,mixed>> $events @return array<string,mixed> */
    private function present(array $request,array $events):array
    {
        $request['baseline']=$this->decodeJson((string)$request['baseline_json']);
        $request['proposed']=$this->decodeJson((string)$request['proposed_json']);
        $request['events']=$events;
        return $request;
    }

    /** @return array<string,mixed> */
    private function request(string $id):array
    {
        if(!$this->isUuid($id))throw new DomainException('The correction amendment was not found.');
        $stmt=$this->pdo->prepare('SELECT * FROM arpa_appointment_correction_amendment_request WHERE id=?');$stmt->execute([$id]);$row=$stmt->fetch();
        if(!$row)throw new DomainException('The correction amendment was not found.');return $row;
    }

    /** @return array<string,mixed> */
    private function lockedRequest(string $id):array
    {
        if(!$this->isUuid($id))throw new DomainException('The correction amendment was not found.');
        $stmt=$this->pdo->prepare('SELECT * FROM arpa_appointment_correction_amendment_request WHERE id=? FOR UPDATE');$stmt->execute([$id]);$row=$stmt->fetch();
        if(!$row)throw new DomainException('The correction amendment was not found.');return $row;
    }

    private function requiredText(mixed $value,string $label,int $max):string
    {
        if(!is_string($value))throw new DomainException("{$label} must be text.");$value=trim($value);
        if($value==='')throw new DomainException("{$label} is required.");
        if(mb_strlen($value)>$max)throw new DomainException("{$label} must not exceed {$max} characters.");return $value;
    }

    private function optionalText(mixed $value,string $label,int $max):?string
    {
        if($value===null||$value==='')return null;if(!is_string($value))throw new DomainException("{$label} must be text.");$value=trim($value);
        if($value==='')return null;if(mb_strlen($value)>$max)throw new DomainException("{$label} must not exceed {$max} characters.");return $value;
    }

    private function date(mixed $value,string $label):string
    {
        if(!is_string($value))throw new DomainException("{$label} must be a valid date.");$value=trim($value);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$date||$date->format('Y-m-d')!==$value)throw new DomainException("{$label} must be a valid date.");return $value;
    }

    private function isUuid(string $value):bool{return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$value)===1;}
    private function decodeNullableJson(mixed $value):mixed{return $value===null?null:$this->decodeJson((string)$value);}
    /** @return array<string,mixed>|array<int,mixed> */
    private function decodeJson(string $json):array{$value=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($value))throw new DomainException('Stored amendment JSON must be an object or array.');return $value;}

    private function canonicalJson(mixed $value):string
    {
        return json_encode($this->canonicalize($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }

    private function canonicalize(mixed $value):mixed
    {
        if(!is_array($value))return $value;
        if(array_is_list($value))return array_map(fn(mixed $item):mixed=>$this->canonicalize($item),$value);
        ksort($value,SORT_STRING);foreach($value as &$item)$item=$this->canonicalize($item);unset($item);return $value;
    }

    private function isActiveDuplicate(PDOException $e):bool
    {
        return (string)$e->getCode()==='23000'&&str_contains((string)($e->errorInfo[2]??$e->getMessage()),'uq_arpa_corr_amendment_active');
    }

    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function transaction(callable $work):mixed
    {
        $owned=!$this->pdo->inTransaction();if($owned)$this->pdo->beginTransaction();
        try{$result=$work();if($owned)$this->pdo->commit();return $result;}
        catch(Throwable $e){if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
