<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableRegistry,Database};
use App\Services\{AssignmentDeletePolicy,OfficerOfficeAssignmentService,OfficerProfileService,UserAccessManagementService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class AssignmentDeleteTest
{
    private PDO $pdo;
    private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->pdo->beginTransaction();
        try{$this->exercise();}finally{$_SESSION=[];Auth::forgetRequestCache();$this->pdo->rollBack();}
        echo "AssignmentDeleteTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        $admin=(string)$this->value("SELECT id FROM system_user WHERE username='dems.admin'");
        $this->useFirstContext($admin);$this->same(true,AssignmentDeletePolicy::allowed(),'only canonical dems.admin policy is enabled');
        $service=new UserAccessManagementService($this->pdo);$officeService=new OfficerOfficeAssignmentService($this->pdo);
        $notifications=$this->count('SELECT COUNT(*) FROM system_notification');
        $arpaBefore=$this->arpaCounts();

        [$ascA,$ascB]=$this->ascFixtures();
        $target=$this->createUser('delete-target');
        $roleA=$this->createAssignment($target,'ASC_SUBJECT_OFFICER',$ascA);
        $scopeA=(string)$this->value('SELECT id FROM user_account_scope WHERE role_assignment_id=?',[$roleA]);
        $roleB=$this->createAssignment($target,'ASC_VIEWER',$ascB);
        $scopeB=(string)$this->value('SELECT id FROM user_account_scope WHERE role_assignment_id=?',[$roleB]);

        $roleConfig=DataTableRegistry::definition('role-assignments');
        $roleAction=$roleConfig['columns'][8]['format'](['id'=>$roleA,'approval_status'=>'APPROVED','active'=>1,'created_by'=>null]);
        $this->same(true,str_contains($roleAction,"/role-assignments/{$roleA}/delete"),'dems.admin sees role Delete action');
        $scopeConfig=DataTableRegistry::definition('scope-assignments');
        $scopeAction=$scopeConfig['columns'][8]['format'](['id'=>$scopeA,'approval_status'=>'APPROVED','active'=>1,'created_by'=>null]);
        $this->same(true,str_contains($scopeAction,"/scope-assignments/{$scopeA}/delete"),'dems.admin sees scope Delete action');
        $officerView=(string)file_get_contents(BASE_PATH.'/app/Views/officers/show.php');
        $this->same(true,str_contains($officerView,'AssignmentDeletePolicy::allowed()'),'dems.admin-only Office assignment Delete action is present');

        $this->throws(fn()=>$service->adminDeleteRoleAssignment($admin,$roleA,''),'role delete reason is mandatory');
        $service->adminDeleteRoleAssignment($admin,$roleA,'Duplicate role assignment');
        $deletedRole=$this->row('SELECT active,deleted_at,deleted_by,delete_reason FROM user_account_role WHERE id=?',[$roleA]);
        $this->same(0,(int)$deletedRole['active'],'deleted role is inactive');$this->notNull($deletedRole['deleted_at'],'deleted role retains deletion timestamp');
        $this->same($admin,$deletedRole['deleted_by'],'deleted role records actor');$this->same('Duplicate role assignment',$deletedRole['delete_reason'],'deleted role records reason');
        $this->same(1,$this->count('SELECT COUNT(*) FROM user_account_scope WHERE id=? AND deleted_at IS NOT NULL AND active=0',[$scopeA]),'dependent scope is revoked and retained');
        $this->same(1,$this->count('SELECT COUNT(*) FROM user_account_role WHERE id=? AND deleted_at IS NULL AND active=1',[$roleB]),'other valid role is unchanged');
        $this->same(1,$this->count('SELECT COUNT(*) FROM user_account_scope WHERE id=? AND deleted_at IS NULL AND active=1',[$scopeB]),'other valid scope is unchanged');
        $this->same(1,$this->count("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='user.role.admin-delete'",[$roleA]),'role delete audit is retained');
        $this->same(1,$this->count("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='user.scope.admin-delete'",[$scopeA]),'dependent scope delete audit is retained');
        $contexts=(new UserContextService($this->pdo))->availableContexts($target);
        $this->same(false,in_array($roleA,array_column($contexts,'role_assignment_id'),true),'deleted role no longer grants authorization');
        $this->same(true,in_array($roleB,array_column($contexts,'role_assignment_id'),true),'unrelated authorization remains available');

        $scopeOnlyRole=$this->createAssignment($target,'ASC_VIEWER',$ascA);$scopeOnly=(string)$this->value('SELECT id FROM user_account_scope WHERE role_assignment_id=?',[$scopeOnlyRole]);
        $this->throws(fn()=>$service->adminDeleteScopeAssignment($admin,$scopeOnly,''),'scope delete reason is mandatory');
        $service->adminDeleteScopeAssignment($admin,$scopeOnly,'Wrong scope assignment');
        $this->same(1,$this->count('SELECT COUNT(*) FROM user_account_scope WHERE id=? AND deleted_at IS NOT NULL AND active=0',[$scopeOnly]),'scope soft delete revokes access');
        $this->same(1,$this->count('SELECT COUNT(*) FROM user_account_role WHERE id=? AND deleted_at IS NULL AND active=1',[$scopeOnlyRole]),'scope delete does not delete its role');
        $this->same(false,in_array($scopeOnly,array_column((new UserContextService($this->pdo))->availableContexts($target),'scope_assignment_id'),true),'deleted scope no longer grants scope access');
        $this->same(1,$this->count("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='user.scope.admin-delete'",[$scopeOnly]),'scope delete audit is retained');

        [$officer,$officeA,$officeB]=$this->officerOfficeFixtures();
        $officeAssignment=$this->uuid();$otherOfficeAssignment=$this->uuid();
        $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,is_primary,approval_status,active,reason) VALUES(?,?,?,'2025-01-01',1,'APPROVED',1,'Delete fixture')")->execute([$officeAssignment,$officer,$officeA]);
        $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,is_primary,approval_status,active,reason) VALUES(?,?,?,'2025-01-01',0,'APPROVED',1,'Unrelated fixture')")->execute([$otherOfficeAssignment,$officer,$officeB]);
        $this->pdo->prepare('UPDATE officer SET primary_office_id=? WHERE id=?')->execute([$officeA,$officer]);
        $pendingConfig=DataTableRegistry::definition('pending-officer-office-assignments');
        $pendingAction=$pendingConfig['columns'][9]['format'](['id'=>$officeAssignment,'officer_id'=>$officer]);
        $this->same(true,str_contains($pendingAction,"/hr/officers/{$officer}/offices/{$officeAssignment}/delete"),'dems.admin sees Office assignment Delete action');
        $this->throws(fn()=>$officeService->adminDelete($officeAssignment,'',$admin),'Office delete reason is mandatory');
        $officeService->adminDelete($officeAssignment,'Wrong officer assignment',$admin);
        $this->same(1,$this->count('SELECT COUNT(*) FROM officer_office_assignment WHERE id=? AND deleted_at IS NOT NULL AND active=0 AND is_primary=0',[$officeAssignment]),'Office assignment is soft deleted');
        $this->same(1,$this->count('SELECT COUNT(*) FROM officer_office_assignment WHERE id=? AND deleted_at IS NULL AND active=1',[$otherOfficeAssignment]),'other Office assignment is unchanged');
        $this->same(null,$this->value('SELECT primary_office_id FROM officer WHERE id=?',[$officer]),'deleted primary pointer is cleared without promoting another assignment');
        $this->same(1,$this->count("SELECT COUNT(*) FROM officer_office_assignment_audit WHERE assignment_id=? AND action_key='ADMIN_DELETE'",[$officeAssignment]),'Office delete audit is retained');
        $profile=(new OfficerProfileService($this->pdo))->profile($officer);
        $visibleOfficeIds=array_merge(array_column($profile['current_offices'],'id'),array_column($profile['historical_offices'],'id'));
        $this->same(false,in_array($officeAssignment,$visibleOfficeIds,true),'deleted Office assignment disappears from normal profile lists');

        foreach([
            ['SYSTEM_ADMIN',null,'delete-other-system'],['NATIONAL_ADMIN',null,'delete-national-admin'],['NATIONAL_SUBJECT_OFFICER',null,'delete-national-subject'],
            ['DISTRICT_ADMIN',$this->districtFor($ascA),'delete-district'],['ASC_ADMIN',$ascA,'delete-asc'],
        ] as [$roleCode,$location,$name]){
            $actor=$this->createActor($roleCode,$location,$name);$this->useFirstContext($actor);
            $this->same(false,AssignmentDeletePolicy::allowed(),"{$roleCode} is not dems.admin");
            $this->throws(fn()=>$service->deleteRoleRecord($actor,$roleB),"{$roleCode} direct role-delete request is rejected");
            $this->throws(fn()=>$service->deleteScopeRecord($actor,$scopeB),"{$roleCode} direct scope-delete request is rejected");
            $this->throws(fn()=>$officeService->deleteRecord($otherOfficeAssignment,$actor),"{$roleCode} direct Office-delete request is rejected");
        }
        $this->useFirstContext($admin);
        $this->same($notifications,$this->count('SELECT COUNT(*) FROM system_notification'),'deletes create no approval notification');
        $this->same($arpaBefore,$this->arpaCounts(),'ARPA records are unchanged');
        $routes=(string)file_get_contents(BASE_PATH.'/routes/web.php');$controller=(string)file_get_contents(BASE_PATH.'/app/Controllers/UserManagementController.php').(string)file_get_contents(BASE_PATH.'/app/Controllers/OfficerController.php');
        $this->same(true,str_contains($routes,"->post('/hr/officers/{id}/offices/{assignmentId}/delete'")&&str_contains($routes,"->post('/access-management/role-assignments/{id}/delete'")&&str_contains($routes,"->post('/access-management/scope-assignments/{id}/delete'"),'three destructive delete routes are POST endpoints');
        $this->same(true,substr_count($controller,'Csrf::validate()')>=3,'all delete controllers use the existing CSRF validator');
        $view=(string)file_get_contents(BASE_PATH.'/app/Views/assignments/delete_confirm.php');
        $this->same(true,str_contains($view,'name="delete_reason"')&&str_contains($view,'name="confirm_delete"'),'confirmation requires reason and explicit acknowledgement');
    }

    /** @return array{0:string,1:string} */
    private function ascFixtures():array{$rows=$this->pdo->query("SELECT l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC' WHERE l.approval_status='APPROVED' AND l.operational_status='ACTIVE' ORDER BY l.id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);if(count($rows)<2)throw new RuntimeException('Two ASC fixtures are required.');return array_map('strval',$rows);}
    private function districtFor(string $asc):string{$s=$this->pdo->prepare("WITH RECURSIVE ancestors(id) AS (SELECT ? UNION DISTINCT SELECT lr.parent_location_id FROM location_relationship lr JOIN ancestors a ON a.id=lr.child_location_id WHERE lr.active=1 AND lr.approval_status='APPROVED') SELECT l.id FROM ancestors a JOIN location l ON l.id=a.id JOIN location_type lt ON lt.id=l.location_type_id WHERE lt.system_key='DISTRICT' LIMIT 1");$s->execute([$asc]);return (string)$s->fetchColumn();}
    /** @return array{0:string,1:string,2:string} */
    private function officerOfficeFixtures():array{$r=$this->pdo->query("SELECT f.id officer_id,MIN(o.id) office_a,MAX(o.id) office_b FROM officer f CROSS JOIN office o JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' WHERE f.approval_status='APPROVED' AND o.approval_status='APPROVED' AND o.operational_status='ACTIVE' AND NOT EXISTS(SELECT 1 FROM officer_office_assignment current_a WHERE current_a.officer_id=f.id AND current_a.deleted_at IS NULL AND current_a.active=1 AND current_a.approval_status='APPROVED' AND current_a.effective_from<=CURRENT_DATE() AND (current_a.effective_to IS NULL OR current_a.effective_to>=CURRENT_DATE())) GROUP BY f.id HAVING COUNT(*)>=2 LIMIT 1")->fetch();if(!$r)throw new RuntimeException('Officer/Office fixtures are required.');return [(string)$r['officer_id'],(string)$r['office_a'],(string)$r['office_b']];}
    private function createActor(string $roleCode,?string $location,string $username):string{$user=$this->createUser($username);$this->createAssignment($user,$roleCode,$location);return $user;}
    private function createUser(string $username):string{$id=$this->uuid();$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$id,$username,$username]);return $id;}
    private function createAssignment(string $user,string $roleCode,?string $location):string{$id=$this->uuid();$role=(string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$roleCode]);$level=(string)$this->value('SELECT role_level FROM application_role WHERE id=?',[$role]);$this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(?,?,?,'2025-01-01','APPROVED',1,'Delete test fixture')")->execute([$id,$user,$role]);if(in_array($level,['NATIONAL','DISTRICT','ASC','ARPA','FARMER'],true)){$type=['NATIONAL'=>'NATIONAL','DISTRICT'=>'DISTRICT','ASC'=>'ASC','ARPA'=>'ARPA_DIVISION','FARMER'=>'ASC'][$level];$mode=['NATIONAL'=>'NATIONAL','DISTRICT'=>'INCLUDE_CHILDREN','ASC'=>'EXACT','ARPA'=>'EXACT','FARMER'=>'EXACT'][$level];$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(UUID(),?,?,?,?,?,'2025-01-01','APPROVED',1,'Delete test fixture')")->execute([$user,$id,$type,$mode,$location]);}return $id;}
    private function useFirstContext(string $user):void{$_SESSION=['user_id'=>$user,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();$contexts=(new UserContextService($this->pdo))->availableContexts($user);if($contexts===[])throw new RuntimeException('An active context is required.');$context=$contexts[0];(new UserContextService($this->pdo))->select($user,(string)$context['role_assignment_id'],$context['scope_assignment_id']===null?null:(string)$context['scope_assignment_id']);Auth::forgetRequestCache();}
    private function arpaCounts():array{return ['appointments'=>$this->count('SELECT COUNT(*) FROM arpa_division_appointment'),'subjects'=>$this->count('SELECT COUNT(*) FROM arpa_subject_assignment'),'requests'=>$this->count('SELECT COUNT(*) FROM arpa_division_appointment_request')];}
    private function row(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function count(string $sql,array $params=[]):int{return (int)$this->value($sql,$params);}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function notNull(mixed $actual,string $message):void{$this->assertions++;if($actual===null||$actual==='')throw new RuntimeException($message.': value was null');}
    private function throws(callable $fn,string $message):void{$this->assertions++;try{$fn();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
}

exit((new AssignmentDeleteTest())->run());
