<?php
declare(strict_types=1);
namespace App\Services;

use PDO;

final class WorkflowNotificationService
{
    private NotificationService $notifications; private NotificationRecipientResolver $recipients;
    public function __construct(private readonly PDO $pdo){$this->notifications=new NotificationService($pdo);$this->recipients=new NotificationRecipientResolver($pdo);}
    public function actionForPermission(string $permission,?string $locationId,string $module,string $title,string $message,string $entityType,string $entityId,string $stage,string $url,string $actorId,array $roleCodes=[]):int
    {
        $n=0;foreach($this->recipients->forPermission($permission,$locationId,$actorId,$roleCodes) as $r){$this->notifications->createActionRequired((string)$r['user_id'],$module,$title,$message,$entityType,$entityId,$stage,$url,'Review','HIGH',$actorId,(string)$r['role_assignment_id'],$r['scope_assignment_id']?:(null));$n++;}return $n;
    }
    public function actionForUser(string $recipient,string $module,string $title,string $message,string $entityType,string $entityId,string $stage,string $url,string $actorId,?string $roleId=null,?string $scopeId=null):void{$this->notifications->createActionRequired($recipient,$module,$title,$message,$entityType,$entityId,$stage,$url,'Review','HIGH',$actorId,$roleId,$scopeId);}
    public function information(string $recipient,string $module,string $title,string $message,string $entityType,string $entityId,string $url,string $actorId):void{$this->notifications->createInformation($recipient,$module,$title,$message,$entityType,$entityId,$url,$actorId);}
    public function completeStage(string $entityType,string $entityId,string $stage,string $actorId,string $reason):void{$this->notifications->resolveStage($entityType,$entityId,$stage,$actorId,$reason);}
    public function resolveAll(string $entityType,string $entityId,string $actorId,string $reason,string $status='RESOLVED_BY_OTHER'):void{$this->notifications->resolveAll($entityType,$entityId,$actorId,$reason,$status);}
}
