<?php
declare(strict_types=1);

use App\Core\{CredentialService,Database};

require dirname(__DIR__).'/bootstrap.php';

final class AdminPasswordResetAuthorizationTest
{
    private int $assertions=0;

    public function run():int
    {
        $pdo=Database::pdo();
        $actorId=(string)$pdo->query('SELECT UUID()')->fetchColumn();
        $targetId=(string)$pdo->query('SELECT UUID()')->fetchColumn();
        $pdo->prepare("INSERT INTO system_user(id,identity_type,username,password_hash,account_status,enabled,password_setup_required,created_at) VALUES(?,'STAFF',?,?,'ACTIVE',1,0,NOW()),(?,'STAFF',?,?,'ACTIVE',1,0,NOW())")
            ->execute([$actorId,'reset-denied-'.substr($actorId,0,8),CredentialService::hashPassword('ActorPassword!123'),$targetId,'reset-target-'.substr($targetId,0,8),CredentialService::hashPassword('TargetPassword!123')]);
        $_SESSION=['user_id'=>$actorId];
        try{
            $before=(string)$pdo->query("SELECT password_hash FROM system_user WHERE id='{$targetId}'")->fetchColumn();
            $denied=false;
            try{CredentialService::resetUserPassword($pdo,$targetId,'TemporaryPassword!456','TemporaryPassword!456','Unauthorized attempt');}catch(DomainException){$denied=true;}
            $after=(string)$pdo->query("SELECT password_hash FROM system_user WHERE id='{$targetId}'")->fetchColumn();
            $this->same(true,$denied,'user without user.reset-password is denied by the service');
            $this->same($before,$after,'direct service bypass leaves the credential unchanged');
            $this->same(0,(int)$pdo->query("SELECT COUNT(*) FROM audit_event WHERE target_id='{$targetId}' AND action_key='ADMIN_PASSWORD_RESET'")->fetchColumn(),'denied bypass creates no success audit event');
        }finally{
            $pdo->prepare('DELETE FROM audit_event WHERE actor_user_id IN (?,?) OR target_id IN (?,?)')->execute([$actorId,$targetId,$actorId,$targetId]);
            $pdo->prepare('DELETE FROM system_user WHERE id IN (?,?)')->execute([$actorId,$targetId]);
        }
        echo "AdminPasswordResetAuthorizationTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function same(mixed $expected,mixed $actual,string $message):void
    {
        $this->assertions++;
        if($expected!==$actual)throw new RuntimeException("{$message}: expected ".var_export($expected,true).', got '.var_export($actual,true));
    }
}

exit((new AdminPasswordResetAuthorizationTest())->run());
