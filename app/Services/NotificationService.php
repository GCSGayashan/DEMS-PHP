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
    public function recent(string $userId,int $limit=8):array{$limit=max(1,min(20,$limit));$s=$this->pdo->prepare("SELECT * FROM system_notification WHERE recipient_user_id=? AND ((notification_type='ACTION_REQUIRED' AND action_status='PENDING') OR (notification_type IN('INFORMATION','WARNING') AND read_at IS NULL)) ORDER BY (notification_type='ACTION_REQUIRED' AND action_status='PENDING') DESC,FIELD(priority,'URGENT','HIGH','NORMAL'),created_at DESC LIMIT {$limit}");$s->execute([$userId]);return $s->fetchAll();}
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
