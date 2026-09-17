<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableRegistry,Database};
use App\Services\{AssignmentDirectEditPolicy,OfficerOfficeAssignmentService,UserAccessManagementService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class AssignmentDirectEditTest
{
    private PDO $pdo;private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->pdo->beginTransaction();
        try{$this->exercise();}finally{$_SESSION=[];Auth::forgetRequestCache();$this->pdo->rollBack();}
        echo "AssignmentDirectEditTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        [$ascA,$ascB]=$this->ascFixtures();
        $nationalSubject=$this->createActor('NATIONAL_SUBJECT_OFFICER',null,'direct-national-subject');
        $nationalAdmin=$this->createActor('NATIONAL_ADMIN',null,'direct-national-admin');
        $systemAdmin=$this->createActor('SYSTEM_ADMIN',null,'direct-system-admin');
        $district=$this->createActor('DISTRICT_SUBJECT_OFFICER',$this->districtFor($ascA),'direct-district');
        $ascActor=$this->createActor('ASC_ADMIN',$ascA,'direct-asc');
        $target=$this->createUser('direct-target');$roleAssignment=$this->createAssignment($target,'ASC_SUBJECT_OFFICER',$ascA);
        $scopeId=(string)$this->value('SELECT id FROM user_account_scope WHERE role_assignment_id=?',[$roleAssignment]);

        $this->useContext($nationalSubject,'NATIONAL_SUBJECT_OFFICER');
        $this->same(true,AssignmentDirectEditPolicy::allowed('user.assign-role'),'National Subject Officer receives direct-edit policy');
        $config=DataTableRegistry::definition('role-assignments');$action=$config['columns'][8]['format'](['id'=>$roleAssignment,'approval_status'=>'APPROVED','active'=>1,'created_by'=>null]);
        $this->same(true,str_contains($action,'/role-assignments/'.$roleAssignment.'/edit'),'National Subject Officer sees Edit on an assignment');

        $service=new UserAccessManagementService($this->pdo);$notifications=$this->count('SELECT COUNT(*) FROM system_notification');
        $service->directEditRoleAssignment($nationalSubject,$roleAssignment,['role_id'=>$this->roleId('ASC_ADMIN'),'effective_from'=>'2025-01-01','effective_to'=>'','reason'=>'National direct correction','official_reference'=>'DIRECT/ROLE']);
        $role=$this->row('SELECT role_id,effective_from,reason,official_reference,approval_status,active FROM user_account_role WHERE id=?',[$roleAssignment]);
        $this->same('2025-01-01',$role['effective_from'],'role edit is immediately visible');$this->same('APPROVED',$role['approval_status'],'role remains approved');$this->same(1,(int)$role['active'],'role remains active');
        $this->same($this->roleId('ASC_ADMIN'),$role['role_id'],'compatible role change is immediate');
        $this->same(1,$this->count("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='user.role.direct-edit'",[$roleAssignment]),'role direct edit is audited');

        $service->directEditScopeAssignment($nationalSubject,$scopeId,['location_id'=>$ascB,'effective_from'=>'2025-01-01','effective_to'=>'','reason'=>'Scope correction','official_reference'=>'DIRECT/SCOPE']);
        $scope=$this->row('SELECT location_id,approval_status,active FROM user_account_scope WHERE id=?',[$scopeId]);
        $this->same($ascB,$scope['location_id'],'scope edit is immediately visible');$this->same('APPROVED',$scope['approval_status'],'scope remains approved');$this->same(1,(int)$scope['active'],'scope remains active');
        $this->same(1,$this->count("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='user.scope.direct-edit'",[$scopeId]),'scope direct edit is audited');
        $this->same($notifications,$this->count('SELECT COUNT(*) FROM system_notification'),'direct access edits create no notification');

        [$officer,$officeA,$officeB]=$this->officerOfficeFixtures();$officeAssignment=$this->uuid();
        $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,approval_status,active,reason) VALUES(?,?,?,'2025-01-01','APPROVED',1,'Direct edit fixture')")->execute([$officeAssignment,$officer,$officeA]);
        $officeService=new OfficerOfficeAssignmentService($this->pdo);$officeService->directEdit($officeAssignment,['office_id'=>$officeB,'effective_from'=>'2025-01-01','effective_to'=>'','reason'=>'Head Office correction','official_reference'=>'DIRECT/OFFICE','remarks'=>'Immediate correction'],$nationalSubject);
        $officeRow=$this->row('SELECT office_id,approval_status,active,reason FROM officer_office_assignment WHERE id=?',[$officeAssignment]);
        $this->same($officeB,$officeRow['office_id'],'Office assignment edit is immediately visible');$this->same('APPROVED',$officeRow['approval_status'],'Office assignment remains approved');$this->same(1,(int)$officeRow['active'],'Office assignment remains active');
        $this->same(1,$this->count("SELECT COUNT(*) FROM officer_office_assignment_audit WHERE assignment_id=? AND action_key='DIRECT_EDIT'",[$officeAssignment]),'Office direct edit is audited');
        $this->same($notifications,$this->count('SELECT COUNT(*) FROM system_notification'),'Office direct edit creates no notification');

        $conflict=$this->uuid();$this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,approval_status,active,reason) VALUES(?,?,?,'2025-01-01','SUBMITTED',0,'Overlap fixture')")->execute([$conflict,$officer,$officeB]);
        $this->throws(fn()=>$officeService->directEdit($officeAssignment,['office_id'=>$officeB,'effective_from'=>'2025-01-01','effective_to'=>'','reason'=>'Must fail'],$nationalSubject),'existing overlap validation remains active');

        $this->useContext($nationalAdmin,'NATIONAL_ADMIN');$this->same(true,AssignmentDirectEditPolicy::allowed('user.assign-role'),'National Admin can direct edit');$service->directEditRoleAssignment($nationalAdmin,$roleAssignment,['effective_from'=>'2025-01-01','effective_to'=>'','reason'=>'National Admin direct correction','official_reference'=>'DIRECT/NATIONAL-ADMIN']);$this->same('National Admin direct correction',(string)$this->value('SELECT reason FROM user_account_role WHERE id=?',[$roleAssignment]),'National Admin direct edit is applied');
        $this->useContext($systemAdmin,'SYSTEM_ADMIN');$this->same(true,AssignmentDirectEditPolicy::allowed('user.assign-role'),'System Admin can direct edit');$service->directEditRoleAssignment($systemAdmin,$roleAssignment,['effective_from'=>'2025-01-01','effective_to'=>'','reason'=>'System Admin direct correction','official_reference'=>'DIRECT/SYSTEM-ADMIN']);$this->same('System Admin direct correction',(string)$this->value('SELECT reason FROM user_account_role WHERE id=?',[$roleAssignment]),'System Admin direct edit is applied');
        $this->useContext($district,'DISTRICT_SUBJECT_OFFICER');$this->same(false,AssignmentDirectEditPolicy::allowed('user.assign-role'),'District Subject Officer cannot direct edit');$this->throws(fn()=>$service->directEditRoleRecord($district,$roleAssignment),'District direct URL is rejected');
        $this->useContext($ascActor,'ASC_ADMIN');$this->same(false,AssignmentDirectEditPolicy::allowed('user.assign-role'),'ASC users cannot direct edit');$this->throws(fn()=>$service->directEditRoleRecord($ascActor,$roleAssignment),'ASC direct URL is rejected');

        $this->useContext($nationalSubject,'NATIONAL_SUBJECT_OFFICER');$newTarget=$this->createUser('direct-new-target');
        $newAssignment=$service->createSubmittedAssignment($nationalSubject,$newTarget,$this->roleId('ASC_SUBJECT_OFFICER'),$ascA,date('Y-m-d'),null,'New assignment still uses workflow','DIRECT/NEW');
        $this->same('SUBMITTED',(string)$this->value('SELECT approval_status FROM user_account_role WHERE id=?',[$newAssignment]),'new assignment creation still uses approval workflow');
    }

    /** @return array{0:string,1:string} */
    private function ascFixtures():array{$rows=$this->pdo->query("SELECT l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC' WHERE l.approval_status='APPROVED' AND l.operational_status='ACTIVE' ORDER BY l.id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);if(count($rows)<2)throw new RuntimeException('Two ASC fixtures are required.');return array_map('strval',$rows);}
    private function districtFor(string $asc):string{$s=$this->pdo->prepare("WITH RECURSIVE ancestors(id) AS (SELECT ? UNION DISTINCT SELECT lr.parent_location_id FROM location_relationship lr JOIN ancestors a ON a.id=lr.child_location_id WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=CURRENT_DATE() AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())) SELECT l.id FROM ancestors a JOIN location l ON l.id=a.id JOIN location_type lt ON lt.id=l.location_type_id WHERE lt.system_key='DISTRICT' LIMIT 1");$s->execute([$asc]);return (string)$s->fetchColumn();}
    /** @return array{0:string,1:string,2:string} */
    private function officerOfficeFixtures():array{$s=$this->pdo->query("SELECT f.id officer_id,MIN(o.id) office_a,MAX(o.id) office_b FROM officer f CROSS JOIN office o JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' WHERE f.approval_status='APPROVED' AND o.approval_status='APPROVED' AND o.operational_status='ACTIVE' AND NOT EXISTS(SELECT 1 FROM officer_office_assignment a WHERE a.officer_id=f.id AND a.office_id=o.id AND ((a.approval_status IN('DRAFT','SUBMITTED','RETURNED')) OR (a.approval_status='APPROVED' AND a.active=1))) GROUP BY f.id HAVING COUNT(*)>=2 LIMIT 1");$r=$s->fetch();if(!$r)throw new RuntimeException('Officer and Office fixtures are required.');return [(string)$r['officer_id'],(string)$r['office_a'],(string)$r['office_b']];}
    private function createActor(string $roleCode,?string $location,string $name):string{$u=$this->createUser($name);$this->createAssignment($u,$roleCode,$location);return $u;}
    private function createUser(string $name):string{$id=$this->uuid();$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$id,$name,$name]);return $id;}
    private function createAssignment(string $user,string $roleCode,?string $location):string{$id=$this->uuid();$role=$this->roleId($roleCode);$level=(string)$this->value('SELECT role_level FROM application_role WHERE id=?',[$role]);$this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(?,?,?,'2025-01-01','APPROVED',1,'Direct edit fixture')")->execute([$id,$user,$role]);if(in_array($level,['NATIONAL','DISTRICT','ASC','ARPA','FARMER'],true)){$type=['NATIONAL'=>'NATIONAL','DISTRICT'=>'DISTRICT','ASC'=>'ASC','ARPA'=>'ARPA_DIVISION','FARMER'=>'ASC'][$level];$mode=['NATIONAL'=>'NATIONAL','DISTRICT'=>'INCLUDE_CHILDREN','ASC'=>'EXACT','ARPA'=>'EXACT','FARMER'=>'EXACT'][$level];$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(UUID(),?,?,?,?,?,'2025-01-01','APPROVED',1,'Direct edit fixture')")->execute([$user,$id,$type,$mode,$location]);}return $id;}
    private function useContext(string $user,string $roleCode):void{$_SESSION=['user_id'=>$user,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();$service=new UserContextService($this->pdo);$context=array_values(array_filter($service->availableContexts($user),fn($row)=>(string)$row['role_code']===$roleCode))[0]??null;if(!$context)throw new RuntimeException("Missing {$roleCode} context");$service->select($user,(string)$context['role_assignment_id'],$context['scope_assignment_id']===null?null:(string)$context['scope_assignment_id']);Auth::forgetRequestCache();}
    private function roleId(string $code):string{return (string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$code]);}
    private function row(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}private function count(string $sql,array $params=[]):int{return (int)$this->value($sql,$params);}private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}private function throws(callable $fn,string $message):void{$this->assertions++;try{$fn();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
}

exit((new AssignmentDirectEditTest())->run());
