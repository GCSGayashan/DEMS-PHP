<?php
declare(strict_types=1);

use App\Core\{Auth,CredentialService,Database};

require dirname(__DIR__).'/bootstrap.php';

final class AccountChangePasswordTest
{
    private PDO $pdo;private int $assertions=0;private string $id='';
    public function run():int
    {
        $this->pdo=Database::pdo();$this->same(1,(int)$this->pdo->query("SELECT COUNT(*) FROM system_user WHERE username='asctest' AND enabled=1 AND account_status='ACTIVE'")->fetchColumn(),'asctest is an enabled operational account');$this->same(true,str_contains(file_get_contents(BASE_PATH.'/routes/web.php'),"/account/change-password"),'authenticated change-password route exists');
        $this->id=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();$username='passwordtest-'.substr(str_replace('-','',$this->id),0,10);$old='OldPassword!123';$new='NewPassword!456';$hash=CredentialService::hashPassword($old);$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,password_hash,account_status,enabled,password_setup_required,created_at) VALUES(?,'STAFF',?,?,'ACTIVE',1,1,NOW())")->execute([$this->id,$username,$hash]);$_SESSION=['user_id'=>$this->id];
        try{
            $this->rejects(fn()=>CredentialService::changeOwnPassword($this->pdo,$this->id,'WrongPassword!1',$new,$new),'wrong current password rejected');$this->same($hash,$this->passwordHash(),'wrong current password leaves hash unchanged');
            $this->rejects(fn()=>CredentialService::changeOwnPassword($this->pdo,$this->id,$old,'weak-password','weak-password'),'weak password rejected');
            CredentialService::changeOwnPassword($this->pdo,$this->id,$old,$new,$new);$changed=$this->pdo->query("SELECT password_hash,password_setup_required,password_changed_at FROM system_user WHERE id='{$this->id}'")->fetch();$this->same(true,password_verify($new,$changed['password_hash']),'successful change hashes the new password');$this->same(false,password_verify($old,$changed['password_hash']),'old password no longer authenticates');$this->same(0,(int)$changed['password_setup_required'],'voluntary change clears setup requirement');$this->same(true,$changed['password_changed_at']!==null,'password change timestamp recorded');
            $asctest=(string)$this->pdo->query("SELECT id FROM system_user WHERE username='asctest'")->fetchColumn();$before=(string)$this->pdo->query("SELECT password_hash FROM system_user WHERE id='{$asctest}'")->fetchColumn();$this->rejects(fn()=>CredentialService::changeOwnPassword($this->pdo,$asctest,$new,'AnotherPassword!789','AnotherPassword!789'),'current user cannot change another account');$after=(string)$this->pdo->query("SELECT password_hash FROM system_user WHERE id='{$asctest}'")->fetchColumn();$this->same($before,$after,'another account remains unchanged');
            $audit=(string)$this->pdo->query("SELECT GROUP_CONCAT(CONCAT(action_key,':',COALESCE(details_json,'')) SEPARATOR '|') FROM audit_event WHERE actor_user_id='{$this->id}'")->fetchColumn();$this->same(true,str_contains($audit,'user.password.change'),'successful password change audited');$this->same(true,str_contains($audit,'user.password.change.failed'),'failed password change audited at security-event level');$this->same(false,str_contains($audit,$old)||str_contains($audit,$new),'password values never enter audit details');
            $controller=file_get_contents(BASE_PATH.'/app/Controllers/AuthController.php');$view=file_get_contents(BASE_PATH.'/app/Views/auth/change_password.php');$layout=file_get_contents(BASE_PATH.'/app/Views/layouts/admin.php');$this->same(false,str_contains($controller,"['user_id']"),'self-service route accepts no target user ID');$this->same(true,str_contains($view,'Current Password'),'form requires current password');$this->same(true,str_contains($view,'Csrf::field()'),'form is CSRF protected');$this->same(true,str_contains($layout,'Change Password'),'account menu exposes Change Password');
        }finally{$this->pdo->prepare('DELETE FROM audit_event WHERE actor_user_id=? OR target_id=?')->execute([$this->id,$this->id]);$this->pdo->prepare('DELETE FROM system_user WHERE id=?')->execute([$this->id]);}
        echo "AccountChangePasswordTest: {$this->assertions} assertions passed.\n";return 0;
    }
    private function passwordHash():string{$s=$this->pdo->prepare('SELECT password_hash FROM system_user WHERE id=?');$s->execute([$this->id]);return (string)$s->fetchColumn();}
    private function rejects(callable $action,string $message):void{$rejected=false;try{$action();}catch(DomainException){$rejected=true;}$this->same(true,$rejected,$message);}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException("{$message}: expected ".var_export($expected,true).', got '.var_export($actual,true));}
}
exit((new AccountChangePasswordTest())->run());
