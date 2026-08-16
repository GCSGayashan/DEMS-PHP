<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Auth,Controller,CredentialService,Database,Csrf,Audit,DataTableRegistry};
use App\Services\OperationalUserActivationService;
use DomainException;
use Throwable;

final class UserManagementController extends Controller
{
    public function accounts(): void
    {
        Auth::requirePermission('user.view');
        $pdo=Database::pdo();
        $officers=$pdo->query("SELECT o.id,o.dad_number,o.name_with_initials FROM officer o LEFT JOIN `system_user` su ON su.officer_id=o.id WHERE o.approval_status='APPROVED' AND su.id IS NULL ORDER BY o.name_with_initials LIMIT 500")->fetchAll();
        $options=[
            'account_status'=>$this->distinctOptions("SELECT DISTINCT account_status value FROM `system_user` ORDER BY account_status"),
            'mfa_method'=>$this->distinctOptions("SELECT DISTINCT mfa_method value FROM `system_user` WHERE mfa_method IS NOT NULL ORDER BY mfa_method"),
        ];
        $dataTable=DataTableRegistry::viewModel('users',[],$options);
        $this->render('users/accounts',compact('dataTable','officers'));
    }

    public function historicalUsers():void
    {
        Auth::requirePermission('user.view');
        $options=['account_status'=>$this->distinctOptions("SELECT DISTINCT account_status value FROM system_user WHERE historical_identity=1 ORDER BY account_status")];
        $dataTable=DataTableRegistry::viewModel('historical-users',[],$options);
        $this->render('users/historical_accounts',compact('dataTable'));
    }

    public function activateForm(string $id):void
    {
        $this->requireActivationPermissions();$pdo=Database::pdo();
        $stmt=$pdo->prepare("SELECT su.*,lur.id legacy_reference_id,lur.legacy_user_id,lur.legacy_username,lur.legacy_display_name,lur.legacy_nic,lur.legacy_role_name,lur.legacy_user_level_name,lur.legacy_status,(SELECT COUNT(*) FROM legacy_arpa_appointment_preview p WHERE JSON_SEARCH(p.workflow_json,'one',lur.legacy_user_id) IS NOT NULL) workflow_activity_count FROM system_user su JOIN legacy_user_reference lur ON lur.system_user_id=su.id WHERE su.id=? AND su.historical_identity=1");$stmt->execute([$id]);$identity=$stmt->fetch();if(!$identity){http_response_code(404);exit('Historical identity not found.');}
        $stmt=$pdo->prepare("SELECT c.*,l.dad_number,l.name_en,t.system_key location_type FROM legacy_user_organization_context c LEFT JOIN location l ON l.id=c.location_id LEFT JOIN location_type t ON t.id=l.location_type_id WHERE c.legacy_user_reference_id=? ORDER BY c.legacy_level_key,l.name_en");$stmt->execute([$identity['legacy_reference_id']]);$legacyContexts=$stmt->fetchAll();
        $roles=$pdo->query("SELECT role_code,role_name,role_level FROM application_role WHERE role_code IN ('ASC_SUBJECT_OFFICER','ASC_ADMIN','ASC_VIEWER','DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN','DISTRICT_VIEWER','NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN','NATIONAL_VIEWER') AND active=1 AND assignable=1 AND approval_status='APPROVED' ORDER BY FIELD(role_level,'ASC','DISTRICT','NATIONAL'),role_name")->fetchAll();
        $locations=$pdo->query("SELECT l.id,l.dad_number,l.name_en,t.system_key location_type FROM location l JOIN location_type t ON t.id=l.location_type_id WHERE t.system_key IN('ASC','DISTRICT') AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' ORDER BY t.system_key,l.name_en")->fetchAll();
        $this->render('users/activate_historical',compact('identity','legacyContexts','roles','locations'));
    }

    public function activateHistorical(string $id):void
    {
        $this->requireActivationPermissions();Csrf::validate();
        try{(new OperationalUserActivationService(Database::pdo()))->activate($id,$_POST,(string)Auth::user()['id']);$this->flash('success','Historical identity activated as an operational STAFF user. A password change is required at first login.');redirect('/access-management/users');}
        catch(Throwable $e){error_log('Operational user activation failed: '.$e->getMessage());$this->flash('danger',$e instanceof DomainException?$e->getMessage():'Unable to activate the selected identity.');redirect('/access-management/users/'.$id.'/activate');}
    }

    public function deactivateUser(string $id):void
    {
        Auth::requirePermission('user.block');Csrf::validate();
        try{(new OperationalUserActivationService(Database::pdo()))->deactivate($id,(string)($_POST['reason']??''),$_POST['official_reference']??null,(string)Auth::user()['id']);$this->flash('success','Operational login disabled and current role/scope periods ended.');}
        catch(Throwable $e){error_log('Operational user deactivation failed: '.$e->getMessage());$this->flash('danger',$e instanceof DomainException?$e->getMessage():'Unable to deactivate the selected user.');}
        redirect('/access-management/users');
    }

    public function deactivateForm(string $id):void
    {
        Auth::requirePermission('user.block');$stmt=Database::pdo()->prepare("SELECT id,username,display_name,email,identity_type,account_status,enabled FROM system_user WHERE id=? AND identity_type='STAFF'");$stmt->execute([$id]);$identity=$stmt->fetch();if(!$identity){http_response_code(404);exit('Operational user not found.');}$this->render('users/deactivate_user',compact('identity'));
    }

    public function resetPasswordForm(string $id):void
    {
        Auth::requirePermission('user.reset-password');
        $user=$this->passwordResetTarget($id);
        if(!$user){http_response_code(404);exit('Operational user not found.');}
        $this->render('users/reset_password',compact('user'));
    }

    public function resetPassword(string $id):void
    {
        Auth::requirePermission('user.reset-password');
        Csrf::validate();
        try{
            CredentialService::resetUserPassword(
                Database::pdo(),$id,
                (string)($_POST['temporary_password']??''),
                (string)($_POST['temporary_password_confirmation']??''),
                (string)($_POST['reason']??''),
                isset($_POST['official_reference'])?(string)$_POST['official_reference']:null
            );
            $this->flash('success','Temporary password set. The user must change it at next login.');
            redirect('/access-management/users');
        }catch(Throwable $e){
            error_log('Administrative password reset failed for user ID '.preg_replace('/[^a-zA-Z0-9-]/','',$id).': '.get_class($e));
            $this->flash('danger',$e instanceof DomainException?$e->getMessage():'Unable to reset the selected user password.');
            redirect('/access-management/users/'.$id.'/reset-password');
        }
    }

    public function requestAccount(): void
    {
        Auth::requirePermission('user.request'); Csrf::validate();
        $username=strtolower(trim((string)($_POST['username']??''))); $password=(string)($_POST['temporary_password']??'');
        if(!preg_match('/^[a-z0-9._-]{5,50}$/',$username)){
            $this->flash('danger','Valid username is required.'); redirect('/access-management/users');
        }
        try{
            $passwordHash=\App\Core\CredentialService::hashTemporaryPassword($password);
        }catch(\DomainException $e){
            $this->flash('danger',$e->getMessage()); redirect('/access-management/users');
        }
        $stmt=Database::pdo()->prepare("INSERT INTO `system_user`(id,officer_id,identity_type,username,password_hash,account_status,approval_status,enabled,mfa_method,password_setup_required,mfa_enrolled,requested_by,requested_at,created_at) VALUES(UUID(),?,'STAFF',?,?,'REQUESTED','DRAFT',0,?,1,0,?,NOW(),NOW())");
        $stmt->execute([$_POST['officer_id'],$username,$passwordHash,$_POST['mfa_method']??'AUTHENTICATOR_APP',Auth::user()['id']]);
        Audit::record('user.request','SYSTEM_USER',null,['username'=>$username]);
        $this->flash('success','User account request created.'); redirect('/access-management/account-requests');
    }

    public function requests(): void
    {
        Auth::requirePermission('user.view');
        $options=['account_status'=>$this->distinctOptions("SELECT DISTINCT account_status value FROM `system_user` ORDER BY account_status")];
        $dataTable=DataTableRegistry::viewModel('account-requests',[],$options);
        $this->render('users/requests',compact('dataTable'));
    }

    public function submitAccount(string $id): void
    {
        Auth::requirePermission('user.submit'); Csrf::validate();
        Database::pdo()->prepare("UPDATE `system_user` SET approval_status='SUBMITTED',submitted_by=?,submitted_at=NOW() WHERE id=? AND approval_status='DRAFT'")->execute([Auth::user()['id'],$id]);
        Audit::record('user.submit','SYSTEM_USER',$id); redirect('/access-management/account-requests');
    }

    public function approveAccount(string $id): void
    {
        Auth::requirePermission('user.approve'); Csrf::validate();
        $pdo=Database::pdo(); $stmt=$pdo->prepare('SELECT requested_by,approval_status FROM `system_user` WHERE id=?'); $stmt->execute([$id]); $row=$stmt->fetch();
        if(!$row||$row['approval_status']!=='SUBMITTED'){ $this->flash('danger','Only submitted requests can be approved.'); redirect('/access-management/account-requests'); }
        if((string)$row['requested_by']===(string)Auth::user()['id']){ $this->flash('danger','Maker cannot approve their own account request.'); redirect('/access-management/account-requests'); }
        $pdo->prepare("UPDATE `system_user` SET approval_status='APPROVED',account_status='ACTIVE',enabled=1,approved_by=?,approved_at=NOW(),activated_by=?,activated_at=NOW() WHERE id=?")->execute([Auth::user()['id'],Auth::user()['id'],$id]);
        Audit::record('user.approve','SYSTEM_USER',$id); $this->flash('success','User account approved and activated.'); redirect('/access-management/users');
    }

    public function roles(): void
    {
        Auth::requirePermission('role.manage');
        $pdo=Database::pdo(); $showLegacy=!empty($_GET['show_legacy']);
        $permissions=$pdo->query("SELECT * FROM application_permission WHERE active=1 ORDER BY module_code,permission_key")->fetchAll();
        $dataTable=DataTableRegistry::viewModel('roles',[],[],['legacy'=>$showLegacy?'':'0']);
        $this->render('users/roles',compact('dataTable','permissions','showLegacy'));
    }

    public function createRole(): void
    {
        Auth::requirePermission('role.manage'); Csrf::validate(); $pdo=Database::pdo();
        $name=trim((string)($_POST['role_name']??'')); $code=strtoupper(trim((string)($_POST['role_code']??'')));
        if($code==='') $code=strtoupper(preg_replace('/[^A-Z0-9]+/','_',$name));
        if($name===''||!preg_match('/^[A-Z][A-Z0-9_]{2,99}$/',$code)){ $this->flash('danger','Role name and a valid uppercase system key are required.'); redirect('/access-management/roles'); }
        $pdo->beginTransaction();
        try{
            $pdo->prepare("INSERT INTO application_role(id,role_code,role_name,description,role_level,protected_role,assignable,legacy,approval_status,active,effective_from,created_by,created_at) VALUES(UUID(),?,?,?,'CUSTOM',0,1,0,'DRAFT',1,CURRENT_DATE(),?,NOW())")->execute([$code,$name,trim((string)($_POST['description']??''))?:null,Auth::user()['id']]);
            $rid=$pdo->prepare('SELECT id FROM application_role WHERE role_code=?'); $rid->execute([$code]); $roleId=$rid->fetchColumn();
            $ins=$pdo->prepare('INSERT IGNORE INTO application_role_permission(role_id,permission_id) VALUES(?,?)');
            foreach((array)($_POST['permissions']??[]) as $pid) $ins->execute([$roleId,$pid]);
            $pdo->commit(); Audit::record('role.create','APPLICATION_ROLE',(string)$roleId,['role_code'=>$code]); $this->flash('success','Custom role draft created.');
        }catch(\Throwable $e){ $pdo->rollBack(); $this->flash('danger',$e->getMessage()); }
        redirect('/access-management/roles');
    }

    public function submitRole(string $id): void
    {
        Auth::requirePermission('role.manage'); Csrf::validate();
        Database::pdo()->prepare("UPDATE application_role SET approval_status='SUBMITTED',submitted_by=?,submitted_at=NOW() WHERE id=? AND protected_role=0 AND approval_status='DRAFT'")->execute([Auth::user()['id'],$id]);
        Audit::record('role.submit','APPLICATION_ROLE',$id); redirect('/access-management/roles');
    }

    public function approveRole(string $id): void
    {
        Auth::requirePermission('role.manage'); Csrf::validate(); $pdo=Database::pdo();
        $stmt=$pdo->prepare('SELECT created_by,approval_status FROM application_role WHERE id=?'); $stmt->execute([$id]); $r=$stmt->fetch();
        if(!$r||$r['approval_status']!=='SUBMITTED'){ $this->flash('danger','Only submitted custom roles can be approved.'); redirect('/access-management/roles'); }
        if((string)$r['created_by']===(string)Auth::user()['id']){ $this->flash('danger','Maker cannot approve their own role.'); redirect('/access-management/roles'); }
        $pdo->prepare("UPDATE application_role SET approval_status='APPROVED',approved_by=?,approved_at=NOW() WHERE id=?")->execute([Auth::user()['id'],$id]);
        Audit::record('role.approve','APPLICATION_ROLE',$id); $this->flash('success','Custom role approved.'); redirect('/access-management/roles');
    }

    public function permissions(): void
    {
        Auth::requirePermission('permission.view');
        $options=['module'=>$this->distinctOptions('SELECT DISTINCT module_code value FROM application_permission ORDER BY module_code')];
        $dataTable=DataTableRegistry::viewModel('permissions',[],$options);
        $this->render('users/permissions',compact('dataTable'));
    }

    public function roleAssignments(): void
    {
        Auth::requirePermission('user.assign-role');
        $pdo=Database::pdo();
        $users=$pdo->query("SELECT id,username FROM `system_user` WHERE enabled=1 ORDER BY username LIMIT 1000")->fetchAll();
        $roles=$pdo->query("SELECT id,role_code,role_name,role_level FROM application_role WHERE active=1 AND assignable=1 AND approval_status='APPROVED' ORDER BY role_level,role_name")->fetchAll();
        $roleOptions=[]; foreach($pdo->query('SELECT id,role_name,role_code FROM application_role ORDER BY role_name')->fetchAll() as $role){$roleOptions[$role['id']]=$role['role_name'].' ('.$role['role_code'].')';}
        $dataTable=DataTableRegistry::viewModel('role-assignments',[],['role'=>$roleOptions]);
        $this->render('users/role_assignments',compact('dataTable','users','roles'));
    }

    public function assignRole(): void
    {
        Auth::requirePermission('user.assign-role'); Csrf::validate();
        $stmt=Database::pdo()->prepare("INSERT INTO user_account_role (id,user_id,role_id,effective_from,effective_to,approval_status,active,created_by,created_at) VALUES(UUID(),?,?,?,?, 'DRAFT',0,?,NOW())");
        $stmt->execute([$_POST['user_id'],$_POST['role_id'],$_POST['effective_from']?:date('Y-m-d'),($_POST['effective_to']??'')?:null,Auth::user()['id']]);
        Audit::record('user.role.assign','USER_ROLE',null,['user_id'=>$_POST['user_id'],'role_id'=>$_POST['role_id']]);
        $this->flash('success','Role assignment draft created.'); redirect('/access-management/role-assignments');
    }

    public function submitRoleAssignment(string $id): void
    {
        Auth::requirePermission('user.assign-role'); Csrf::validate();
        Database::pdo()->prepare("UPDATE user_account_role SET approval_status='SUBMITTED',submitted_by=?,submitted_at=NOW() WHERE id=? AND created_by=? AND approval_status='DRAFT'")->execute([Auth::user()['id'],$id,Auth::user()['id']]);
        Audit::record('user.role.submit','USER_ROLE',$id); redirect('/access-management/role-assignments');
    }

    public function approveRoleAssignment(string $id): void
    {
        Auth::requirePermission('user.assign-role'); Csrf::validate(); $pdo=Database::pdo();
        $stmt=$pdo->prepare('SELECT created_by,approval_status FROM user_account_role WHERE id=?');$stmt->execute([$id]);$row=$stmt->fetch();
        if(!$row||$row['approval_status']!=='SUBMITTED'){ $this->flash('danger','Only submitted role assignments can be approved.'); redirect('/access-management/role-assignments'); }
        if((string)$row['created_by']===(string)Auth::user()['id']){ $this->flash('danger','Maker cannot approve their own role assignment.'); redirect('/access-management/role-assignments'); }
        $pdo->prepare("UPDATE user_account_role SET approval_status='APPROVED',active=1,approved_by=?,approved_at=NOW() WHERE id=?")->execute([Auth::user()['id'],$id]);
        Audit::record('user.role.approve','USER_ROLE',$id); $this->flash('success','Role assignment approved.'); redirect('/access-management/role-assignments');
    }

    public function scopes(): void
    {
        Auth::requirePermission('user.assign-scope');
        $pdo=Database::pdo();
        $roleAssignments=$pdo->query("SELECT uar.id,su.username,r.role_name,r.role_code,r.role_level,uar.user_id FROM user_account_role uar JOIN `system_user` su ON su.id=uar.user_id JOIN application_role r ON r.id=uar.role_id WHERE uar.active=1 AND uar.approval_status='APPROVED' AND r.role_level<>'FARMER' ORDER BY su.username,r.role_name")->fetchAll();
        $locations=$pdo->query("SELECT l.id,l.dad_number,l.name_en,lt.system_key type_key FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE l.approval_status='APPROVED' ORDER BY l.name_en LIMIT 2000")->fetchAll();
        $scopeOptions=['scope_type'=>$this->distinctOptions('SELECT DISTINCT scope_type value FROM user_account_scope ORDER BY scope_type')];
        $dataTable=DataTableRegistry::viewModel('scope-assignments',[],$scopeOptions);
        $this->render('users/scope_assignments',compact('dataTable','roleAssignments','locations'));
    }

    public function assignScope(): void
    {
        Auth::requirePermission('user.assign-scope'); Csrf::validate(); $pdo=Database::pdo();
        $stmt=$pdo->prepare("SELECT uar.user_id,r.role_level FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.id=? AND uar.active=1 AND uar.approval_status='APPROVED'");
        $stmt->execute([$_POST['role_assignment_id']??'']); $assignment=$stmt->fetch();
        if(!$assignment){$this->flash('danger','Select an approved active role assignment.');redirect('/access-management/scope-assignments');}
        $type=$_POST['scope_type']??'NATIONAL'; $level=$assignment['role_level'];
        $expected=['SYSTEM'=>'NATIONAL','NATIONAL'=>'NATIONAL','DISTRICT'=>'DISTRICT','ASC'=>'ASC','ARPA'=>'ARPA_DIVISION'];
        if(isset($expected[$level]) && $expected[$level]!==$type){$this->flash('danger',"Role level {$level} requires {$expected[$level]} scope.");redirect('/access-management/scope-assignments');}
        $location=($type==='NATIONAL')?null:(($_POST['location_id']??'')?:null);
        if($type!=='NATIONAL' && !$location){$this->flash('danger','A location target is required for this scope.');redirect('/access-management/scope-assignments');}
        $stmt=$pdo->prepare("INSERT INTO user_account_scope (id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,effective_to,approval_status,active,created_by,created_at) VALUES(UUID(),?,?,?,?,?,?,?,?,'DRAFT',0,?,NOW())");
        $stmt->execute([$assignment['user_id'],$_POST['role_assignment_id'],$type,$_POST['scope_mode']??'EXACT',$location,$_POST['effective_from']?:date('Y-m-d'),($_POST['effective_to']??'')?:null,Auth::user()['id']]);
        Audit::record('user.scope.create','USER_SCOPE',null,['user_id'=>$assignment['user_id'],'scope_type'=>$type,'location_id'=>$location]);
        $this->flash('success','Scope assignment draft created.'); redirect('/access-management/scope-assignments');
    }

    public function submitScope(string $id): void
    {
        Auth::requirePermission('user.assign-scope'); Csrf::validate();
        Database::pdo()->prepare("UPDATE user_account_scope SET approval_status='SUBMITTED',submitted_by=?,submitted_at=NOW() WHERE id=? AND created_by=? AND approval_status='DRAFT'")->execute([Auth::user()['id'],$id,Auth::user()['id']]);
        Audit::record('user.scope.submit','USER_SCOPE',$id);redirect('/access-management/scope-assignments');
    }

    public function approveScope(string $id): void
    {
        Auth::requirePermission('user.assign-scope'); Csrf::validate();$pdo=Database::pdo();$stmt=$pdo->prepare('SELECT created_by,approval_status FROM user_account_scope WHERE id=?');$stmt->execute([$id]);$row=$stmt->fetch();
        if(!$row||$row['approval_status']!=='SUBMITTED'){$this->flash('danger','Only submitted scope assignments can be approved.');redirect('/access-management/scope-assignments');}
        if((string)$row['created_by']===(string)Auth::user()['id']){$this->flash('danger','Maker cannot approve their own scope assignment.');redirect('/access-management/scope-assignments');}
        $pdo->prepare("UPDATE user_account_scope SET approval_status='APPROVED',active=1,approved_by=?,approved_at=NOW() WHERE id=?")->execute([Auth::user()['id'],$id]);
        Audit::record('user.scope.approve','USER_SCOPE',$id);$this->flash('success','Scope assignment approved.');redirect('/access-management/scope-assignments');
    }

    public function provisioningFailures(): void
    {
        Auth::requirePermission('user.retry-provisioning');
        $options=['category'=>$this->distinctOptions('SELECT DISTINCT failure_category value FROM provisioning_failure ORDER BY failure_category')];
        $dataTable=DataTableRegistry::viewModel('provisioning-failures',[],$options);
        $this->render('users/provisioning_failures',compact('dataTable'));
    }

    public function securityHistory(): void
    {
        Auth::requirePermission('user.view-security-history');
        $options=['event_type'=>$this->distinctOptions('SELECT DISTINCT action_key value FROM audit_event ORDER BY action_key')];
        $dataTable=DataTableRegistry::viewModel('security-history',[],$options);
        $this->render('users/security_history',compact('dataTable'));
    }

    private function distinctOptions(string $sql): array
    {
        $options=[];
        foreach(Database::pdo()->query($sql)->fetchAll() as $row){$value=(string)($row['value']??'');if($value!=='')$options[$value]=$value;}
        return $options;
    }
    private function passwordResetTarget(string $id):?array
    {
        $sql="SELECT su.id,su.username,su.display_name,su.identity_type,su.account_status,su.enabled,
            (SELECT GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ')
             FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id
             WHERE uar.user_id=su.id AND uar.active=1 AND uar.approval_status='APPROVED'
               AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE())) effective_roles,
            (SELECT GROUP_CONCAT(DISTINCT CONCAT(uas.scope_type,' / ',uas.scope_mode,
                COALESCE(CONCAT(' / ',l.dad_number,' - ',l.name_en),CONCAT(' / ',off.dad_number,' - ',off.name_en),''))
                ORDER BY uas.scope_type SEPARATOR '; ')
             FROM user_account_scope uas LEFT JOIN location l ON l.id=uas.location_id LEFT JOIN office off ON off.id=uas.office_id
             WHERE uas.user_id=su.id AND uas.active=1 AND uas.approval_status='APPROVED'
               AND uas.effective_from<=CURRENT_DATE() AND (uas.effective_to IS NULL OR uas.effective_to>=CURRENT_DATE())) effective_scopes
            FROM system_user su WHERE su.id=? AND su.identity_type<>'HISTORICAL' AND su.enabled=1 AND su.account_status='ACTIVE'";
        $stmt=Database::pdo()->prepare($sql);$stmt->execute([$id]);
        return $stmt->fetch()?:null;
    }
    private function requireActivationPermissions():void{foreach(['user.activate','user.assign-role','user.assign-scope','user.reset-password'] as $permission)Auth::requirePermission($permission);}
}
