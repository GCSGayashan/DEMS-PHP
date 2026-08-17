<?php
declare(strict_types=1);
namespace App\Core;

use DomainException;
use PDO;
use Throwable;

final class CredentialService
{
    public static function validateOperationalUsername(string $username): string
    {
        $username = trim($username);

        $length = function_exists('mb_strlen')
            ? mb_strlen($username, 'UTF-8')
            : strlen($username);

        if ($length < 5 || $length > 50) {
            throw new DomainException('Username must be 5-50 characters.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $username) === 1) {
            throw new DomainException('Username contains invalid control characters.');
        }

        return $username;
    }

    public static function hashTemporaryPassword(string $password): string
    {
        return self::hashPassword($password);
    }

    public static function hashPassword(string $password): string
    {
        if(strlen($password)<8 || preg_match('/[A-Z]/',$password)!==1 || preg_match('/[a-z]/',$password)!==1 || preg_match('/\d/',$password)!==1 || preg_match('/[^A-Za-z0-9]/',$password)!==1){
            throw new DomainException('Password must be at least 8 characters and include uppercase, lowercase, number, and symbol.');
        }
        $hash=password_hash($password,PASSWORD_DEFAULT);
        if($hash===false) throw new DomainException('Unable to create the secure password hash.');
        return $hash;
    }

    public static function changeOwnPassword(PDO $pdo,string $userId,string $currentPassword,string $newPassword,string $confirmation):void
    {
        $authenticated=Auth::user();
        if(!$authenticated||(string)$authenticated['id']!==$userId){
            throw new DomainException('You may change only your own password.');
        }

        $failure='UNKNOWN';
        try{
            $pdo->beginTransaction();
            $stmt=$pdo->prepare("SELECT id,password_hash,enabled,account_status FROM `system_user` WHERE id=? FOR UPDATE");
            $stmt->execute([$userId]);
            $user=$stmt->fetch();
            if(!$user||(int)$user['enabled']!==1||$user['account_status']!=='ACTIVE'){
                $failure='ACCOUNT_NOT_OPERATIONAL';
                throw new DomainException('Password change is unavailable for this account.');
            }
            if(empty($user['password_hash'])||!password_verify($currentPassword,(string)$user['password_hash'])){
                $failure='CURRENT_PASSWORD_INVALID';
                throw new DomainException('Current password is incorrect.');
            }
            if($newPassword!==$confirmation){
                $failure='CONFIRMATION_MISMATCH';
                throw new DomainException('New password and confirmation must match.');
            }
            if(password_verify($newPassword,(string)$user['password_hash'])){
                $failure='PASSWORD_REUSED';
                throw new DomainException('New password must differ from the current password.');
            }
            $failure='PASSWORD_POLICY_REJECTED';
            $newHash=self::hashPassword($newPassword);
            $failure='UPDATE_FAILED';
            $pdo->prepare('UPDATE `system_user` SET password_hash=?,password_setup_required=0,password_changed_at=NOW(),updated_at=NOW() WHERE id=?')
                ->execute([$newHash,$userId]);
            Audit::record('user.password.change','SYSTEM_USER',$userId,['method'=>'SELF_SERVICE'],'INFO');
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            try{Audit::record('user.password.change.failed','SYSTEM_USER',$userId,['failure_category'=>$failure],'WARNING');}catch(Throwable){/* Preserve the original credential failure. */}
            throw $e;
        }
    }

    public static function resetUserPassword(PDO $pdo,string $targetUserId,string $temporaryPassword,string $confirmation,string $reason,?string $officialReference=null):void
    {
        $administrator=Auth::user();
        if(!$administrator||!Auth::can('user.reset-password')){
            throw new DomainException('You do not have permission to reset user passwords.');
        }
        $reason=trim($reason);
        $officialReference=trim((string)$officialReference)?:null;
        if($reason==='') throw new DomainException('A password reset reason is required.');
        if($temporaryPassword!==$confirmation) throw new DomainException('Temporary password and confirmation must match.');

        try{
            $pdo->beginTransaction();
            $stmt=$pdo->prepare("SELECT id,username,password_hash,enabled,account_status FROM `system_user` WHERE id=? FOR UPDATE");
            $stmt->execute([$targetUserId]);
            $target=$stmt->fetch();
            if(!$target||(int)$target['enabled']!==1||$target['account_status']!=='ACTIVE'){
                throw new DomainException('Password reset is available only for an active operational account.');
            }
            if(!empty($target['password_hash'])&&password_verify($temporaryPassword,(string)$target['password_hash'])){
                throw new DomainException('Temporary password must differ from the current password.');
            }
            $newHash=self::hashTemporaryPassword($temporaryPassword);
            $pdo->prepare('UPDATE `system_user` SET password_hash=?,password_setup_required=1,password_changed_at=NOW(),updated_at=NOW() WHERE id=?')
                ->execute([$newHash,$targetUserId]);
            Audit::record('ADMIN_PASSWORD_RESET','SYSTEM_USER',$targetUserId,[
                'target_username'=>$target['username'],
                'administrator_id'=>$administrator['id'],
                'administrator_username'=>$administrator['username'],
                'reason'=>$reason,
                'official_reference'=>$officialReference,
                'credential_timestamp_semantics'=>'password_changed_at records when the credential was set',
            ],'WARNING');
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }
}
