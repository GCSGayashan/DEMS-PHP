<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{Auth,ScopeService};
use DomainException;
use PDO;
use Throwable;

final class OfficerOfficeAssignmentService
{
    public const INITIAL_OFFICER_REASON='Initial Office assignment selected during Officer creation';
    public const USER_ACCOUNT_REQUEST_INITIAL_REASON='Initial Office for user account request';

    public function __construct(private readonly PDO $pdo) {}

    public function saveInitialForOfficer(string $officerId,?string $officeId,?string $effectiveFrom,string $actorId):?string
    {
        $officeId=trim((string)$officeId);$effectiveFrom=trim((string)$effectiveFrom);
        return $this->transaction(function()use($officerId,$officeId,$effectiveFrom,$actorId):?string{
            $officer=$this->pdo->prepare('SELECT id,approval_status,created_by FROM officer WHERE id=? FOR UPDATE');$officer->execute([$officerId]);$officerRow=$officer->fetch();
            if(!$officerRow||!in_array((string)$officerRow['approval_status'],['DRAFT','SUBMITTED'],true))throw new DomainException('The initial Office can be changed only while the Officer is awaiting approval or correction.');
            if((string)$officerRow['created_by']!==$actorId)throw new DomainException('Only the Officer maker may select the initial Office.');
            $existing=$this->lockedInitialForOfficer($officerId);
            if($officeId==='')return $existing['id']??null;
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$effectiveFrom))throw new DomainException('Select a valid Office Assignment Effective From date.');
            if(!ScopeService::canAccessOffice($actorId,$officeId))throw new DomainException('You cannot select this Office.');
            $this->assertActiveOffice($officeId);
            $existingId=(string)($existing['id']??'');$dup=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE deleted_at IS NULL AND id<>? AND officer_id=? AND office_id=? AND ((approval_status IN('DRAFT','SUBMITTED','RETURNED')) OR (approval_status='APPROVED' AND active=1)) AND (effective_to IS NULL OR effective_to>=?)");$dup->execute([$existingId,$officerId,$officeId,$effectiveFrom]);
            if((int)$dup->fetchColumn()>0)throw new DomainException('An overlapping Office assignment already exists for this Officer and Office.');
            $primary=$effectiveFrom<=date('Y-m-d')?1:0;
            if($existing){
                if(!in_array((string)$existing['approval_status'],['DRAFT','RETURNED'],true))throw new DomainException('The submitted initial Office assignment must be returned before it can be changed.');
                $before=$existing;$this->pdo->prepare('UPDATE officer_office_assignment SET office_id=?,effective_from=?,is_primary=?,active=0,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?')->execute([$officeId,$effectiveFrom,$primary,$actorId,$existing['id']]);
                $this->event((string)$existing['id'],'UPDATED',$before,$this->row((string)$existing['id']),'Initial Office corrected with the returned Officer.',$actorId);return (string)$existing['id'];
            }
            $id=$this->uuid();$status=(string)$officerRow['approval_status'];$submitted=$status==='SUBMITTED';
            $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,is_primary,active,reason,remarks,approval_status,created_by,submitted_by,submitted_at) VALUES(?,?,?,?,?,0,?,?,?,?,?,IF(?,NOW(),NULL))")
                ->execute([$id,$officerId,$officeId,$effectiveFrom,$primary,self::INITIAL_OFFICER_REASON,'Created with the Officer submission.',$status,$actorId,$submitted?$actorId:null,$submitted?1:0]);
            $this->event($id,$status,null,$this->row($id),self::INITIAL_OFFICER_REASON,$actorId);if($submitted)$this->notifySubmitted($this->row($id),$actorId);return $id;
        });
    }

    public function initialForOfficer(string $officerId):?array
    {
        $rows=$this->initialRows($officerId,false);if(count($rows)>1)throw new DomainException('The Officer has multiple initial Office assignments and requires review.');return $rows[0]??null;
    }

    public function returnInitialForCorrection(string $officerId,string $reason,string $actorId):void
    {
        $assignment=$this->initialForOfficer($officerId);if(!$assignment)return;
        $this->transaction(function()use($assignment,$reason,$actorId):void{
            $row=$this->locked((string)$assignment['id']);if($row['approval_status']!=='SUBMITTED')throw new DomainException('The initial Office assignment is not awaiting approval.');if($row['created_by']===$actorId||$row['submitted_by']===$actorId)throw new DomainException('Maker-checker policy prevents self-review.');$this->assertScope($row,$actorId);$before=$row;
            $this->pdo->prepare("UPDATE officer_office_assignment SET approval_status='RETURNED',active=0,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?")->execute([$actorId,$row['id']]);$this->event((string)$row['id'],'RETURNED',$before,$this->row((string)$row['id']),$reason,$actorId);
        });
    }

    public function submitInitialForOfficer(string $officerId,string $actorId):void
    {
        $assignment=$this->initialForOfficer($officerId);if(!$assignment||$assignment['approval_status']==='SUBMITTED')return;$this->submit((string)$assignment['id'],$actorId);
    }

    public function approveInitialForOfficer(string $officerId,string $actorId):void
    {
        $assignment=$this->initialForOfficer($officerId);
        if(!$assignment)throw new DomainException('The submitted Officer does not have a valid initial Office assignment.');
        $this->approve((string)$assignment['id'],$actorId);
    }

    public function create(array $data,string $actorId):string
    {
        $this->assertCreationPermission($actorId);
        $returnedId=trim((string)($data['assignment_id']??''));
        if($returnedId!=='')return $this->resubmitReturned($returnedId,$data,$actorId);
        if(trim((string)($data['reason']??''))===self::USER_ACCOUNT_REQUEST_INITIAL_REASON)throw new DomainException('The User Account Request initial-Office reason is reserved for that workflow.');
        return $this->createSubmitted($data,$actorId,false);
    }

    /** Dedicated entry point for the parent User Account Request transaction. */
    public function createForUserAccountRequest(array $data,string $actorId):string
    {
        if(!Auth::isCurrentUser($actorId)||!Auth::can('user.request'))throw new DomainException('You are not authorized to create the initial User Account Office assignment.');
        $data['reason']=self::USER_ACCOUNT_REQUEST_INITIAL_REASON;
        return $this->createSubmitted($data,$actorId,true);
    }

    private function createSubmitted(array $data,string $actorId,bool $userAccountInitial):string
    {
        $officer=trim((string)($data['officer_id']??''));$office=trim((string)($data['office_id']??''));
        $from=trim((string)($data['effective_from']??''));$reason=trim((string)($data['reason']??''));
        if($officer===''||$office===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)||$reason==='')throw new DomainException('Officer, Office, Start Date, and Reason are required.');
        if(!ScopeService::canAccessOffice($actorId,$office))throw new DomainException('You cannot select this Office.');
        $this->assertActiveOffice($office);$workflow=$this->creationWorkflowContext($actorId);
        return $this->transaction(function()use($data,$actorId,$officer,$office,$from,$reason,$workflow,$userAccountInitial):string{
            $officerLock=$this->pdo->prepare('SELECT * FROM officer WHERE id=? FOR UPDATE');$officerLock->execute([$officer]);$officerRow=$officerLock->fetch();if(!$officerRow)throw new DomainException('Officer was not found.');
            if($this->isSubjectOfficerWorkflow($workflow)&&!$userAccountInitial){
                if((string)$officerRow['approval_status']!=='APPROVED')throw new DomainException('Only an approved Officer can receive this Office assignment.');
                if(!ScopeService::canAccessOfficerForOfficeAssignment($actorId,$officer))throw new DomainException('This Officer is outside your current Office-assignment scope.');
                if($this->hasOpenApprovedAssignment($officer,true))throw new DomainException('This Officer already has an approved current or future Office assignment.');
                if($this->hasPendingAssignment($officer))throw new DomainException('This Officer already has a pending Office assignment request.');
            }
            $dup=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE deleted_at IS NULL AND officer_id=? AND office_id=? AND ((approval_status IN('DRAFT','SUBMITTED','RETURNED')) OR (approval_status='APPROVED' AND active=1)) AND effective_from=?");$dup->execute([$officer,$office,$from]);
            if((int)$dup->fetchColumn()>0)throw new DomainException('An Office assignment already exists for this Officer, Office and effective date.');
            $id=$this->uuid();$primary=!empty($data['is_primary'])?1:0;
            if($primary===1&&$from>date('Y-m-d'))throw new DomainException('A future Office assignment can be set as Primary when it becomes effective.');
            $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,is_primary,active,reason,official_reference,remarks,approval_status,created_by,submitted_by,submitted_at,workflow_origin_role_code,workflow_scope_location_id) VALUES(?,?,?,?,?,0,?,?,?,'SUBMITTED',?,?,NOW(),?,?)")
                ->execute([$id,$officer,$office,$from,$primary,$reason,$this->null($data['official_reference']??null),$this->null($data['remarks']??null),$actorId,$actorId,$workflow['role_code'],$workflow['scope_location_id']]);
            $this->event($id,'SUBMITTED',null,$this->row($id),$reason,$actorId);$this->notifySubmitted($this->row($id),$actorId);return $id;
        });
    }

    /** @return array<string,mixed>|null */
    public function returnedForMaker(string $officerId,string $actorId):?array
    {
        $s=$this->pdo->prepare("SELECT * FROM officer_office_assignment WHERE deleted_at IS NULL AND officer_id=? AND created_by=? AND approval_status='RETURNED' ORDER BY updated_at DESC,created_at DESC,id DESC LIMIT 1");
        $s->execute([$officerId,$actorId]);$row=$s->fetch();if(!$row)return null;
        try{$this->assertCreationContext($row,$actorId);}catch(DomainException){return null;}
        return $row;
    }

    public function resubmitReturned(string $id,array $data,string $actorId):string
    {
        $this->assertCreationPermission($actorId);
        $office=trim((string)($data['office_id']??''));$from=trim((string)($data['effective_from']??''));$reason=trim((string)($data['reason']??''));
        if($office===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)||$reason==='')throw new DomainException('Office, Start Date, and Reason are required.');
        if(!ScopeService::canAccessOffice($actorId,$office))throw new DomainException('You cannot select this Office.');$this->assertActiveOffice($office);
        return $this->transaction(function()use($id,$data,$actorId,$office,$from,$reason):string{
            $row=$this->locked($id);if((string)$row['approval_status']!=='RETURNED'||(string)$row['created_by']!==$actorId)throw new DomainException('The returned Office assignment was not found.');$this->assertCreationContext($row,$actorId);
            $officer=$this->pdo->prepare('SELECT * FROM officer WHERE id=? FOR UPDATE');$officer->execute([$row['officer_id']]);$officerRow=$officer->fetch();if(!$officerRow)throw new DomainException('Officer was not found.');
            if($this->isSubjectOfficerRow($row)){
                if((string)$officerRow['approval_status']!=='APPROVED')throw new DomainException('Only an approved Officer can receive this Office assignment.');
                if(!ScopeService::canAccessOfficerForOfficeAssignment($actorId,(string)$row['officer_id']))throw new DomainException('This Officer is outside your current Office-assignment scope.');
                if($this->hasOpenApprovedAssignment((string)$row['officer_id'],true))throw new DomainException('This Officer already has an approved current or future Office assignment.');
                if($this->hasPendingAssignment((string)$row['officer_id'],$id))throw new DomainException('This Officer already has a pending Office assignment request.');
            }
            $dup=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE deleted_at IS NULL AND id<>? AND officer_id=? AND office_id=? AND ((approval_status IN('DRAFT','SUBMITTED','RETURNED')) OR (approval_status='APPROVED' AND active=1)) AND effective_from=? FOR UPDATE");$dup->execute([$id,$row['officer_id'],$office,$from]);if((int)$dup->fetchColumn()>0)throw new DomainException('An Office assignment already exists for this Officer, Office and effective date.');
            $before=$row;$primary=!empty($data['is_primary'])?1:0;if($primary===1&&$from>date('Y-m-d'))throw new DomainException('A future Office assignment can be set as Primary when it becomes effective.');
            $u=$this->pdo->prepare("UPDATE officer_office_assignment SET office_id=?,effective_from=?,is_primary=?,reason=?,official_reference=?,remarks=?,approval_status='SUBMITTED',active=0,submitted_by=?,submitted_at=NOW(),updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND approval_status='RETURNED'");
            $u->execute([$office,$from,$primary,$reason,$this->null($data['official_reference']??null),$this->null($data['remarks']??null),$actorId,$actorId,$id]);if($u->rowCount()!==1)throw new DomainException('The returned Office assignment changed while it was being resubmitted.');
            $this->event($id,'RESUBMITTED',$before,$this->row($id),$reason,$actorId);$this->notifySubmitted($this->row($id),$actorId);return $id;
        });
    }

    public function submit(string $id,string $actorId):void
    {
        $this->transaction(function()use($id,$actorId):void{$r=$this->locked($id);if($r['approval_status']!=='DRAFT'&&$r['approval_status']!=='RETURNED')throw new DomainException('Only a draft or returned Office assignment may be submitted.');if($r['created_by']!==$actorId)throw new DomainException('Only the creator may submit this Office assignment.');$this->assertCreationContext($r,$actorId);$before=$r;$this->pdo->prepare("UPDATE officer_office_assignment SET approval_status='SUBMITTED',active=0,submitted_by=?,submitted_at=NOW(),updated_by=?,version=version+1 WHERE id=?")->execute([$actorId,$actorId,$id]);$this->event($id,'SUBMITTED',$before,$this->row($id),null,$actorId);$this->notifySubmitted($this->row($id),$actorId);});
    }

    public function approve(string $id,string $actorId):void
    {
        $this->transaction(function()use($id,$actorId):void{$r=$this->locked($id);if($this->isUserAccountRequestInitial($r))throw new DomainException('This initial Office assignment can only be approved with its User Account Request.');$this->approveLocked($r,$actorId,true);});
    }

    public function approveInitialForUserAccountRequest(string $id,string $userRequestId,string $actorId):void
    {
        $this->transaction(function()use($id,$userRequestId,$actorId):void{
            $r=$this->locked($id);$s=$this->pdo->prepare('SELECT id,officer_id,identity_type,identity_source,approval_status,requested_by FROM system_user WHERE id=? FOR UPDATE');$s->execute([$userRequestId]);$user=$s->fetch();
            if(!$user||(string)$user['approval_status']!=='SUBMITTED'||(string)$user['identity_type']!=='STAFF'||(string)$user['identity_source']!==UserAccountRequestService::SOURCE_MANUAL||(string)$user['officer_id']!==(string)$r['officer_id']||(string)$user['requested_by']!==(string)$r['created_by']||!$this->isUserAccountRequestInitial($r))throw new DomainException('The Office assignment is not the valid initial assignment for this User Account Request.');
            $this->approveLocked($r,$actorId,false);
        });
    }

    /** Limited review model authorized by the proposed target Office, not by current Officer-directory membership. */
    public function reviewForApproval(string $id,string $actorId):array
    {
        $this->assertApprovalPermission($actorId);
        $s=$this->pdo->prepare("SELECT a.*,f.dad_number officer_dad,f.name_with_initials officer_name,f.nic,d.name_en designation_name,o.dad_number office_dad,o.name_en office_name,ot.name_en office_type,l.dad_number location_dad,l.name_en location_name,su.display_name submitted_by_name,su.username submitted_by_username FROM officer_office_assignment a JOIN officer f ON f.id=a.officer_id LEFT JOIN designation d ON d.id=f.primary_designation_id JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id LEFT JOIN system_user su ON su.id=a.submitted_by WHERE a.id=? AND a.deleted_at IS NULL AND a.approval_status='SUBMITTED' AND (a.reason IS NULL OR a.reason<>?)");
        $s->execute([$id,self::USER_ACCOUNT_REQUEST_INITIAL_REASON]);$row=$s->fetch();if(!$row)throw new DomainException('The submitted Office assignment was not found.');if($row['created_by']===$actorId||$row['submitted_by']===$actorId)throw new DomainException('Maker-checker policy prevents self-approval.');$this->assertApprovalContext($row,$actorId);
        $allowed=array_column(ScopeService::scopedOffices($actorId),'id');$where=$allowed===[]?'1=0':'a.office_id IN ('.implode(',',array_fill(0,count($allowed),'?')).')';
        $current=$this->pdo->prepare("SELECT o.dad_number office_dad,o.name_en office_name,ot.name_en office_type,l.name_en location_name,a.effective_from,a.is_primary FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id WHERE a.deleted_at IS NULL AND a.officer_id=? AND a.approval_status='APPROVED' AND a.active=1 AND a.effective_from<=CURRENT_DATE() AND (a.effective_to IS NULL OR a.effective_to>=CURRENT_DATE()) AND {$where} ORDER BY a.is_primary DESC,o.name_en");
        $current->execute(array_merge([(string)$row['officer_id']],$allowed));$row['current_offices']=$current->fetchAll();return $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function unassignedOfficers(string $actorId):array
    {
        if(!Auth::isCurrentUser($actorId)||(!Auth::can('officer.office-assignment.create')&&!Auth::can('officer.office-assignment.approve')))throw new DomainException('Unassigned Officer access is not permitted.');
        $access=ScopeService::officeAssignmentCandidateAccess($actorId,'f');$where=$access['where'];$where[]="f.approval_status='APPROVED'";$where[]="NOT EXISTS(SELECT 1 FROM officer_office_assignment current_oa WHERE current_oa.deleted_at IS NULL AND current_oa.officer_id=f.id AND current_oa.active=1 AND current_oa.approval_status='APPROVED' AND (current_oa.effective_to IS NULL OR current_oa.effective_to>=CURRENT_DATE()))";
        $sql=$access['with']." SELECT f.id,f.dad_number,f.name_with_initials,f.nic,d.name_en designation_name,c.name_en class_name,os.name_en officer_status_name,
                    COUNT(DISTINCT pending.id) pending_count,
                    GROUP_CONCAT(DISTINCT CONCAT(po.dad_number,' - ',po.name_en) ORDER BY po.name_en SEPARATOR '; ') requested_office,
                    GROUP_CONCAT(DISTINCT COALESCE(maker.display_name,maker.username) ORDER BY COALESCE(maker.display_name,maker.username) SEPARATOR '; ') submitted_by_name,
                    MAX(pending.submitted_at) pending_submitted_at,
                    MAX(CASE WHEN pending.created_by=? THEN pending.id END) maker_pending_id,
                    MAX(CASE WHEN returned.created_by=? THEN returned.id END) returned_assignment_id
                FROM officer f
                LEFT JOIN designation d ON d.id=f.primary_designation_id
                LEFT JOIN officer_class c ON c.id=f.class_id
                LEFT JOIN officer_status os ON os.id=f.officer_status_id
                LEFT JOIN officer_office_assignment pending ON pending.officer_id=f.id AND pending.deleted_at IS NULL AND pending.approval_status='SUBMITTED' AND pending.reason<>?
                LEFT JOIN office po ON po.id=pending.office_id
                LEFT JOIN system_user maker ON maker.id=pending.submitted_by
                LEFT JOIN officer_office_assignment returned ON returned.officer_id=f.id AND returned.deleted_at IS NULL AND returned.approval_status='RETURNED' AND returned.reason<>?
                WHERE ".implode(' AND ',$where)."
                GROUP BY f.id,f.dad_number,f.name_with_initials,f.nic,d.name_en,c.name_en,os.name_en
                ORDER BY f.dad_number";
        $params=array_merge($access['params'],[$actorId,$actorId,self::USER_ACCOUNT_REQUEST_INITIAL_REASON,self::USER_ACCOUNT_REQUEST_INITIAL_REASON]);
        $s=$this->pdo->prepare($sql);$s->execute($params);$rows=$s->fetchAll();
        foreach($rows as &$row){$row['assignment_status']=(int)$row['pending_count']>0?'Pending Assignment':(!empty($row['returned_assignment_id'])?'Returned for Correction':'Unassigned');$row['can_assign']=Auth::can('officer.office-assignment.create')&&(int)$row['pending_count']===0;}unset($row);
        return $rows;
    }

    /** @return array{officer:array<string,mixed>,offices:array<int,array<string,mixed>>,returnedAssignment:?array<string,mixed>,currentOfficeLabel:string} */
    public function assignmentForm(string $officerId,string $actorId):array
    {
        $this->assertCreationPermission($actorId);$s=$this->pdo->prepare("SELECT f.*,d.name_en designation_name,os.name_en officer_status_name FROM officer f LEFT JOIN designation d ON d.id=f.primary_designation_id LEFT JOIN officer_status os ON os.id=f.officer_status_id WHERE f.id=?");$s->execute([$officerId]);$officer=$s->fetch();if(!$officer)throw new DomainException('Officer was not found.');
        $workflow=$this->creationWorkflowContext($actorId);$returned=$this->returnedForMaker($officerId,$actorId);
        if($this->isSubjectOfficerWorkflow($workflow)){
            if((string)$officer['approval_status']!=='APPROVED'||!ScopeService::canAccessOfficerForOfficeAssignment($actorId,$officerId))throw new DomainException('This Officer is outside your current Office-assignment scope.');
            if($this->hasOpenApprovedAssignment($officerId))throw new DomainException('This Officer already has an approved current or future Office assignment.');
            if($this->hasPendingAssignment($officerId))throw new DomainException('This Officer already has a pending Office assignment request.');
        }
        $current=$this->pdo->prepare("SELECT GROUP_CONCAT(DISTINCT CONCAT(o.dad_number,' - ',o.name_en) ORDER BY o.name_en SEPARATOR '; ') FROM officer_office_assignment a JOIN office o ON o.id=a.office_id WHERE a.deleted_at IS NULL AND a.officer_id=? AND a.active=1 AND a.approval_status='APPROVED' AND a.effective_from<=CURRENT_DATE() AND (a.effective_to IS NULL OR a.effective_to>=CURRENT_DATE())");$current->execute([$officerId]);$currentOfficeLabel=(string)($current->fetchColumn()?:'Unassigned');
        return ['officer'=>$officer,'offices'=>$this->eligibleOffices($actorId),'returnedAssignment'=>$returned,'currentOfficeLabel'=>$currentOfficeLabel];
    }

    /** @return array{can_assign:bool,pending:?array<string,mixed>,returned:?array<string,mixed>} */
    public function actionForOfficer(string $officerId,string $actorId):array
    {
        $pending=$this->pendingForOfficer($officerId);$returned=$this->returnedForMaker($officerId,$actorId);$can=false;
        if(Auth::isCurrentUser($actorId)&&Auth::can('officer.office-assignment.create')&&$pending===null){
            try{$workflow=$this->creationWorkflowContext($actorId);$can=!$this->isSubjectOfficerWorkflow($workflow)||(!$this->hasOpenApprovedAssignment($officerId)&&ScopeService::canAccessOfficerForOfficeAssignment($actorId,$officerId));}catch(DomainException){$can=false;}
        }
        return ['can_assign'=>$can,'pending'=>$pending,'returned'=>$returned];
    }

    public function returnForCorrection(string $id,string $reason,string $actorId):void
    {
        $this->reviewDecision($id,$reason,$actorId,'RETURNED');
    }

    public function reject(string $id,string $reason,string $actorId):void
    {
        $this->reviewDecision($id,$reason,$actorId,'REJECTED');
    }

    public function end(string $id,string $effectiveTo,string $reason,string $actorId):void
    {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$effectiveTo)||trim($reason)==='')throw new DomainException('End Date and Reason are required.');
        $this->transaction(function()use($id,$effectiveTo,$reason,$actorId):void{$r=$this->locked($id);if($r['approval_status']!=='APPROVED'||!(int)$r['active'])throw new DomainException('Only an approved active Office assignment may be ended.');if($effectiveTo<$r['effective_from'])throw new DomainException('Effective To cannot precede Effective From.');$this->assertScope($r,$actorId);$before=$r;$this->pdo->prepare('UPDATE officer_office_assignment SET effective_to=?,ended_by=?,ended_at=NOW(),updated_by=?,version=version+1 WHERE id=?')->execute([$effectiveTo,$actorId,$actorId,$id]);if((int)$r['is_primary']===1&&$effectiveTo<=date('Y-m-d'))$this->pdo->prepare('UPDATE officer SET primary_office_id=NULL,updated_by=?,version=version+1 WHERE id=? AND primary_office_id=?')->execute([$actorId,$r['officer_id'],$r['office_id']]);$this->event($id,'ENDED',$before,$this->row($id),$reason,$actorId);});
    }

    public function setPrimary(string $id,string $actorId):void
    {
        $this->transaction(function()use($id,$actorId):void{$r=$this->locked($id);$today=date('Y-m-d');if($r['approval_status']!=='APPROVED'||!(int)$r['active']||$r['effective_from']>$today||($r['effective_to']!==null&&$r['effective_to']<$today))throw new DomainException('Only a current approved Office assignment may be primary.');$this->assertScope($r,$actorId);$before=$r;$this->clearCurrentPrimary((string)$r['officer_id'],$id,$actorId);$this->pdo->prepare('UPDATE officer_office_assignment SET is_primary=1,updated_by=?,version=version+1 WHERE id=?')->execute([$actorId,$id]);$this->pdo->prepare('UPDATE officer SET primary_office_id=?,updated_by=?,version=version+1 WHERE id=?')->execute([$r['office_id'],$actorId,$r['officer_id']]);$this->event($id,'SET_PRIMARY',$before,$this->row($id),null,$actorId);});
    }

    /** @return array<string,mixed> */
    public function directEditRecord(string $id,string $actorId):array
    {
        AssignmentDirectEditPolicy::assert('officer.office-assignment.view');
        $s=$this->pdo->prepare("SELECT a.*,f.dad_number officer_dad,f.name_with_initials officer_name,o.dad_number office_dad,o.name_en office_name,ot.name_en office_type,l.name_en location_name FROM officer_office_assignment a JOIN officer f ON f.id=a.officer_id JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id WHERE a.id=? AND a.deleted_at IS NULL");
        $s->execute([$id]);$row=$s->fetch();
        if(!$row)throw new DomainException('Office assignment was not found.');
        $this->assertScope($row,$actorId);
        return $row;
    }

    public function directEdit(string $id,array $data,string $actorId):void
    {
        $context=AssignmentDirectEditPolicy::assert('officer.office-assignment.view');
        $office=trim((string)($data['office_id']??''));$from=$this->validDate($data['effective_from']??null,'Effective From');
        $to=$this->optionalDate($data['effective_to']??null,'Effective To');$reason=trim((string)($data['reason']??''));
        if($office===''||$reason==='')throw new DomainException('Office, Start Date, and Reason are required.');
        if($to!==null&&$to<$from)throw new DomainException('Effective To cannot precede Effective From.');
        if(!ScopeService::canAccessOffice($actorId,$office))throw new DomainException('You cannot select this Office.');
        $this->assertActiveOffice($office);

        $this->transaction(function()use($id,$data,$actorId,$context,$office,$from,$to,$reason):void{
            $before=$this->locked($id);$this->assertScope($before,$actorId);
            $candidate=$before;$candidate['office_id']=$office;$candidate['effective_from']=$from;$candidate['effective_to']=$to;
            $duplicate=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE deleted_at IS NULL AND id<>? AND officer_id=? AND office_id=? AND ((approval_status IN('DRAFT','SUBMITTED','RETURNED')) OR (approval_status='APPROVED' AND active=1)) AND effective_from<=COALESCE(?,'9999-12-31') AND (effective_to IS NULL OR effective_to>=?) FOR UPDATE");
            $duplicate->execute([$id,$before['officer_id'],$office,$to,$from]);
            if((int)$duplicate->fetchColumn()>0)throw new DomainException('This Officer already has an overlapping assignment to the selected Office.');

            $primary=!empty($data['is_primary'])?1:0;$today=date('Y-m-d');
            if($primary===1&&($before['approval_status']!=='APPROVED'||!(int)$before['active']||$from>$today||($to!==null&&$to<$today)))throw new DomainException('Only a current approved Office assignment may be primary.');
            if($primary===1)$this->clearCurrentPrimary((string)$before['officer_id'],$id,$actorId);
            $this->pdo->prepare('UPDATE officer_office_assignment SET office_id=?,effective_from=?,effective_to=?,is_primary=?,reason=?,official_reference=?,remarks=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?')
                ->execute([$office,$from,$to,$primary,$reason,$this->null($data['official_reference']??null),$this->null($data['remarks']??null),$actorId,$id]);
            $after=$this->row($id);
            if($primary===1){
                $this->pdo->prepare('UPDATE officer SET primary_office_id=?,updated_by=?,version=version+1 WHERE id=?')->execute([$office,$actorId,$before['officer_id']]);
            }elseif((int)$before['is_primary']===1){
                $replacement=$this->pdo->prepare("SELECT office_id FROM officer_office_assignment WHERE deleted_at IS NULL AND officer_id=? AND id<>? AND is_primary=1 AND approval_status='APPROVED' AND active=1 AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE()) ORDER BY effective_from DESC,id LIMIT 1");
                $replacement->execute([$before['officer_id'],$id]);$replacementOffice=$replacement->fetchColumn()?:null;
                $this->pdo->prepare('UPDATE officer SET primary_office_id=?,updated_by=?,version=version+1 WHERE id=?')->execute([$replacementOffice,$actorId,$before['officer_id']]);
            }
            $this->directEditEvent($id,$before,$after,$actorId,$context);
        });
    }

    /** @return array<string,mixed> */
    public function deleteRecord(string $id,string $actorId):array
    {
        AssignmentDeletePolicy::assert($actorId);
        $s=$this->pdo->prepare("SELECT a.*,f.dad_number officer_dad,f.name_with_initials officer_name,o.dad_number office_dad,o.name_en office_name,ot.name_en office_type,l.name_en location_name FROM officer_office_assignment a JOIN officer f ON f.id=a.officer_id JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id WHERE a.id=? AND a.deleted_at IS NULL");
        $s->execute([$id]);return $s->fetch()?:throw new DomainException('Office assignment was not found.');
    }

    public function adminDelete(string $id,string $reason,string $actorId):void
    {
        $context=AssignmentDeletePolicy::assert($actorId);$reason=trim($reason);if($reason==='')throw new DomainException('Delete Reason is required.');
        $this->transaction(function()use($id,$reason,$actorId,$context):void{
            $before=$this->locked($id);if($before['deleted_at']!==null)throw new DomainException('Office assignment was not found.');
            $this->pdo->prepare('UPDATE officer_office_assignment SET active=0,is_primary=0,deleted_at=NOW(),deleted_by=?,delete_reason=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND deleted_at IS NULL')->execute([$actorId,$reason,$actorId,$id]);
            if((int)$before['is_primary']===1){
                $replacement=$this->pdo->prepare("SELECT office_id FROM officer_office_assignment WHERE officer_id=? AND id<>? AND deleted_at IS NULL AND is_primary=1 AND approval_status='APPROVED' AND active=1 AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE()) ORDER BY effective_from DESC,id LIMIT 1");
                $replacement->execute([$before['officer_id'],$id]);$office=$replacement->fetchColumn()?:null;
                $this->pdo->prepare('UPDATE officer SET primary_office_id=?,updated_by=?,version=version+1 WHERE id=? AND primary_office_id=?')->execute([$office,$actorId,$before['officer_id'],$before['office_id']]);
            }
            $after=$this->row($id);$payload=$after;$payload['_active_context']=AssignmentDeletePolicy::auditContext($context);
            $this->pdo->prepare('INSERT INTO officer_office_assignment_audit(assignment_id,action_key,previous_state_json,new_state_json,reason,actor_user_id) VALUES(?,?,?,?,?,?)')->execute([$id,'ADMIN_DELETE',json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$reason,$actorId]);
        });
    }

    public function hasCurrentAscOfficeAssignment(string $officerId,string $ascLocationId,string $date):bool
    {
        $s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' WHERE a.deleted_at IS NULL AND a.officer_id=? AND o.linked_location_id=? AND a.active=1 AND a.approval_status='APPROVED' AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>=?) AND o.operational_status='ACTIVE' AND o.approval_status='APPROVED'");$s->execute([$officerId,$ascLocationId,$date,$date]);return (int)$s->fetchColumn()>0;
    }

    public function hasCurrentAscOfficeAssignmentNow(string $officerId,string $ascLocationId):bool
    {
        $s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' WHERE a.deleted_at IS NULL AND a.officer_id=? AND o.linked_location_id=? AND a.active=1 AND a.approval_status='APPROVED' AND a.effective_from<=CURRENT_DATE() AND (a.effective_to IS NULL OR a.effective_to>=CURRENT_DATE()) AND o.operational_status='ACTIVE' AND o.approval_status='APPROVED' AND o.effective_from<=CURRENT_DATE() AND (o.effective_to IS NULL OR o.effective_to>=CURRENT_DATE())");$s->execute([$officerId,$ascLocationId]);return (int)$s->fetchColumn()>0;
    }

    /** @return array{where:array<int,string>,params:array<int,mixed>} */
    public static function approvalQueueAccess(string $actorId,string $alias='a'):array
    {
        if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$alias)!==1)throw new DomainException('Invalid Office Assignment queue alias.');
        $context=Auth::activeContextForUser($actorId);
        if($context===null)return ['where'=>['1=0'],'params'=>[]];
        $role=(string)$context['role_code'];
        $officeIds=array_column(ScopeService::scopedOffices($actorId),'id');
        $officeMarks=implode(',',array_fill(0,count($officeIds),'?'));
        $scopedOffice=$officeIds===[]?'1=0':"{$alias}.office_id IN ({$officeMarks})";
        if($role==='DISTRICT_ADMIN')return ['where'=>["(({$alias}.workflow_origin_role_code IN('DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN') AND {$alias}.workflow_scope_location_id=?) OR {$alias}.workflow_origin_role_code IS NULL)",$scopedOffice],'params'=>array_merge([(string)$context['location_id']],$officeIds)];
        if($role==='NATIONAL_ADMIN')return ['where'=>["({$alias}.workflow_origin_role_code IN('NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN') OR {$alias}.workflow_origin_role_code IS NULL)",$scopedOffice],'params'=>$officeIds];
        if($role==='ASC_ADMIN')return ['where'=>["(({$alias}.workflow_origin_role_code IN('ASC_SUBJECT_OFFICER','ASC_ADMIN') AND {$alias}.workflow_scope_location_id=?) OR {$alias}.workflow_origin_role_code IS NULL)",$scopedOffice],'params'=>array_merge([(string)$context['location_id']],$officeIds)];
        // Preserve the pre-existing enterprise/legacy approver view, which was
        // target-Office scoped and did not depend on a workflow-origin column.
        return ['where'=>[$scopedOffice],'params'=>$officeIds];
    }

    /** @return array<int,array<string,mixed>> */
    private function eligibleOffices(string $actorId):array
    {
        $ids=array_column(ScopeService::scopedOffices($actorId),'id');if($ids===[])return [];$marks=implode(',',array_fill(0,count($ids),'?'));
        $sql="SELECT o.id,o.dad_number,o.name_en,ot.system_key office_type,l.dad_number location_dad,l.name_en location_name,
                     CASE WHEN ot.system_key='DISTRICT_OFFICE' THEN l.name_en WHEN COUNT(DISTINCT district.id)=1 THEN MAX(district.name_en) ELSE NULL END district_name
              FROM office o
              JOIN office_type ot ON ot.id=o.office_type_id
              LEFT JOIN location l ON l.id=o.linked_location_id
              LEFT JOIN location_relationship lr ON lr.child_location_id=l.id AND lr.relationship_type='DISTRICT_ASC' AND lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=CURRENT_DATE() AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())
              LEFT JOIN location district ON district.id=lr.parent_location_id
              WHERE o.id IN ({$marks}) AND o.approval_status='APPROVED' AND o.operational_status='ACTIVE'
              GROUP BY o.id,o.dad_number,o.name_en,ot.system_key,l.dad_number,l.name_en,ot.display_order
              ORDER BY ot.display_order,o.name_en";
        $s=$this->pdo->prepare($sql);$s->execute($ids);return $s->fetchAll();
    }

    /** @return array{role_code:?string,scope_location_id:?string} */
    private function creationWorkflowContext(string $actorId):array
    {
        if(!Auth::isCurrentUser($actorId))throw new DomainException('The current user does not match the Office Assignment actor.');
        $context=Auth::activeContextForUser($actorId);if($context===null)throw new DomainException('Select an Active Working Context.');$role=(string)$context['role_code'];
        if(in_array($role,['DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN'],true)){
            if((string)$context['role_level']!=='DISTRICT'||(string)$context['scope_type']!=='DISTRICT'||(string)$context['scope_mode']!=='INCLUDE_CHILDREN'||empty($context['location_id']))throw new DomainException('A current District working context is required.');
            return ['role_code'=>$role,'scope_location_id'=>(string)$context['location_id']];
        }
        if(in_array($role,['NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN'],true)){
            if((string)$context['role_level']!=='NATIONAL'||(string)$context['scope_type']!=='NATIONAL'||(string)$context['scope_mode']!=='NATIONAL')throw new DomainException('A current National working context is required.');
            return ['role_code'=>$role,'scope_location_id'=>null];
        }
        if(in_array($role,['ASC_SUBJECT_OFFICER','ASC_ADMIN'],true)){
            if((string)$context['role_level']!=='ASC'||(string)$context['scope_type']!=='ASC'||(string)$context['scope_mode']!=='EXACT'||empty($context['location_id']))throw new DomainException('A current ASC working context is required.');
            return ['role_code'=>$role,'scope_location_id'=>(string)$context['location_id']];
        }
        return ['role_code'=>null,'scope_location_id'=>null];
    }

    private function assertCreationPermission(string $actorId):void
    {
        if(!Auth::isCurrentUser($actorId)||!Auth::can('officer.office-assignment.create'))throw new DomainException('You are not authorized to assign an Office.');
    }

    private function assertCreationContext(array $row,string $actorId):void
    {
        $context=$this->creationWorkflowContext($actorId);$origin=(string)($row['workflow_origin_role_code']??'');
        if($origin!==''){
            if($origin!==(string)($context['role_code']??''))throw new DomainException('This Office assignment must be corrected in its original working context.');
            if((string)($row['workflow_scope_location_id']??'')!==(string)($context['scope_location_id']??''))throw new DomainException('This Office assignment is outside the original working scope.');
        }
        $this->assertScope($row,$actorId);
    }

    private function assertApprovalContext(array $row,string $actorId):void
    {
        $this->assertApprovalPermission($actorId);$context=Auth::activeContextForUser($actorId)??throw new DomainException('Select an Active Working Context.');$origin=(string)($row['workflow_origin_role_code']??'');
        if((string)$context['role_code']==='SYSTEM_ADMIN'){$this->assertScope($row,$actorId);return;}
        $checker=['DISTRICT_SUBJECT_OFFICER'=>'DISTRICT_ADMIN','DISTRICT_ADMIN'=>'DISTRICT_ADMIN','NATIONAL_SUBJECT_OFFICER'=>'NATIONAL_ADMIN','NATIONAL_ADMIN'=>'NATIONAL_ADMIN','ASC_SUBJECT_OFFICER'=>'ASC_ADMIN','ASC_ADMIN'=>'ASC_ADMIN'][$origin]??null;
        if($checker!==null){
            if((string)$context['role_code']!==$checker)throw new DomainException('This Office assignment requires approval at its original governance level.');
            if(str_starts_with($checker,'DISTRICT_')&&(string)($context['location_id']??'')!==(string)($row['workflow_scope_location_id']??''))throw new DomainException('This Office assignment belongs to another District.');
            if(str_starts_with($checker,'ASC_')&&(string)($context['location_id']??'')!==(string)($row['workflow_scope_location_id']??''))throw new DomainException('This Office assignment belongs to another Agrarian Service Center.');
            if(str_starts_with($checker,'NATIONAL_')&&((string)$context['role_level']!=='NATIONAL'||(string)$context['scope_mode']!=='NATIONAL'))throw new DomainException('A current National working context is required.');
        }
        $this->assertScope($row,$actorId);
    }

    private function reviewDecision(string $id,string $reason,string $actorId,string $status):void
    {
        $reason=trim($reason);if($reason==='')throw new DomainException(($status==='RETURNED'?'Return':'Rejection').' reason is required.');
        $this->transaction(function()use($id,$reason,$actorId,$status):void{
            $row=$this->locked($id);if($this->isUserAccountRequestInitial($row))throw new DomainException('This initial Office assignment is controlled by its User Account Request.');if((string)$row['approval_status']!=='SUBMITTED')throw new DomainException('Only a submitted Office assignment can be reviewed.');if((string)$row['created_by']===$actorId||(string)$row['submitted_by']===$actorId)throw new DomainException('Maker-checker policy prevents self-review.');$this->assertApprovalContext($row,$actorId);$before=$row;
            $u=$this->pdo->prepare("UPDATE officer_office_assignment SET approval_status=?,active=0,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND approval_status='SUBMITTED'");$u->execute([$status,$actorId,$id]);if($u->rowCount()!==1)throw new DomainException('The Office assignment changed while it was being reviewed.');$this->event($id,$status,$before,$this->row($id),$reason,$actorId);
            $notice=new WorkflowNotificationService($this->pdo);$notice->completeStage('OFFICER_OFFICE_ASSIGNMENT',$id,'APPROVAL',$actorId,$status==='RETURNED'?'Returned for correction':'Rejected');
            if(!empty($row['created_by'])){if($status==='RETURNED')$notice->actionForUser((string)$row['created_by'],'OFFICER','Office Assignment Returned','Your Officer Office Assignment was returned for correction.','OFFICER_OFFICE_ASSIGNMENT',$id,'CORRECTION','/hr/officers/'.$row['officer_id'].'/offices/assign',$actorId);else $notice->information((string)$row['created_by'],'OFFICER','Office Assignment Rejected','Your Officer Office Assignment was rejected.','OFFICER_OFFICE_ASSIGNMENT',$id,'/hr/officers/'.$row['officer_id'],$actorId);}
        });
    }

    private function pendingForOfficer(string $officerId):?array{$s=$this->pdo->prepare("SELECT a.*,o.dad_number office_dad,o.name_en office_name,u.display_name submitted_by_name,u.username submitted_by_username FROM officer_office_assignment a JOIN office o ON o.id=a.office_id LEFT JOIN system_user u ON u.id=a.submitted_by WHERE a.deleted_at IS NULL AND a.officer_id=? AND a.approval_status='SUBMITTED' AND a.reason<>? ORDER BY a.submitted_at DESC,a.id DESC LIMIT 1");$s->execute([$officerId,self::USER_ACCOUNT_REQUEST_INITIAL_REASON]);return $s->fetch()?:null;}
    private function hasPendingAssignment(string $officerId,?string $except=null):bool{$s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE deleted_at IS NULL AND officer_id=? AND approval_status='SUBMITTED' AND reason<>? AND id<>COALESCE(?, '')");$s->execute([$officerId,self::USER_ACCOUNT_REQUEST_INITIAL_REASON,$except]);return (int)$s->fetchColumn()>0;}
    /** An approved assignment that is current or scheduled, but not already ended. */
    private function hasOpenApprovedAssignment(string $officerId,bool $lock=false):bool{$sql="SELECT COUNT(*) FROM officer_office_assignment WHERE deleted_at IS NULL AND officer_id=? AND active=1 AND approval_status='APPROVED' AND (effective_to IS NULL OR effective_to>=CURRENT_DATE())".($lock?' FOR UPDATE':'');$s=$this->pdo->prepare($sql);$s->execute([$officerId]);return (int)$s->fetchColumn()>0;}
    /** @param array{role_code:?string,scope_location_id:?string} $workflow */
    private function isSubjectOfficerWorkflow(array $workflow):bool{return in_array((string)($workflow['role_code']??''),['DISTRICT_SUBJECT_OFFICER','NATIONAL_SUBJECT_OFFICER'],true);}
    private function isSubjectOfficerRow(array $row):bool{return in_array((string)($row['workflow_origin_role_code']??''),['DISTRICT_SUBJECT_OFFICER','NATIONAL_SUBJECT_OFFICER'],true);}

    private function assertNoOverlap(array $r):void{$s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE deleted_at IS NULL AND id<>? AND officer_id=? AND office_id=? AND active=1 AND approval_status='APPROVED' AND effective_from<=COALESCE(?, '9999-12-31') AND (effective_to IS NULL OR effective_to>=?) FOR UPDATE");$s->execute([$r['id'],$r['officer_id'],$r['office_id'],$r['effective_to'],$r['effective_from']]);if((int)$s->fetchColumn()>0)throw new DomainException('This Officer already has an overlapping approved assignment to the selected Office.');}
    private function hasCurrentPrimary(string $officerId,string $except):bool{$s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE deleted_at IS NULL AND officer_id=? AND id<>? AND is_primary=1 AND approval_status='APPROVED' AND active=1 AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE()) FOR UPDATE");$s->execute([$officerId,$except]);return (int)$s->fetchColumn()>0;}
    private function lockedInitialForOfficer(string $officerId):?array{$rows=$this->initialRows($officerId,true);if(count($rows)>1)throw new DomainException('The Officer has multiple initial Office assignments and requires review.');return $rows[0]??null;}
    private function initialRows(string $officerId,bool $lock):array{$sql="SELECT a.*,o.dad_number office_dad,o.name_en office_name,ot.name_en office_type,l.name_en location_name FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id WHERE a.deleted_at IS NULL AND a.officer_id=? AND a.reason=? ORDER BY a.created_at,a.id".($lock?' FOR UPDATE':'');$s=$this->pdo->prepare($sql);$s->execute([$officerId,self::INITIAL_OFFICER_REASON]);return $s->fetchAll();}
    private function clearCurrentPrimary(string $officerId,string $except,string $actorId):void{$s=$this->pdo->prepare("SELECT id FROM officer_office_assignment WHERE deleted_at IS NULL AND officer_id=? AND id<>? AND is_primary=1 AND approval_status='APPROVED' AND active=1 AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE()) FOR UPDATE");$s->execute([$officerId,$except]);foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id){$before=$this->row($id);$this->pdo->prepare('UPDATE officer_office_assignment SET is_primary=0,updated_by=?,version=version+1 WHERE id=?')->execute([$actorId,$id]);$this->event($id,'PRIMARY_REPLACED',$before,$this->row($id),null,$actorId);}}
    private function assertScope(array $r,string $actor):void{if(!ScopeService::canAccessOffice($actor,(string)$r['office_id']))throw new DomainException('You cannot manage this Office assignment.');}
    private function assertApprovalPermission(string $actor):void
    {
        if(!Auth::isCurrentUser($actor)||Auth::activeContextForUser($actor)===null||!Auth::can('officer.office-assignment.approve'))throw new DomainException('You are not authorized to approve Office assignments.');
    }
    private function notifySubmitted(array $row,string $actorId):void
    {
        if($this->isUserAccountRequestInitial($row))return;
        $origin=(string)($row['workflow_origin_role_code']??'');$roles=[];$location=$row['workflow_scope_location_id']?:null;
        if(in_array($origin,['DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN'],true))$roles=['DISTRICT_ADMIN'];
        elseif(in_array($origin,['NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN'],true)){$roles=['NATIONAL_ADMIN'];$location=null;}
        elseif(in_array($origin,['ASC_SUBJECT_OFFICER','ASC_ADMIN'],true))$roles=['ASC_ADMIN'];
        else{$s=$this->pdo->prepare('SELECT linked_location_id FROM office WHERE id=?');$s->execute([$row['office_id']]);$location=$s->fetchColumn()?:null;}
        (new WorkflowNotificationService($this->pdo))->actionForPermission('officer.office-assignment.approve',$location,'OFFICER','Officer Office Assignment Awaiting Approval','Officer Office Assignment awaiting approval.','OFFICER_OFFICE_ASSIGNMENT',(string)$row['id'],'APPROVAL','/hr/officers/office-assignments/'.$row['id'].'/review',$actorId,$roles);
    }
    private function approveLocked(array $r,string $actorId,bool $notify):void
    {
        if($r['approval_status']!=='SUBMITTED')throw new DomainException('Only a submitted Office assignment may be approved.');if($r['created_by']===$actorId||$r['submitted_by']===$actorId)throw new DomainException('Maker-checker policy prevents self-approval.');if($notify)$this->assertApprovalContext($r,$actorId);else{$this->assertApprovalPermission($actorId);$this->assertScope($r,$actorId);}$lock=$this->pdo->prepare('SELECT id FROM officer WHERE id=? FOR UPDATE');$lock->execute([$r['officer_id']]);$lock=$this->pdo->prepare('SELECT id FROM office WHERE id=? FOR UPDATE');$lock->execute([$r['office_id']]);$this->assertActiveOffice((string)$r['office_id']);if($this->isSubjectOfficerRow($r)&&$this->hasOpenApprovedAssignment((string)$r['officer_id'],true))throw new DomainException('This Officer received another approved current or future Office assignment after this request was submitted. Review the Office history before approving.');$this->assertNoOverlap($r);$before=$r;$id=(string)$r['id'];
        $today=date('Y-m-d');$isCurrent=$r['effective_from']<=$today&&($r['effective_to']===null||$r['effective_to']>=$today);$makePrimary=$isCurrent&&((int)$r['is_primary']===1||!$this->hasCurrentPrimary((string)$r['officer_id'],$id));if($makePrimary)$this->clearCurrentPrimary((string)$r['officer_id'],$id,$actorId);
        $this->pdo->prepare("UPDATE officer_office_assignment SET approval_status='APPROVED',active=1,is_primary=?,approved_by=?,approved_at=NOW(),updated_by=?,version=version+1 WHERE id=?")->execute([$makePrimary?1:0,$actorId,$actorId,$id]);if($makePrimary)$this->pdo->prepare('UPDATE officer SET primary_office_id=?,updated_by=?,version=version+1 WHERE id=?')->execute([$r['office_id'],$actorId,$r['officer_id']]);$this->event($id,'APPROVED',$before,$this->row($id),null,$actorId);
        if($notify){$notice=new WorkflowNotificationService($this->pdo);$notice->completeStage('OFFICER_OFFICE_ASSIGNMENT',$id,'APPROVAL',$actorId,'Office assignment approved');if(!empty($r['created_by']))$notice->information((string)$r['created_by'],'OFFICER','Office Assignment Approved','Your Officer Office Assignment has been approved.','OFFICER_OFFICE_ASSIGNMENT',$id,'/hr/officers/'.$r['officer_id'],$actorId);}
    }
    private function isUserAccountRequestInitial(array $row):bool{return (string)($row['reason']??'')===self::USER_ACCOUNT_REQUEST_INITIAL_REASON;}
    private function assertActiveOffice(string $id):void{$s=$this->pdo->prepare("SELECT COUNT(*) FROM office WHERE id=? AND approval_status='APPROVED' AND operational_status='ACTIVE'");$s->execute([$id]);if((int)$s->fetchColumn()!==1)throw new DomainException('The selected Office is not approved and active.');}
    private function assertEntity(string $table,string $id):void{$s=$this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id=?");$s->execute([$id]);if((int)$s->fetchColumn()!==1)throw new DomainException('Officer was not found.');}
    private function locked(string $id):array{$s=$this->pdo->prepare('SELECT * FROM officer_office_assignment WHERE id=? FOR UPDATE');$s->execute([$id]);$r=$s->fetch();if(!$r)throw new DomainException('Office assignment was not found.');return $r;}
    private function row(string $id):array{$s=$this->pdo->prepare('SELECT * FROM officer_office_assignment WHERE id=?');$s->execute([$id]);return $s->fetch()?:[];}
    private function event(string $id,string $action,?array $before,array $after,?string $reason,string $actor):void{$this->pdo->prepare('INSERT INTO officer_office_assignment_audit(assignment_id,action_key,previous_state_json,new_state_json,reason,actor_user_id) VALUES(?,?,?,?,?,?)')->execute([$id,$action,$before?json_encode($before,JSON_UNESCAPED_UNICODE):null,json_encode($after,JSON_UNESCAPED_UNICODE),$reason,$actor]);}
    private function directEditEvent(string $id,array $before,array $after,string $actor,array $context):void
    {
        $changed=[];foreach($after as $field=>$value){if(array_key_exists($field,$before)&&$before[$field]!==$value)$changed[$field]=['before'=>$before[$field],'after'=>$value];}
        $payload=['assignment'=>$after,'changed_fields'=>$changed,'active_context'=>AssignmentDirectEditPolicy::auditContext($context)];
        $this->pdo->prepare('INSERT INTO officer_office_assignment_audit(assignment_id,action_key,previous_state_json,new_state_json,reason,actor_user_id) VALUES(?,?,?,?,?,?)')->execute([$id,'DIRECT_EDIT',json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'Head Office direct edit',$actor]);
    }
    private function validDate(mixed $value,string $label):string{$value=trim((string)$value);$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new DomainException($label.' must be a valid date.');return $value;}
    private function optionalDate(mixed $value,string $label):?string{$value=trim((string)$value);return $value===''?null:$this->validDate($value,$label);}
    private function null(mixed $v):?string{$v=trim((string)$v);return $v===''?null:$v;}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function transaction(callable $fn):mixed{$own=!$this->pdo->inTransaction();if($own)$this->pdo->beginTransaction();try{$r=$fn();if($own)$this->pdo->commit();return $r;}catch(Throwable $e){if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
}
