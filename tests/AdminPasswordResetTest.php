<?php
declare(strict_types=1);

use App\Core\{CredentialService,DataTableRegistry,Database};

require dirname(__DIR__).'/bootstrap.php';

final class AdminPasswordResetTest
{
    private PDO $pdo;
    private int $assertions=0;
    private string $adminId='';
    private string $targetId='';
    private string $targetRoleAssignmentId='';

    public function run():int
    {
        $this->pdo=Database::pdo();
        $asctestBefore=$this->asctestCredentialFingerprint();
        $this->adminId=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();
        $this->targetId=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();
        $this->targetRoleAssignmentId=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();
        $adminRole=(string)$this->pdo->query("SELECT id FROM application_role WHERE role_code='SYSTEM_ADMIN' AND active=1 AND approval_status='APPROVED'")->fetchColumn();
        $targetRole=(string)$this->pdo->query("SELECT id FROM application_role WHERE role_code='NATIONAL_VIEWER' AND active=1 AND approval_status='APPROVED'")->fetchColumn();
        $this->same(true,$adminRole!==''&&$targetRole!=='','test roles are available');

        $oldPassword='OriginalPassword!123';
        $newPassword='TemporaryPassword!456';
        $adminUsername='reset-admin-'.substr($this->adminId,0,8);
        $targetUsername='reset-user-'.substr($this->targetId,0,8);
        $this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,password_hash,account_status,approval_status,enabled,password_setup_required,created_at) VALUES(?,'STAFF',?,'Reset Test Administrator',?,'ACTIVE','APPROVED',1,0,NOW()),(?,'STAFF',?,'Reset Test User',?,'ACTIVE','APPROVED',1,0,NOW())")
            ->execute([$this->adminId,$adminUsername,CredentialService::hashPassword('AdministratorPassword!123'),$this->targetId,$targetUsername,CredentialService::hashPassword($oldPassword)]);
        $adminAssignment=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();
        $this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,created_by,approved_by,created_at,approved_at) VALUES(?,?,?,CURRENT_DATE(),'APPROVED',1,?,?,NOW(),NOW()),(?,?,?,CURRENT_DATE(),'APPROVED',1,?,?,NOW(),NOW())")
            ->execute([$adminAssignment,$this->adminId,$adminRole,$this->adminId,$this->adminId,$this->targetRoleAssignmentId,$this->targetId,$targetRole,$this->adminId,$this->adminId]);
        $scopeId=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();
        $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,effective_from,approval_status,active,created_by,created_at) VALUES(?,?,?,'NATIONAL','NATIONAL',CURRENT_DATE(),'APPROVED',1,?,NOW())")
            ->execute([$scopeId,$this->targetId,$this->targetRoleAssignmentId,$this->adminId]);
        $_SESSION=['user_id'=>$this->adminId];

        try{
            $before=$this->targetState();
            $this->rejects(fn()=>CredentialService::resetUserPassword($this->pdo,$this->targetId,$newPassword,$newPassword,''),'blank reason rejected');
            $this->rejects(fn()=>CredentialService::resetUserPassword($this->pdo,$this->targetId,$newPassword,'DifferentPassword!789','Support request'),'mismatched confirmation rejected');
            $this->rejects(fn()=>CredentialService::resetUserPassword($this->pdo,$this->targetId,'weak-password','weak-password','Support request'),'weak password rejected');
            $this->same($before['password_hash'],$this->targetState()['password_hash'],'validation failures leave password hash unchanged');

            CredentialService::resetUserPassword($this->pdo,$this->targetId,$newPassword,$newPassword,'Verified help-desk request','REF-RESET-TEST');
            $after=$this->targetState();
            $this->same(false,password_verify($oldPassword,$after['password_hash']),'old credential no longer authenticates');
            $this->same(true,password_verify($newPassword,$after['password_hash']),'temporary credential authenticates');
            $this->same(1,(int)$after['password_setup_required'],'next login requires user password setup');
            $this->same(true,$after['password_changed_at']!==null,'credential-setting timestamp is recorded');
            foreach(['id','username','account_status','approval_status','enabled','identity_type','officer_id','farmer_id'] as $field)$this->same($before[$field],$after[$field],"{$field} remains unchanged");
            $this->same($before['role_count'],$after['role_count'],'role assignments remain unchanged');
            $this->same($before['scope_count'],$after['scope_count'],'scope assignments remain unchanged');
            $this->same($before['legacy_reference_count'],$after['legacy_reference_count'],'legacy references remain unchanged');

            $audit=$this->pdo->query("SELECT action_key,target_id,details_json,severity FROM audit_event WHERE target_id='{$this->targetId}' AND action_key='ADMIN_PASSWORD_RESET' ORDER BY id DESC LIMIT 1")->fetch();
            $this->same('ADMIN_PASSWORD_RESET',$audit['action_key'],'administrative reset audit event created');
            $this->same('WARNING',$audit['severity'],'administrative reset is a security-significant event');
            $details=json_decode((string)$audit['details_json'],true,512,JSON_THROW_ON_ERROR);
            $this->same($targetUsername,$details['target_username'],'audit identifies target username');
            $this->same($this->adminId,$details['administrator_id'],'audit identifies administrator');
            $this->same('Verified help-desk request',$details['reason'],'audit records reason');
            $this->same('REF-RESET-TEST',$details['official_reference'],'audit records official reference');
            $auditText=(string)$audit['details_json'];
            $this->same(false,str_contains($auditText,$oldPassword)||str_contains($auditText,$newPassword),'password values never enter audit data');

            $routes=file_get_contents(BASE_PATH.'/routes/web.php');
            $controller=file_get_contents(BASE_PATH.'/app/Controllers/UserManagementController.php');
            $view=file_get_contents(BASE_PATH.'/app/Views/users/reset_password.php');
            $dashboard=file_get_contents(BASE_PATH.'/app/Views/modules/system_administration.php');
            $layout=file_get_contents(BASE_PATH.'/app/Views/layouts/admin.php');
            $this->same(2,substr_count($routes,'/access-management/users/{id}/reset-password'),'GET and POST reset routes are registered');
            $this->same(true,substr_count($controller,"Auth::requirePermission('user.reset-password')")>=2,'GET and POST require reset permission');
            $this->same(true,str_contains($controller,'Csrf::validate()'),'POST controller validates CSRF');
            $this->same(true,str_contains($view,'Csrf::field()'),'reset form contains CSRF token');
            $this->same(true,str_contains($dashboard,'Active Users')&&str_contains($dashboard,'User Requests'),'System Administration dashboard links User Management');
            $this->same(true,str_contains($dashboard,"Auth::can('user.view')"),'dashboard User Management cards are permission driven');
            $this->same(true,str_contains($layout,"Auth::can('user.view')"),'sidebar User Management links remain permission driven');
            $definition=DataTableRegistry::definition('users');
            $labels=array_column($definition['columns'],'label');
            foreach(['Username','Display Name','Identity Type','Account Status','Enabled','Role(s)','Assigned Locations','Password Setup','Last Password Changed','Actions'] as $label)$this->same(true,in_array($label,$labels,true),"active directory includes {$label}");
            $this->same(1,(int)$this->pdo->query("SELECT COUNT(*) FROM system_user WHERE username='asctest' AND enabled=1 AND account_status='ACTIVE'")->fetchColumn(),'asctest remains in Active Users');
        }finally{
            $this->pdo->prepare('DELETE FROM audit_event WHERE actor_user_id IN (?,?) OR target_id IN (?,?)')->execute([$this->adminId,$this->targetId,$this->adminId,$this->targetId]);
            $this->pdo->prepare('DELETE FROM user_account_scope WHERE user_id IN (?,?)')->execute([$this->adminId,$this->targetId]);
            $this->pdo->prepare('DELETE FROM user_account_role WHERE user_id IN (?,?)')->execute([$this->adminId,$this->targetId]);
            $this->pdo->prepare('DELETE FROM system_user WHERE id IN (?,?)')->execute([$this->adminId,$this->targetId]);
            $this->same($asctestBefore,$this->asctestCredentialFingerprint(),'asctest actual credential was not changed');
        }
        echo "AdminPasswordResetTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function targetState():array
    {
        $stmt=$this->pdo->prepare("SELECT su.*,(SELECT COUNT(*) FROM user_account_role WHERE user_id=su.id) role_count,(SELECT COUNT(*) FROM user_account_scope WHERE user_id=su.id) scope_count,(SELECT COUNT(*) FROM legacy_user_reference WHERE system_user_id=su.id) legacy_reference_count FROM system_user su WHERE su.id=?");
        $stmt->execute([$this->targetId]);
        return $stmt->fetch();
    }

    private function asctestCredentialFingerprint():string
    {
        return (string)$this->pdo->query("SELECT SHA2(COALESCE(password_hash,''),256) FROM system_user WHERE username='asctest'")->fetchColumn();
    }

    private function rejects(callable $action,string $message):void
    {
        $rejected=false;
        try{$action();}catch(DomainException){$rejected=true;}
        $this->same(true,$rejected,$message);
    }

    private function same(mixed $expected,mixed $actual,string $message):void
    {
        $this->assertions++;
        if($expected!==$actual)throw new RuntimeException("{$message}: expected ".var_export($expected,true).', got '.var_export($actual,true));
    }
}

exit((new AdminPasswordResetTest())->run());
