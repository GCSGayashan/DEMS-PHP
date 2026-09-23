<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Audit;
use DomainException;
use PDO;

final class NotificationService
{
    private const TERMINAL=['COMPLETED','RESOLVED_BY_OTHER','CANCELLED','EXPIRED'];
    public function __construct(private readonly PDO $pdo){}

    public function createActionRequired(string $recipient,string $module,string $title,string $message,string $entityType,string $entityId,string $stage,string $actionUrl,string $actionLabel='Review',string $priority='HIGH',?string $createdBy=null,?string $roleAssignmentId=null,?string $scopeAssignmentId=null):string
    {
        return $this->create($recipient,'ACTION_REQUIRED',$module,$title,$message,$entityType,$entityId,$stage,$actionUrl,$actionLabel,$priority,$createdBy,$roleAssignmentId,$scopeAssignmentId);
    }
    public function createInformation(string $recipient,string $module,string $title,string $message,?string $entityType=null,?string $entityId=null,?string $actionUrl=null,?string $createdBy=null):string
    {
        return $this->create($recipient,'INFORMATION',$module,$title,$message,$entityType,$entityId,null,$actionUrl,$actionUrl?'View':null,'NORMAL',$createdBy,null,null,'COMPLETED');
    }
    public function createWarning(string $recipient,string $module,string $title,string $message,?string $entityType=null,?string $entityId=null,?string $actionUrl=null,string $priority='HIGH',?string $createdBy=null):string
    {
        return $this->create($recipient,'WARNING',$module,$title,$message,$entityType,$entityId,null,$actionUrl,$actionUrl?'View':null,$priority,$createdBy,null,null,'COMPLETED');
    }
    private function create(string $recipient,string $type,string $module,string $title,string $message,?string $entityType,?string $entityId,?string $stage,?string $url,?string $label,string $priority,?string $createdBy,?string $roleId,?string $scopeId,string $status='PENDING'):string
    {
        if($url!==null&&(!str_starts_with($url,'/')||str_starts_with($url,'//')))throw new DomainException('Notification actions must use an internal application path.');
        $dedupe=implode(':',[$module,$entityType??'NONE',$entityId??'NONE',$stage??$type,$recipient]);
        $existing=$this->pdo->prepare("SELECT id FROM system_notification WHERE recipient_user_id=? AND dedupe_key=? AND action_status='PENDING' LIMIT 1");$existing->execute([$recipient,$dedupe]);$id=$existing->fetchColumn();if($id!==false)return (string)$id;
        $id=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();
        $s=$this->pdo->prepare("INSERT INTO system_notification(id,recipient_user_id,notification_type,module_code,title,message,entity_type,entity_id,workflow_stage,action_url,action_label,priority,action_status,completed_at,dedupe_key,required_role_assignment_id,required_scope_assignment_id,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,IF(?='COMPLETED',NOW(),NULL),?,?,?,?)");
        $s->execute([$id,$recipient,$type,$module,$title,$message,$entityType,$entityId,$stage,$url,$label,$priority,$status,$status,$dedupe,$roleId,$scopeId,$createdBy]);
        Audit::record('notification.created','SYSTEM_NOTIFICATION',$id,['recipient_user_id'=>$recipient,'notification_type'=>$type,'entity_type'=>$entityType,'entity_id'=>$entityId,'workflow_stage'=>$stage]);
        return $id;
    }
    public function markRead(string $id,string $userId):void
    {
        $s=$this->pdo->prepare('UPDATE system_notification SET read_at=COALESCE(read_at,NOW()),updated_at=NOW() WHERE id=? AND recipient_user_id=?');$s->execute([$id,$userId]);if($s->rowCount()!==1){$q=$this->pdo->prepare('SELECT COUNT(*) FROM system_notification WHERE id=? AND recipient_user_id=?');$q->execute([$id,$userId]);if((int)$q->fetchColumn()!==1)throw new DomainException('Notification was not found.');}
    }
    public function forUser(string $id,string $userId):array
    {
        $s=$this->pdo->prepare('SELECT * FROM system_notification WHERE id=? AND recipient_user_id=?');$s->execute([$id,$userId]);$r=$s->fetch();if(!$r)throw new DomainException('Notification was not found.');return $r;
    }
    public function pendingCount(string $userId):int{$s=$this->pdo->prepare("SELECT COUNT(*) FROM system_notification WHERE recipient_user_id=? AND notification_type='ACTION_REQUIRED' AND action_status='PENDING'");$s->execute([$userId]);return (int)$s->fetchColumn();}
    public function recent(string $userId,int $limit=8):array
    {
        $limit=max(1,min(20,$limit));$context=self::contextQueryParts('n');
        $s=$this->pdo->prepare("SELECT n.*,".implode(',',$context['select'])." FROM system_notification n {$context['joins']} WHERE n.recipient_user_id=? AND ((n.notification_type='ACTION_REQUIRED' AND n.action_status='PENDING') OR (n.notification_type IN('INFORMATION','WARNING') AND n.read_at IS NULL)) ORDER BY (n.notification_type='ACTION_REQUIRED' AND n.action_status='PENDING') DESC,FIELD(n.priority,'URGENT','HIGH','NORMAL'),n.created_at DESC LIMIT {$limit}");
        $s->execute([$userId]);return $s->fetchAll();
    }

    /**
     * Shared set-based Officer/Office projection for notification collections.
     * Transaction-specific Office evidence always precedes current Officer data.
     *
     * @return array{joins:string,select:array<int,string>,searchable:array<int,string>}
     */
    public static function contextQueryParts(string $notificationAlias='n'):array
    {
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$notificationAlias))throw new DomainException('Invalid notification SQL alias.');
        $n=$notificationAlias;
        $officerId="COALESCE(ctx_officer.id,ctx_edit_officer.id,ctx_assignment_officer.id,ctx_division_officer.id,ctx_subject_officer.id,ctx_user_officer.id,ctx_role_officer.id,ctx_scope_officer.id)";
        $officerDad="COALESCE(ctx_officer.dad_number,ctx_edit_officer.dad_number,ctx_assignment_officer.dad_number,ctx_division_officer.dad_number,ctx_subject_officer.dad_number,ctx_user_officer.dad_number,ctx_role_officer.dad_number,ctx_scope_officer.dad_number)";
        $officerName="COALESCE(ctx_officer.name_with_initials,ctx_edit_officer.name_with_initials,ctx_assignment_officer.name_with_initials,ctx_division_officer.name_with_initials,ctx_subject_officer.name_with_initials,ctx_user_officer.name_with_initials,ctx_role_officer.name_with_initials,ctx_scope_officer.name_with_initials)";
        $officeDad="CASE {$n}.entity_type
            WHEN 'OFFICER' THEN CASE WHEN COALESCE(ctx_officer_initial.office_count,0)>0 THEN ctx_officer_initial_office.dad_number ELSE ctx_primary_office.dad_number END
            WHEN 'OFFICER_EDIT_REQUEST' THEN ctx_primary_office.dad_number
            WHEN 'OFFICER_OFFICE_ASSIGNMENT' THEN ctx_assignment_office.dad_number
            WHEN 'ARPA_DIVISION_REQUEST' THEN ctx_division_office.dad_number
            WHEN 'ARPA_SUBJECT_REQUEST' THEN ctx_subject_office.dad_number
            WHEN 'SYSTEM_USER' THEN CASE WHEN COALESCE(ctx_user_initial.office_count,0)>0 THEN ctx_user_initial_office.dad_number ELSE COALESCE(ctx_user_scope_office.dad_number,ctx_primary_office.dad_number) END
            WHEN 'USER_ROLE' THEN ctx_role_scope_office.dad_number
            WHEN 'USER_SCOPE' THEN COALESCE(ctx_scope_direct_office.dad_number,ctx_scope_location_office.dad_number)
          END";
        $officeName="CASE {$n}.entity_type
            WHEN 'OFFICER' THEN CASE WHEN COALESCE(ctx_officer_initial.office_count,0)>0 THEN ctx_officer_initial_office.name_en ELSE ctx_primary_office.name_en END
            WHEN 'OFFICER_EDIT_REQUEST' THEN ctx_primary_office.name_en
            WHEN 'OFFICER_OFFICE_ASSIGNMENT' THEN ctx_assignment_office.name_en
            WHEN 'ARPA_DIVISION_REQUEST' THEN ctx_division_office.name_en
            WHEN 'ARPA_SUBJECT_REQUEST' THEN ctx_subject_office.name_en
            WHEN 'SYSTEM_USER' THEN CASE WHEN COALESCE(ctx_user_initial.office_count,0)>0 THEN ctx_user_initial_office.name_en ELSE COALESCE(ctx_user_scope_office.name_en,ctx_primary_office.name_en) END
            WHEN 'USER_ROLE' THEN ctx_role_scope_office.name_en
            WHEN 'USER_SCOPE' THEN COALESCE(ctx_scope_direct_office.name_en,ctx_scope_location_office.name_en)
          END";
        $joins="
          LEFT JOIN officer ctx_officer ON {$n}.entity_type='OFFICER' AND ctx_officer.id={$n}.entity_id
          LEFT JOIN officer_edit_request ctx_edit_request ON {$n}.entity_type='OFFICER_EDIT_REQUEST' AND ctx_edit_request.id={$n}.entity_id
          LEFT JOIN officer ctx_edit_officer ON ctx_edit_officer.id=ctx_edit_request.officer_id
          LEFT JOIN officer_office_assignment ctx_assignment ON {$n}.entity_type='OFFICER_OFFICE_ASSIGNMENT' AND ctx_assignment.id={$n}.entity_id
          LEFT JOIN officer ctx_assignment_officer ON ctx_assignment_officer.id=ctx_assignment.officer_id
          LEFT JOIN office ctx_assignment_office ON ctx_assignment_office.id=ctx_assignment.office_id
          LEFT JOIN arpa_division_appointment_request ctx_division_request ON {$n}.entity_type='ARPA_DIVISION_REQUEST' AND ctx_division_request.id={$n}.entity_id
          LEFT JOIN officer ctx_division_officer ON ctx_division_officer.id=ctx_division_request.officer_id
          LEFT JOIN office ctx_division_office ON ctx_division_office.linked_location_id=ctx_division_request.asc_location_id
          LEFT JOIN arpa_subject_assignment_request ctx_subject_request ON {$n}.entity_type='ARPA_SUBJECT_REQUEST' AND ctx_subject_request.id={$n}.entity_id
          LEFT JOIN officer ctx_subject_officer ON ctx_subject_officer.id=ctx_subject_request.officer_id
          LEFT JOIN office ctx_subject_office ON ctx_subject_office.linked_location_id=ctx_subject_request.asc_location_id
          LEFT JOIN system_user ctx_user ON {$n}.entity_type='SYSTEM_USER' AND ctx_user.id={$n}.entity_id
          LEFT JOIN officer ctx_user_officer ON ctx_user_officer.id=ctx_user.officer_id
          LEFT JOIN user_account_role ctx_role ON {$n}.entity_type='USER_ROLE' AND ctx_role.id={$n}.entity_id
          LEFT JOIN system_user ctx_role_user ON ctx_role_user.id=ctx_role.user_id
          LEFT JOIN officer ctx_role_officer ON ctx_role_officer.id=ctx_role_user.officer_id
          LEFT JOIN user_account_scope ctx_scope ON {$n}.entity_type='USER_SCOPE' AND ctx_scope.id={$n}.entity_id
          LEFT JOIN system_user ctx_scope_user ON ctx_scope_user.id=ctx_scope.user_id
          LEFT JOIN officer ctx_scope_officer ON ctx_scope_officer.id=ctx_scope_user.officer_id
          LEFT JOIN (
            SELECT officer_id,COUNT(DISTINCT office_id) office_count,CASE WHEN COUNT(DISTINCT office_id)=1 THEN MAX(office_id) END office_id
            FROM officer_office_assignment
            WHERE reason='".OfficerOfficeAssignmentService::INITIAL_OFFICER_REASON."'
            GROUP BY officer_id
          ) ctx_officer_initial ON ctx_officer_initial.officer_id=ctx_officer.id
          LEFT JOIN office ctx_officer_initial_office ON ctx_officer_initial_office.id=ctx_officer_initial.office_id
          LEFT JOIN (
            SELECT officer_id,COUNT(DISTINCT office_id) office_count,CASE WHEN COUNT(DISTINCT office_id)=1 THEN MAX(office_id) END office_id
            FROM officer_office_assignment
            WHERE reason='".OfficerOfficeAssignmentService::USER_ACCOUNT_REQUEST_INITIAL_REASON."'
            GROUP BY officer_id
          ) ctx_user_initial ON ctx_user_initial.officer_id=ctx_user_officer.id
          LEFT JOIN office ctx_user_initial_office ON ctx_user_initial_office.id=ctx_user_initial.office_id
          LEFT JOIN (
            SELECT uar.user_id,CASE WHEN COUNT(DISTINCT COALESCE(uas.office_id,lo.id))=1 THEN MAX(COALESCE(uas.office_id,lo.id)) END office_id
            FROM user_account_role uar
            LEFT JOIN user_account_scope uas ON uas.role_assignment_id=uar.id
            LEFT JOIN office lo ON lo.linked_location_id=uas.location_id
            WHERE uar.reason='Initial role for user account request'
            GROUP BY uar.user_id
          ) ctx_user_scope_resolution ON ctx_user_scope_resolution.user_id=ctx_user.id
          LEFT JOIN office ctx_user_scope_office ON ctx_user_scope_office.id=ctx_user_scope_resolution.office_id
          LEFT JOIN (
            SELECT uas.role_assignment_id,CASE WHEN COUNT(DISTINCT COALESCE(uas.office_id,lo.id))=1 THEN MAX(COALESCE(uas.office_id,lo.id)) END office_id
            FROM user_account_scope uas
            LEFT JOIN office lo ON lo.linked_location_id=uas.location_id
            GROUP BY uas.role_assignment_id
          ) ctx_role_scope_resolution ON ctx_role_scope_resolution.role_assignment_id=ctx_role.id
          LEFT JOIN office ctx_role_scope_office ON ctx_role_scope_office.id=ctx_role_scope_resolution.office_id
          LEFT JOIN office ctx_scope_direct_office ON ctx_scope_direct_office.id=ctx_scope.office_id
          LEFT JOIN office ctx_scope_location_office ON ctx_scope_location_office.linked_location_id=ctx_scope.location_id
          LEFT JOIN office ctx_primary_office ON ctx_primary_office.id=COALESCE(ctx_officer.primary_office_id,ctx_edit_officer.primary_office_id,ctx_assignment_officer.primary_office_id,ctx_division_officer.primary_office_id,ctx_subject_officer.primary_office_id,ctx_user_officer.primary_office_id,ctx_role_officer.primary_office_id,ctx_scope_officer.primary_office_id)";
        return [
            'joins'=>$joins,
            'select'=>["{$officerId} notification_officer_id","{$officerDad} notification_officer_dad_number","{$officerName} notification_officer_name","{$officeDad} notification_office_dad_number","{$officeName} notification_office_name"],
            'searchable'=>[$officerDad,$officerName,$officeDad,$officeName],
        ];
    }

    /** @return array{officer:?string,office:?string} */
    public static function displayContext(array $notification):array
    {
        $officerDad=trim((string)($notification['notification_officer_dad_number']??''));
        $officerName=trim((string)($notification['notification_officer_name']??''));
        $officeDad=trim((string)($notification['notification_office_dad_number']??''));
        $officeName=trim((string)($notification['notification_office_name']??''));
        $officer=$officerName===''?null:trim(($officerDad!==''?$officerDad.' - ':'').$officerName);
        $office=$officeName===''?null:trim(($officeDad!==''?$officeDad.' - ':'').$officeName);
        return ['officer'=>$officer,'office'=>$office];
    }
    public function resolveStage(string $entityType,string $entityId,string $stage,string $actorId,string $reason='Workflow stage completed'):void
    {
        $s=$this->pdo->prepare("SELECT id,recipient_user_id FROM system_notification WHERE entity_type=? AND entity_id=? AND workflow_stage=? AND notification_type='ACTION_REQUIRED' AND action_status='PENDING' FOR UPDATE");$s->execute([$entityType,$entityId,$stage]);
        foreach($s->fetchAll() as $row){$status=(string)$row['recipient_user_id']===$actorId?'COMPLETED':'RESOLVED_BY_OTHER';$u=$this->pdo->prepare('UPDATE system_notification SET action_status=?,completed_at=NOW(),resolved_by=?,resolution_reason=?,updated_at=NOW() WHERE id=? AND action_status=\'PENDING\'');$u->execute([$status,$actorId,$reason,$row['id']]);Audit::record('notification.'.strtolower($status),'SYSTEM_NOTIFICATION',(string)$row['id'],['resolved_by'=>$actorId,'reason'=>$reason]);}
    }
    public function resolveAll(string $entityType,string $entityId,string $actorId,string $reason='Workflow resolved',string $status='RESOLVED_BY_OTHER'):void
    {
        if(!in_array($status,self::TERMINAL,true))throw new DomainException('Invalid notification resolution status.');$s=$this->pdo->prepare("UPDATE system_notification SET action_status=?,completed_at=NOW(),resolved_by=?,resolution_reason=?,updated_at=NOW() WHERE entity_type=? AND entity_id=? AND notification_type='ACTION_REQUIRED' AND action_status='PENDING'");$s->execute([$status,$actorId,$reason,$entityType,$entityId]);
    }
}
