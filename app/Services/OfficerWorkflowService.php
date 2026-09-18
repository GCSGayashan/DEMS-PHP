<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Audit,Auth,ScopeService};
use DomainException;
use PDO;
use Throwable;

final class OfficerWorkflowService
{
    private const MAKER_CHECKER=[
        'DISTRICT_SUBJECT_OFFICER'=>'DISTRICT_ADMIN',
        'NATIONAL_SUBJECT_OFFICER'=>'NATIONAL_ADMIN',
    ];

    public function __construct(private readonly PDO $pdo){}

    /** @return array{role_code:?string,scope_location_id:?string} */
    public function creationContext(string $actorId):array
    {
        $context=$this->context($actorId);$role=(string)$context['role_code'];
        if($role==='DISTRICT_SUBJECT_OFFICER'){
            if($context['role_level']!=='DISTRICT'||$context['scope_type']!=='DISTRICT'||$context['scope_mode']!=='INCLUDE_CHILDREN'||empty($context['location_id']))throw new DomainException('A current District working context is required to create this Officer.');
            return ['role_code'=>$role,'scope_location_id'=>(string)$context['location_id']];
        }
        if($role==='NATIONAL_SUBJECT_OFFICER'){
            if($context['role_level']!=='NATIONAL'||$context['scope_type']!=='NATIONAL'||$context['scope_mode']!=='NATIONAL')throw new DomainException('A current National working context is required to create this Officer.');
            return ['role_code'=>$role,'scope_location_id'=>null];
        }
        return ['role_code'=>null,'scope_location_id'=>null];
    }

    public function canAccess(string $officerId,string $actorId):bool
    {
        $row=$this->row($officerId);if(!$row)return false;
        $context=$this->context($actorId,false);if($context===null)return false;
        if($row['approval_status']==='APPROVED'){
            if(ScopeService::canAccessOfficer($actorId,$officerId))return true;
            if($this->hasCurrentApprovedOffice($officerId))return false;
        }
        if($context['role_code']==='SYSTEM_ADMIN')return true;
        if((string)$row['created_by']===$actorId&&$this->makerContextMatches($row,$context))return true;
        if($row['workflow_origin_role_code']===null&&Auth::can('officer.approve'))return true;
        return $this->checkerContextMatches($row,$context);
    }

    public function assertEditable(string $officerId,string $actorId):void
    {
        $row=$this->requiredRow($officerId);
        if($row['approval_status']==='APPROVED'){
            OfficerAdminDirectEditPolicy::assert($actorId);
            return;
        }
        $context=$this->context($actorId);
        if($row['approval_status']!=='DRAFT'||(string)$row['created_by']!==$actorId||!$this->makerContextMatches($row,$context))throw new DomainException('Only the maker may correct a returned Officer in the original working context.');
    }

    public function submit(string $officerId,string $actorId):void
    {
        $this->transaction(function()use($officerId,$actorId):void{
            $row=$this->lockedRow($officerId);$context=$this->context($actorId);
            if($row['approval_status']!=='DRAFT')throw new DomainException('Only a returned Officer draft can be resubmitted.');
            if((string)$row['created_by']!==$actorId||!$this->makerContextMatches($row,$context))throw new DomainException('Only the maker may resubmit this Officer in the original working context.');
            OfficerPersonnelValidator::servicePermanency($row['arpa_service_permanency']??null,$row['service_permanented_date']??null);
            OfficerPersonnelValidator::contactNumbers($row['primary_mobile']??null,$row['alternative_mobile']??null);
            (new OfficerOfficeAssignmentService($this->pdo))->submitInitialForOfficer($officerId,$actorId);
            $stmt=$this->pdo->prepare("UPDATE officer SET approval_status='SUBMITTED',submitted_by=?,submitted_at=NOW(),updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND version=?");
            $stmt->execute([$actorId,$actorId,$officerId,$row['version']]);if($stmt->rowCount()!==1)throw new DomainException('The Officer changed while it was being submitted.');
            Audit::record('workflow.submit','OFFICER',$officerId,['from_status'=>'DRAFT','to_status'=>'SUBMITTED','working_context'=>$this->auditContext($context)]);
            $notice=new WorkflowNotificationService($this->pdo);$notice->completeStage('OFFICER',$officerId,'CORRECTION',$actorId,'Officer resubmitted');
            $target=$row['workflow_scope_location_id']?:null;$roles=$row['workflow_origin_role_code']==='DISTRICT_SUBJECT_OFFICER'?['DISTRICT_ADMIN']:($row['workflow_origin_role_code']==='NATIONAL_SUBJECT_OFFICER'?['NATIONAL_ADMIN']:[]);
            $notice->actionForPermission('officer.approve',$target,'OFFICER','New Officer Awaiting Approval','A submitted Officer record is ready for review.','OFFICER',$officerId,'APPROVAL','/hr/officers/'.$officerId,$actorId,$roles);
        });
    }

    public function approve(string $officerId,string $actorId):void
    {
        $this->transaction(function()use($officerId,$actorId):void{
            $row=$this->lockedRow($officerId);$context=$this->context($actorId);
            if($row['approval_status']!=='SUBMITTED')throw new DomainException('Only a submitted Officer can be approved.');
            if((string)$row['created_by']===$actorId||(string)$row['submitted_by']===$actorId)throw new DomainException('Maker cannot approve their own Officer record.');
            $this->assertChecker($row,$context);
            OfficerPersonnelValidator::servicePermanency($row['arpa_service_permanency']??null,$row['service_permanented_date']??null);
            OfficerPersonnelValidator::contactNumbers($row['primary_mobile']??null,$row['alternative_mobile']??null);
            $stmt=$this->pdo->prepare("UPDATE officer SET approval_status='APPROVED',operational_status=CASE WHEN effective_from<=CURRENT_DATE() THEN 'ACTIVE' ELSE 'INACTIVE' END,approved_by=?,approved_at=NOW(),updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND version=?");
            $stmt->execute([$actorId,$actorId,$officerId,$row['version']]);if($stmt->rowCount()!==1)throw new DomainException('The Officer changed while it was being approved.');
            (new OfficerOfficeAssignmentService($this->pdo))->approveInitialForOfficer($officerId,$actorId);
            Audit::record('workflow.approve','OFFICER',$officerId,['from_status'=>'SUBMITTED','to_status'=>'APPROVED','working_context'=>$this->auditContext($context)]);
            $notice=new WorkflowNotificationService($this->pdo);$notice->completeStage('OFFICER',$officerId,'APPROVAL',$actorId,'Officer approved');
            if(!empty($row['created_by']))$notice->information((string)$row['created_by'],'OFFICER','Officer Request Approved','Your Officer request has been approved.','OFFICER',$officerId,'/hr/officers/'.$officerId,$actorId);
        });
    }

    public function returnForCorrection(string $officerId,string $reason,string $actorId):void
    {
        $reason=trim($reason);if($reason==='')throw new DomainException('A correction reason is required.');
        $this->transaction(function()use($officerId,$reason,$actorId):void{
            $row=$this->lockedRow($officerId);$context=$this->context($actorId);
            if($row['approval_status']!=='SUBMITTED')throw new DomainException('Only a submitted Officer can be returned.');
            if((string)$row['created_by']===$actorId||(string)$row['submitted_by']===$actorId)throw new DomainException('Maker cannot check their own Officer record.');
            $this->assertChecker($row,$context);
            (new OfficerOfficeAssignmentService($this->pdo))->returnInitialForCorrection($officerId,$reason,$actorId);
            $stmt=$this->pdo->prepare("UPDATE officer SET approval_status='DRAFT',returned_by=?,returned_at=NOW(),action_reason=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND version=?");
            $stmt->execute([$actorId,$reason,$actorId,$officerId,$row['version']]);if($stmt->rowCount()!==1)throw new DomainException('The Officer changed while it was being returned.');
            Audit::record('workflow.return','OFFICER',$officerId,['from_status'=>'SUBMITTED','to_status'=>'DRAFT','reason'=>$reason,'working_context'=>$this->auditContext($context)]);
            $notice=new WorkflowNotificationService($this->pdo);$notice->resolveAll('OFFICER',$officerId,$actorId,'Officer returned for correction');
            $notice->actionForUser((string)$row['created_by'],'OFFICER','Officer Returned for Correction','Correction is required: '.$reason,'OFFICER',$officerId,'CORRECTION','/hr/officers/'.$officerId.'/edit',$actorId);
        });
    }

    /** @return array{where:array<int,string>,params:array<int,string>} */
    public function queueAccess(string $actorId,string $alias='o'):array
    {
        $context=$this->context($actorId,false);if($context===null)return ['where'=>['1=0'],'params'=>[]];
        $role=(string)$context['role_code'];$where=["({$alias}.approval_status IN('DRAFT','SUBMITTED') OR ({$alias}.approval_status='APPROVED' AND NOT EXISTS(SELECT 1 FROM officer_office_assignment woa WHERE woa.officer_id={$alias}.id AND woa.active=1 AND woa.approval_status='APPROVED' AND woa.effective_from<=CURRENT_DATE() AND (woa.effective_to IS NULL OR woa.effective_to>=CURRENT_DATE()))))"];$params=[];
        if($role==='SYSTEM_ADMIN')return ['where'=>$where,'params'=>$params];
        if(isset(self::MAKER_CHECKER[$role])){
            $where[]="{$alias}.created_by=?";$params[]=$actorId;$where[]="{$alias}.workflow_origin_role_code=?";$params[]=$role;
            if($role==='DISTRICT_SUBJECT_OFFICER'){$where[]="{$alias}.workflow_scope_location_id=?";$params[]=(string)$context['location_id'];}
            return ['where'=>$where,'params'=>$params];
        }
        $maker=array_search($role,self::MAKER_CHECKER,true);
        if($maker!==false){$where[]="{$alias}.workflow_origin_role_code=?";$params[]=$maker;if($role==='DISTRICT_ADMIN'){$where[]="{$alias}.workflow_scope_location_id=?";$params[]=(string)$context['location_id'];}return ['where'=>$where,'params'=>$params];}
        if(Auth::can('officer.approve')){$where[]="{$alias}.workflow_origin_role_code IS NULL";return ['where'=>$where,'params'=>$params];}
        return ['where'=>['1=0'],'params'=>[]];
    }

    public function actions(string $officerId,string $actorId):array
    {
        $row=$this->requiredRow($officerId);$context=$this->context($actorId,false);
        $maker=$context!==null&&(string)$row['created_by']===$actorId&&$this->makerContextMatches($row,$context);
        $checker=$context!==null&&($this->checkerContextMatches($row,$context)||($row['workflow_origin_role_code']===null&&Auth::can('officer.approve')))&&(string)$row['created_by']!==$actorId&&(string)$row['submitted_by']!==$actorId;
        $approvedEdit=$row['approval_status']==='APPROVED'&&OfficerAdminDirectEditPolicy::allowed();
        return ['can_edit'=>Auth::can('officer.edit')&&(($row['approval_status']==='DRAFT'&&$maker)||$approvedEdit),'can_submit'=>$row['approval_status']==='DRAFT'&&$maker&&Auth::can('officer.submit'),'can_approve'=>$row['approval_status']==='SUBMITTED'&&$checker&&Auth::can('officer.approve'),'can_return'=>$row['approval_status']==='SUBMITTED'&&$checker&&Auth::can('officer.return')];
    }

    public function notifyExistingSubmission(string $officerId,string $actorId):void
    {
        $row=$this->requiredRow($officerId);if((string)$row['approval_status']!=='SUBMITTED')return;$target=$row['workflow_scope_location_id']?:null;$roles=$row['workflow_origin_role_code']==='DISTRICT_SUBJECT_OFFICER'?['DISTRICT_ADMIN']:($row['workflow_origin_role_code']==='NATIONAL_SUBJECT_OFFICER'?['NATIONAL_ADMIN']:[]);
        (new WorkflowNotificationService($this->pdo))->actionForPermission('officer.approve',$target,'OFFICER','New Officer Awaiting Approval','A submitted Officer record is ready for review.','OFFICER',$officerId,'APPROVAL','/hr/officers/'.$officerId,$actorId,$roles);
    }

    private function assertChecker(array $row,array $context):void
    {
        if($context['role_code']==='SYSTEM_ADMIN')return;
        if($row['workflow_origin_role_code']===null)return; // Existing legacy HR workflow remains unchanged.
        if(!$this->checkerContextMatches($row,$context))throw new DomainException('This Officer submission is outside your authorized maker-checker scope.');
    }

    private function checkerContextMatches(array $row,array $context):bool
    {
        $maker=(string)($row['workflow_origin_role_code']??'');$checker=self::MAKER_CHECKER[$maker]??null;
        if($checker===null)return false;if((string)$context['role_code']!==$checker)return false;
        if($checker==='DISTRICT_ADMIN')return $context['role_level']==='DISTRICT'&&$context['scope_type']==='DISTRICT'&&$context['scope_mode']==='INCLUDE_CHILDREN'&&(string)$context['location_id']===(string)$row['workflow_scope_location_id'];
        return $context['role_level']==='NATIONAL'&&$context['scope_type']==='NATIONAL'&&$context['scope_mode']==='NATIONAL'&&$row['workflow_scope_location_id']===null;
    }

    private function makerContextMatches(array $row,array $context):bool
    {
        $role=(string)($row['workflow_origin_role_code']??'');if($role==='')return true;if($role!==(string)$context['role_code'])return false;
        return $role!=='DISTRICT_SUBJECT_OFFICER'||(string)$row['workflow_scope_location_id']===(string)$context['location_id'];
    }

    private function context(string $actorId,bool $required=true):?array
    {
        $context=Auth::activeContextForUser($actorId);if($context===null&&$required)throw new DomainException('Select a current working context.');return $context;
    }
    private function row(string $id):array|false{$s=$this->pdo->prepare('SELECT * FROM officer WHERE id=?');$s->execute([$id]);return $s->fetch();}
    private function hasCurrentApprovedOffice(string $id):bool{$s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id=? AND active=1 AND approval_status='APPROVED' AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE())");$s->execute([$id]);return (int)$s->fetchColumn()>0;}
    private function requiredRow(string $id):array{$row=$this->row($id);if(!$row)throw new DomainException('Officer record was not found.');return $row;}
    private function lockedRow(string $id):array{$s=$this->pdo->prepare('SELECT * FROM officer WHERE id=? FOR UPDATE');$s->execute([$id]);$row=$s->fetch();if(!$row)throw new DomainException('Officer record was not found.');return $row;}
    private function auditContext(array $context):array{return ['role_code'=>$context['role_code'],'role_assignment_id'=>$context['role_assignment_id'],'scope_assignment_id'=>$context['scope_assignment_id'],'scope_location_id'=>$context['location_id']];}
    private function transaction(callable $callback):void{if($this->pdo->inTransaction()){$callback();return;}$this->pdo->beginTransaction();try{$callback();$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
}
