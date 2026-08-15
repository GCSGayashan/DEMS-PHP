<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <username> <password>\n"); exit(1);
}
[$script,$username,$password]=$argv;
if (strlen($username)<5 || strlen($password)<12) {
    fwrite(STDERR, "Username must be at least 5 characters and password at least 12 characters.\n"); exit(1);
}
$pdo=App\Core\Database::pdo();
$pdo->beginTransaction();
try {
    $stmt=$pdo->prepare('SELECT id FROM `system_user` WHERE username=?');$stmt->execute([$username]);$userId=$stmt->fetchColumn();
    if (!$userId) {
        $pdo->prepare("INSERT INTO `system_user`(id,identity_type,username,password_hash,account_status,enabled,mobile_verified,created_at) VALUES(UUID(),'STAFF',?,?,'ACTIVE',1,0,NOW())")->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);
        $stmt=$pdo->prepare('SELECT id FROM `system_user` WHERE username=?');$stmt->execute([$username]);$userId=$stmt->fetchColumn();
    } else {
        $pdo->prepare('UPDATE `system_user` SET password_hash=?,account_status=\'ACTIVE\',enabled=1 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$userId]);
    }
    $roleId=$pdo->query("SELECT id FROM application_role WHERE role_code='SYSTEM_ADMIN'")->fetchColumn();
    if(!$roleId) throw new RuntimeException('SYSTEM_ADMIN role not seeded. Run migrations first.');
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM user_account_role WHERE user_id=? AND role_id=? AND active=1");$stmt->execute([$userId,$roleId]);
    if((int)$stmt->fetchColumn()===0){$pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,created_by,created_at,approved_by,approved_at) VALUES(UUID(),?,?,CURRENT_DATE(),'APPROVED',1,?,NOW(),?,NOW())")->execute([$userId,$roleId,$userId,$userId]);}
    $pdo->commit();
    echo "Administrator ready: {$username}\n";
} catch(Throwable $e){$pdo->rollBack();fwrite(STDERR,$e->getMessage()."\n");exit(1);}
