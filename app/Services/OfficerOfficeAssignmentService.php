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
            $existingId=(string)($existing['id']??'');$dup=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE id<>? AND officer_id=? AND office_id=? AND ((approval_status IN('DRAFT','SUBMITTED','RETURNED')) OR (approval_status='APPROVED' AND active=1)) AND (effective_to IS NULL OR effective_to>=?)");$dup->execute([$existingId,$officerId,$officeId,$effectiveFrom]);
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
        $assignment=$this->initialForOfficer($officerId);if(!$assignment)return;$this->approve((string)$assignment['id'],$actorId);
    }

    public function create(array $data,string $actorId):string
    {
        $officer=trim((string)($data['officer_id']??''));$office=trim((string)($data['office_id']??''));
        $from=trim((string)($data['effective_from']??''));$reason=trim((string)($data['reason']??''));
        if($officer===''||$office===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)||$reason==='')throw new DomainException('Officer, Office, Start Date, and Reason are required.');
        if(!ScopeService::canAccessOffice($actorId,$office))throw new DomainException('You cannot select this Office.');
        $this->assertEntity('officer',$officer);$this->assertActiveOffice($office);
        return $this->transaction(function()use($data,$actorId,$officer,$office,$from,$reason):string{
            $dup=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id=? AND office_id=? AND ((approval_status IN('DRAFT','SUBMITTED','RETURNED')) OR (approval_status='APPROVED' AND active=1)) AND effective_from=?");$dup->execute([$officer,$office,$from]);
            if((int)$dup->fetchColumn()>0)throw new DomainException('An Office assignment already exists for this Officer, Office and effective date.');
            $id=$this->uuid();$primary=!empty($data['is_primary'])?1:0;
            if($primary===1&&$from>date('Y-m-d'))throw new DomainException('A future Office assignment can be set as Primary when it becomes effective.');
            $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,is_primary,active,reason,official_reference,remarks,approval_status,created_by,submitted_by,submitted_at) VALUES(?,?,?,?,?,0,?,?,?,'SUBMITTED',?,?,NOW())")
                ->execute([$id,$officer,$office,$from,$primary,$reason,$this->null($data['official_reference']??null),$this->null($data['remarks']??null),$actorId,$actorId]);
            $this->event($id,'SUBMITTED',null,$this->row($id),$reason,$actorId);$this->notifySubmitted($this->row($id),$actorId);return $id;
        });
    }

    public function submit(string $id,string $actorId):void
    {
        $this->transaction(function()use($id,$actorId):void{$r=$this->locked($id);if($r['approval_status']!=='DRAFT'&&$r['approval_status']!=='RETURNED')throw new DomainException('Only a draft or returned Office assignment may be submitted.');if($r['created_by']!==$actorId)throw new DomainException('Only the creator may submit this Office assignment.');$this->assertScope($r,$actorId);$before=$r;$this->pdo->prepare("UPDATE officer_office_assignment SET approval_status='SUBMITTED',active=0,submitted_by=?,submitted_at=NOW(),updated_by=?,version=version+1 WHERE id=?")->execute([$actorId,$actorId,$id]);$this->event($id,'SUBMITTED',$before,$this->row($id),null,$actorId);$this->notifySubmitted($this->row($id),$actorId);});
    }

    public function approve(string $id,string $actorId):void
    {
        $this->transaction(function()use($id,$actorId):void{$r=$this->locked($id);if($r['approval_status']!=='SUBMITTED')throw new DomainException('Only a submitted Office assignment may be approved.');$this->assertApprovalPermission($actorId);if($r['created_by']===$actorId||$r['submitted_by']===$actorId)throw new DomainException('Maker-checker policy prevents self-approval.');$this->assertScope($r,$actorId);$lock=$this->pdo->prepare('SELECT id FROM officer WHERE id=? FOR UPDATE');$lock->execute([$r['officer_id']]);$lock=$this->pdo->prepare('SELECT id FROM office WHERE id=? FOR UPDATE');$lock->execute([$r['office_id']]);$this->assertNoOverlap($r);$before=$r;
            $today=date('Y-m-d');$isCurrent=$r['effective_from']<=$today&&($r['effective_to']===null||$r['effective_to']>=$today);$makePrimary=$isCurrent&&((int)$r['is_primary']===1||!$this->hasCurrentPrimary((string)$r['officer_id'],$id));
            if($makePrimary)$this->clearCurrentPrimary((string)$r['officer_id'],$id,$actorId);
            $this->pdo->prepare("UPDATE officer_office_assignment SET approval_status='APPROVED',active=1,is_primary=?,approved_by=?,approved_at=NOW(),updated_by=?,version=version+1 WHERE id=?")->execute([$makePrimary?1:0,$actorId,$actorId,$id]);
            if($makePrimary)$this->pdo->prepare('UPDATE officer SET primary_office_id=?,updated_by=?,version=version+1 WHERE id=?')->execute([$r['office_id'],$actorId,$r['officer_id']]);
            $this->event($id,'APPROVED',$before,$this->row($id),null,$actorId);
            $notice=new WorkflowNotificationService($this->pdo);$notice->completeStage('OFFICER_OFFICE_ASSIGNMENT',$id,'APPROVAL',$actorId,'Office assignment approved');if(!empty($r['created_by']))$notice->information((string)$r['created_by'],'OFFICER','Office Assignment Approved','Your Officer Office Assignment has been approved.','OFFICER_OFFICE_ASSIGNMENT',$id,'/hr/officers/'.$r['officer_id'],$actorId);
        });
    }

    /** Limited review model authorized by the proposed target Office, not by current Officer-directory membership. */
    public function reviewForApproval(string $id,string $actorId):array
    {
        $this->assertApprovalPermission($actorId);
        $s=$this->pdo->prepare("SELECT a.*,f.dad_number officer_dad,f.name_with_initials officer_name,f.nic,d.name_en designation_name,o.dad_number office_dad,o.name_en office_name,ot.name_en office_type,l.dad_number location_dad,l.name_en location_name,su.display_name submitted_by_name,su.username submitted_by_username FROM officer_office_assignment a JOIN officer f ON f.id=a.officer_id LEFT JOIN designation d ON d.id=f.primary_designation_id JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id LEFT JOIN system_user su ON su.id=a.submitted_by WHERE a.id=? AND a.approval_status='SUBMITTED'");
        $s->execute([$id]);$row=$s->fetch();if(!$row)throw new DomainException('The submitted Office assignment was not found.');if($row['created_by']===$actorId||$row['submitted_by']===$actorId)throw new DomainException('Maker-checker policy prevents self-approval.');$this->assertScope($row,$actorId);
        $allowed=array_column(ScopeService::scopedOffices($actorId),'id');$where=$allowed===[]?'1=0':'a.office_id IN ('.implode(',',array_fill(0,count($allowed),'?')).')';
        $current=$this->pdo->prepare("SELECT o.dad_number office_dad,o.name_en office_name,ot.name_en office_type,l.name_en location_name,a.effective_from,a.is_primary FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id WHERE a.officer_id=? AND a.approval_status='APPROVED' AND a.active=1 AND a.effective_from<=CURRENT_DATE() AND (a.effective_to IS NULL OR a.effective_to>=CURRENT_DATE()) AND {$where} ORDER BY a.is_primary DESC,o.name_en");
        $current->execute(array_merge([(string)$row['officer_id']],$allowed));$row['current_offices']=$current->fetchAll();return $row;
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
        $s=$this->pdo->prepare("SELECT a.*,f.dad_number officer_dad,f.name_with_initials officer_name,o.dad_number office_dad,o.name_en office_name,ot.name_en office_type,l.name_en location_name FROM officer_office_assignment a JOIN officer f ON f.id=a.officer_id JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id WHERE a.id=?");
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
            $duplicate=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE id<>? AND officer_id=? AND office_id=? AND ((approval_status IN('DRAFT','SUBMITTED','RETURNED')) OR (approval_status='APPROVED' AND active=1)) AND effective_from<=COALESCE(?,'9999-12-31') AND (effective_to IS NULL OR effective_to>=?) FOR UPDATE");
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
                $replacement=$this->pdo->prepare("SELECT office_id FROM officer_office_assignment WHERE officer_id=? AND id<>? AND is_primary=1 AND approval_status='APPROVED' AND active=1 AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE()) ORDER BY effective_from DESC,id LIMIT 1");
                $replacement->execute([$before['officer_id'],$id]);$replacementOffice=$replacement->fetchColumn()?:null;
                $this->pdo->prepare('UPDATE officer SET primary_office_id=?,updated_by=?,version=version+1 WHERE id=?')->execute([$replacementOffice,$actorId,$before['officer_id']]);
            }
            $this->directEditEvent($id,$before,$after,$actorId,$context);
        });
    }

    public function hasCurrentAscOfficeAssignment(string $officerId,string $ascLocationId,string $date):bool
    {
        $s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' WHERE a.officer_id=? AND o.linked_location_id=? AND a.active=1 AND a.approval_status='APPROVED' AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>=?) AND o.operational_status='ACTIVE' AND o.approval_status='APPROVED'");$s->execute([$officerId,$ascLocationId,$date,$date]);return (int)$s->fetchColumn()>0;
    }

    public function hasCurrentAscOfficeAssignmentNow(string $officerId,string $ascLocationId):bool
    {
        $s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' WHERE a.officer_id=? AND o.linked_location_id=? AND a.active=1 AND a.approval_status='APPROVED' AND a.effective_from<=CURRENT_DATE() AND (a.effective_to IS NULL OR a.effective_to>=CURRENT_DATE()) AND o.operational_status='ACTIVE' AND o.approval_status='APPROVED' AND o.effective_from<=CURRENT_DATE() AND (o.effective_to IS NULL OR o.effective_to>=CURRENT_DATE())");$s->execute([$officerId,$ascLocationId]);return (int)$s->fetchColumn()>0;
    }

    private function assertNoOverlap(array $r):void{$s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE id<>? AND officer_id=? AND office_id=? AND active=1 AND approval_status='APPROVED' AND effective_from<=COALESCE(?, '9999-12-31') AND (effective_to IS NULL OR effective_to>=?) FOR UPDATE");$s->execute([$r['id'],$r['officer_id'],$r['office_id'],$r['effective_to'],$r['effective_from']]);if((int)$s->fetchColumn()>0)throw new DomainException('This Officer already has an overlapping approved assignment to the selected Office.');}
    private function hasCurrentPrimary(string $officerId,string $except):bool{$s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id=? AND id<>? AND is_primary=1 AND approval_status='APPROVED' AND active=1 AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE()) FOR UPDATE");$s->execute([$officerId,$except]);return (int)$s->fetchColumn()>0;}
    private function lockedInitialForOfficer(string $officerId):?array{$rows=$this->initialRows($officerId,true);if(count($rows)>1)throw new DomainException('The Officer has multiple initial Office assignments and requires review.');return $rows[0]??null;}
    private function initialRows(string $officerId,bool $lock):array{$sql="SELECT a.*,o.dad_number office_dad,o.name_en office_name,ot.name_en office_type,l.name_en location_name FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id WHERE a.officer_id=? AND a.reason=? ORDER BY a.created_at,a.id".($lock?' FOR UPDATE':'');$s=$this->pdo->prepare($sql);$s->execute([$officerId,self::INITIAL_OFFICER_REASON]);return $s->fetchAll();}
    private function clearCurrentPrimary(string $officerId,string $except,string $actorId):void{$s=$this->pdo->prepare("SELECT id FROM officer_office_assignment WHERE officer_id=? AND id<>? AND is_primary=1 AND approval_status='APPROVED' AND active=1 AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE()) FOR UPDATE");$s->execute([$officerId,$except]);foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id){$before=$this->row($id);$this->pdo->prepare('UPDATE officer_office_assignment SET is_primary=0,updated_by=?,version=version+1 WHERE id=?')->execute([$actorId,$id]);$this->event($id,'PRIMARY_REPLACED',$before,$this->row($id),null,$actorId);}}
    private function assertScope(array $r,string $actor):void{if(!ScopeService::canAccessOffice($actor,(string)$r['office_id']))throw new DomainException('You cannot manage this Office assignment.');}
    private function assertApprovalPermission(string $actor):void
    {
        if(!Auth::isCurrentUser($actor)||Auth::activeContextForUser($actor)===null||!Auth::can('officer.office-assignment.approve'))throw new DomainException('You are not authorized to approve Office assignments.');
    }
    private function notifySubmitted(array $row,string $actorId):void
    {
        $s=$this->pdo->prepare('SELECT linked_location_id FROM office WHERE id=?');$s->execute([$row['office_id']]);$location=$s->fetchColumn()?:null;
        (new WorkflowNotificationService($this->pdo))->actionForPermission('officer.office-assignment.approve',$location,'OFFICER','Office Assignment Awaiting Approval','An Officer Office Assignment is ready for review.','OFFICER_OFFICE_ASSIGNMENT',(string)$row['id'],'APPROVAL','/hr/officers/office-assignments/'.$row['id'].'/review',$actorId);
    }
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
