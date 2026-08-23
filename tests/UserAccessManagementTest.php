<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database,ScopeService};
use App\Services\{OperationalUserActivationService,UserAccessManagementService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class UserAccessManagementTest
{
    private PDO $pdo;
    private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$before=$this->state();$this->pdo->beginTransaction();
        try{$this->exercise();}finally{$_SESSION=[];$this->pdo->rollBack();}
        $this->same($before,$this->state(),'all focused access fixtures roll back');
        echo "UserAccessManagementTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        [$districtX,$ascX,$districtY,$ascY]=$this->districtFixtures();
        $system=$this->systemAdmin();$checker=$this->createActor('SYSTEM_ADMIN',null,'system-checker');
        $national=$this->createActor('NATIONAL_ADMIN',null,'national-manager');
        $nationalSubject=$this->createActor('NATIONAL_SUBJECT_OFFICER',null,'national-subject-manager');
        $district=$this->createActor('DISTRICT_ADMIN',$districtX,'district-manager');
        $districtSubject=$this->createActor('DISTRICT_SUBJECT_OFFICER',$districtX,'district-subject-manager');
        $policy=new UserAccessManagementService($this->pdo);

        $this->same('SYSTEM',$policy->authority($system)['kind'],'SYSTEM_ADMIN remains unrestricted');
        $this->same('NATIONAL',$policy->authority($national)['kind'],'NATIONAL_ADMIN requires and resolves its own National scope');
        $this->same('NATIONAL_SUBJECT',$policy->authority($nationalSubject)['kind'],'NATIONAL_SUBJECT_OFFICER resolves its own restricted National management authority');
        $this->same([$districtX],$policy->authority($district)['district_ids'],'DISTRICT_ADMIN resolves only its linked District scope');
        $this->same('DISTRICT_SUBJECT',$policy->authority($districtSubject)['kind'],'DISTRICT_SUBJECT_OFFICER resolves restricted District management authority');
        $this->same([$districtX],$policy->authority($districtSubject)['district_ids'],'District Subject Officer authority uses only its linked District scope');

        $requiredPermissions=['user.view','user.activate','user.block','user.reset-password','user.assign-role','user.revoke-role','user.assign-scope','user.revoke-scope'];
        $permissionPlaceholders=implode(',',array_fill(0,count($requiredPermissions),'?'));
        $this->same(count($requiredPermissions)*2,$this->count("SELECT COUNT(*) FROM application_role r JOIN application_role_permission rp ON rp.role_id=r.id JOIN application_permission p ON p.id=rp.permission_id WHERE r.role_code IN ('NATIONAL_SUBJECT_OFFICER','DISTRICT_SUBJECT_OFFICER') AND p.permission_key IN ({$permissionPlaceholders})",$requiredPermissions),'both Subject Officer roles have the requested User Management permissions');

        $queryPolicy=new UserAccessManagementService($this->pdo);$beforeQuestions=$this->questions();
        $manageableUsers=$queryPolicy->manageableUsers($district);$userQueryCount=$this->questions()-$beforeQuestions;
        $this->same(true,$userQueryCount<=3,'manageable users uses one authority query and one set-based user query');
        $expectedUsers=$this->count("SELECT COUNT(*) FROM system_user su WHERE su.enabled=1 AND NOT EXISTS (SELECT 1 FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.user_id=su.id AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE()) AND r.active=1 AND r.approval_status='APPROVED' AND r.role_level='SYSTEM')");
        $this->same($expectedUsers,count($manageableUsers),'set-based manageable users returns every eligible enabled identity');

        $districtViewer=$this->roleId('DISTRICT_VIEWER');$ascViewer=$this->roleId('ASC_VIEWER');
        $nationalViewer=$this->roleId('NATIONAL_VIEWER');$systemRole=$this->roleId('SYSTEM_ADMIN');
        $policy->validateAssignment($district,$districtViewer,$districtX,date('Y-m-d'));
        $policy->validateAssignment($district,$ascViewer,$ascX,date('Y-m-d'));
        $this->assertions+=2;
        $this->throws(fn()=>$policy->validateAssignment($district,$districtViewer,$districtY,date('Y-m-d')),'District actor cannot post another District');
        $this->throws(fn()=>$policy->validateAssignment($district,$ascViewer,$ascY,date('Y-m-d')),'District actor cannot post an ASC in another District');
        $this->throws(fn()=>$policy->validateAssignment($district,$nationalViewer,null,date('Y-m-d')),'District actor cannot assign National role');
        $this->throws(fn()=>$policy->validateAssignment($district,$systemRole,null,date('Y-m-d')),'District actor cannot assign System role');
        $policy->validateAssignment($national,$nationalViewer,null,date('Y-m-d'));
        $policy->validateAssignment($national,$districtViewer,$districtY,date('Y-m-d'));
        $this->assertions+=2;

        foreach(['NATIONAL_SUBJECT_OFFICER'=>null,'NATIONAL_VIEWER'=>null,'DISTRICT_SUBJECT_OFFICER'=>$districtY,'DISTRICT_VIEWER'=>$districtY,'ASC_SUBJECT_OFFICER'=>$ascY,'ASC_VIEWER'=>$ascY] as $roleCode=>$locationId){
            $policy->validateAssignment($nationalSubject,$this->roleId($roleCode),$locationId,date('Y-m-d'));
            $this->assertions++;
        }
        foreach(['SYSTEM_ADMIN','NATIONAL_ADMIN','DISTRICT_ADMIN','ASC_ADMIN'] as $roleCode){
            $locationId=['DISTRICT_ADMIN'=>$districtY,'ASC_ADMIN'=>$ascY][$roleCode]??null;
            $this->throws(fn()=>$policy->validateAssignment($nationalSubject,$this->roleId($roleCode),$locationId,date('Y-m-d')),"National Subject Officer cannot assign {$roleCode}");
        }
        $nationalSubjectRoles=array_column($policy->manageableRoles($nationalSubject),'role_code');
        sort($nationalSubjectRoles);
        $expectedNationalSubjectRoles=['ASC_SUBJECT_OFFICER','ASC_VIEWER','DISTRICT_SUBJECT_OFFICER','DISTRICT_VIEWER','NATIONAL_SUBJECT_OFFICER','NATIONAL_VIEWER'];
        sort($expectedNationalSubjectRoles);
        $this->same($expectedNationalSubjectRoles,$nationalSubjectRoles,'National Subject Officer sees only the approved manageable role matrix');
        $allowedTarget=$this->createUser('national-subject-target');
        $this->createAssignment($allowedTarget,'DISTRICT_SUBJECT_OFFICER',$districtY);
        $this->createAssignment($allowedTarget,'ASC_VIEWER',$ascY);
        $this->same(true,$policy->canManageUser($nationalSubject,$allowedTarget),'National Subject Officer can manage users whose roles are all permitted');
        $adminTarget=$this->createActor('ASC_ADMIN',$ascY,'national-subject-blocked-admin');
        $this->same(false,$policy->canManageUser($nationalSubject,$adminTarget),'National Subject Officer cannot manage an ASC Administrator account');

        foreach(['DISTRICT_SUBJECT_OFFICER'=>$districtX,'DISTRICT_VIEWER'=>$districtX,'ASC_SUBJECT_OFFICER'=>$ascX,'ASC_VIEWER'=>$ascX] as $roleCode=>$locationId){
            $policy->validateAssignment($districtSubject,$this->roleId($roleCode),$locationId,date('Y-m-d'));
            $this->assertions++;
        }
        foreach(['SYSTEM_ADMIN'=>null,'NATIONAL_SUBJECT_OFFICER'=>null,'NATIONAL_VIEWER'=>null,'DISTRICT_ADMIN'=>$districtX,'ASC_ADMIN'=>$ascX] as $roleCode=>$locationId){
            $this->throws(fn()=>$policy->validateAssignment($districtSubject,$this->roleId($roleCode),$locationId,date('Y-m-d')),"District Subject Officer cannot assign {$roleCode}");
        }
        $districtSubjectRoles=array_column($policy->manageableRoles($districtSubject),'role_code');
        sort($districtSubjectRoles);
        $expectedDistrictSubjectRoles=['ASC_SUBJECT_OFFICER','ASC_VIEWER','DISTRICT_SUBJECT_OFFICER','DISTRICT_VIEWER'];
        sort($expectedDistrictSubjectRoles);
        $this->same($expectedDistrictSubjectRoles,$districtSubjectRoles,'District Subject Officer sees only its approved manageable role matrix');
        $policy->validateAssignment($districtSubject,$this->roleId('DISTRICT_VIEWER'),$districtX,date('Y-m-d'));
        $policy->validateAssignment($districtSubject,$this->roleId('ASC_VIEWER'),$ascX,date('Y-m-d'));
        $this->assertions+=2;
        $this->throws(fn()=>$policy->validateAssignment($districtSubject,$this->roleId('DISTRICT_VIEWER'),$districtY,date('Y-m-d')),'District Subject Officer cannot post another District');
        $this->throws(fn()=>$policy->validateAssignment($districtSubject,$this->roleId('ASC_VIEWER'),$ascY,date('Y-m-d')),'District Subject Officer cannot post an ASC outside its District');
        $districtSubjectTarget=$this->createUser('district-subject-target');
        $districtSubjectAssignment=$this->createAssignment($districtSubjectTarget,'DISTRICT_VIEWER',$districtX);
        $this->createAssignment($districtSubjectTarget,'ASC_SUBJECT_OFFICER',$ascX);
        $this->same(true,$policy->canManageUser($districtSubject,$districtSubjectTarget),'District Subject Officer can manage permitted users in its District');
        $policy->assertCanManageRoleAssignment($districtSubject,$districtSubjectAssignment);$this->assertions++;
        $outsideDistrictSubjectTarget=$this->createActor('ASC_VIEWER',$ascY,'district-subject-outside-target');
        $this->same(false,$policy->canManageUser($districtSubject,$outsideDistrictSubjectTarget),'District Subject Officer cannot manage a user in another District');

        $districtLocations=$policy->manageableLocations($district);$districtLocationIds=array_column($districtLocations,'id');
        $this->same(true,in_array($districtX,$districtLocationIds,true),'District location read includes own District');
        $this->same(true,in_array($ascX,$districtLocationIds,true),'District location read includes descendant ASC');
        $this->same(false,in_array($districtY,$districtLocationIds,true),'District location read excludes another District');
        $this->same(false,in_array($ascY,$districtLocationIds,true),'District location read excludes another District ASC');
        $this->same([$districtX],array_column($policy->searchAssignableLocations($district,$districtViewer,'',100),'id'),'District role lookup returns only own District');
        $this->same(true,in_array($ascX,array_column($policy->searchAssignableLocations($district,$ascViewer,'',100),'id'),true),'ASC role lookup returns a descendant ASC');
        $this->same(false,in_array($ascY,array_column($policy->searchAssignableLocations($district,$ascViewer,'',100),'id'),true),'ASC role lookup excludes another District ASC');
        $this->throws(fn()=>$policy->searchAssignableLocations($national,$ascViewer,'',100),'National lookup preserves the implemented National/District assignment matrix');
        $arpaRole=$this->roleId('ARPA_OFFICER');$arpaResults=$policy->searchAssignableLocations($system,$arpaRole,'',100);
        $this->same(100,count($arpaResults),'ARPA lookup is bounded to 100 rows');
        $this->same(true,$this->count("SELECT COUNT(*) FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE lt.system_key='ARPA_DIVISION'")>100,'bounded ARPA test has more than 100 available rows');

        $target=$this->createUser('kasun.multi');
        $districtAssignment=$this->createAssignment($target,'DISTRICT_ADMIN',$districtX);
        $ascAssignment=$this->createAssignment($target,'ASC_VIEWER',$ascY);
        $this->same(2,$this->count('SELECT COUNT(*) FROM user_account_role WHERE user_id=?',[$target]),'one user supports two simultaneous roles');
        $this->same(2,$this->count('SELECT COUNT(DISTINCT location_id) FROM user_account_scope WHERE user_id=?',[$target]),'one user supports two different locations');
        $this->same(0,$this->count('SELECT COUNT(*) FROM user_account_scope uas JOIN user_account_role uar ON uar.id=uas.role_assignment_id WHERE uas.user_id<>uar.user_id AND uas.user_id=?',[$target]),'every scope belongs to the same user as its role assignment');
        $this->same(true,ScopeService::canAccessLocationForPermission($target,'data.approve',$ascX),'District permission works inside District X');
        $this->same(false,ScopeService::canAccessLocationForPermission($target,'data.approve',$ascY),'District permission cannot borrow ASC Viewer scope Y');
        $this->same(true,ScopeService::canAccessLocationForPermission($target,'data.view',$ascY),'ASC Viewer permission works in its own ASC Y');
        $this->same(true,in_array($target,array_column($policy->manageableUsers($district),'id'),true),'unrelated out-of-District assignment does not prevent adding an authorized new assignment');
        $this->same(false,$policy->canManageUser($district,$target),'user-level actions cannot affect a user who also has an out-of-District assignment');

        $sameLocationUser=$this->createUser('two.roles.same.location');
        $this->createAssignment($sameLocationUser,'ASC_VIEWER',$ascX);
        $this->createAssignment($sameLocationUser,'ASC_SUBJECT_OFFICER',$ascX);
        $this->same(2,$this->count('SELECT COUNT(*) FROM user_account_scope WHERE user_id=? AND location_id=?',[$sameLocationUser,$ascX]),'two roles may independently use the same location');

        $expired=$this->createActor('DISTRICT_ADMIN',$districtX,'expired-manager',1,'APPROVED',date('Y-m-d',strtotime('-2 days')));
        $inactive=$this->createActor('DISTRICT_ADMIN',$districtX,'inactive-manager',0,'APPROVED');
        $unapproved=$this->createActor('DISTRICT_ADMIN',$districtX,'unapproved-manager',1,'SUBMITTED');
        $this->throws(fn()=>$policy->authority($expired),'expired role grants no authority');
        $this->throws(fn()=>$policy->authority($inactive),'inactive role grants no authority');
        $this->throws(fn()=>$policy->authority($unapproved),'unapproved role grants no authority');

        $this->throws(fn()=>$policy->assertCanManageRoleAssignment($district,$ascAssignment),'direct assignment URL in another District is denied');
        $policy->assertCanManageRoleAssignment($district,$districtAssignment);$this->assertions++;

        $future=date('Y-m-d',strtotime('+10 days'));
        $replacement=$policy->createSubmittedAssignment($system,$target,$districtViewer,$districtY,$future,null,'Transfer to another District','TEST/TRANSFER',$districtAssignment);
        $this->same('SUBMITTED',(string)$this->value('SELECT approval_status FROM user_account_role WHERE id=?',[$replacement]),'new user role is submitted directly');
        $this->same('SUBMITTED',(string)$this->value('SELECT approval_status FROM user_account_scope WHERE role_assignment_id=?',[$replacement]),'linked assigned location is submitted in the same transaction');
        $this->throws(fn()=>$policy->approveAssignment($system,$replacement),'maker cannot approve replacement');
        $policy->approveAssignment($checker,$replacement);
        $expectedEnd=date('Y-m-d',strtotime($future.' -1 day'));
        $this->same($expectedEnd,(string)$this->value('SELECT effective_to FROM user_account_role WHERE id=?',[$districtAssignment]),'approved transfer closes old role period one day before replacement');
        $this->same($expectedEnd,(string)$this->value('SELECT effective_to FROM user_account_scope WHERE role_assignment_id=?',[$districtAssignment]),'approved transfer closes old linked scope period');
        $this->same('APPROVED',(string)$this->value('SELECT approval_status FROM user_account_role WHERE id=?',[$replacement]),'replacement role is approved');
        $this->same(1,$this->count('SELECT COUNT(*) FROM user_account_scope WHERE role_assignment_id=? AND location_id=?',[$replacement,$districtY]),'replacement has its own new District scope');
        $this->same(3,$this->count('SELECT COUNT(*) FROM user_account_role WHERE user_id=?',[$target]),'closed assignment remains in effective-dated history');

        $crossUserScope=$this->uuid();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdoThrows(function()use($crossUserScope,$sameLocationUser,$districtAssignment,$ascX):void{$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active) VALUES(?,?,?,'ASC','EXACT',?,CURRENT_DATE(),'APPROVED',1)")->execute([$crossUserScope,$sameLocationUser,$districtAssignment,$ascX]);},'composite FK rejects cross-user role/scope mapping');

        $this->activationCases($policy,$district,$national,$districtX,$districtY);

        $_SESSION=['user_id'=>$system,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();$systemContext=array_values(array_filter((new UserContextService($this->pdo))->availableContexts($system),fn(array $context):bool=>$context['role_code']==='SYSTEM_ADMIN'))[0]??null;if(!$systemContext)throw new RuntimeException('SYSTEM_ADMIN working context is required.');(new UserContextService($this->pdo))->select($system,(string)$systemContext['role_assignment_id'],$systemContext['scope_assignment_id']===null?null:(string)$systemContext['scope_assignment_id']);Auth::forgetRequestCache();
        $response=(new DataTableQuery($this->pdo,DataTableRegistry::definition('role-assignments'),new DataTableRequest(['length'=>100,'search'=>['value'=>'kasun.multi']])))->response();
        $this->same(true,count($response['data'])>=3,'same system user appears in multiple role-group rows');
        $this->same(true,str_contains((string)file_get_contents(dirname(__DIR__).'/app/Views/users/role_assignments.php'),'Users by Role'),'role assignment UI groups users underneath roles');
        $roleView=(string)file_get_contents(dirname(__DIR__).'/app/Views/users/role_assignments.php');$scopeView=(string)file_get_contents(dirname(__DIR__).'/app/Views/users/scope_assignments.php');
        $this->same(false,str_contains($roleView,'foreach($locations'),'role page does not eagerly render location options');
        $this->same(false,str_contains($scopeView,'foreach ($locations'),'scope page does not eagerly render location options');
        $this->same(true,str_contains($roleView,'assignment-locations'),'role page uses the bounded server-side location lookup');
    }

    private function activationCases(UserAccessManagementService $policy,string $districtActor,string $nationalActor,string $districtX,string $districtY):void
    {
        $targets=$this->pdo->query("SELECT su.id FROM system_user su JOIN legacy_user_reference lur ON lur.system_user_id=su.id WHERE su.identity_type='HISTORICAL' AND su.enabled=0 ORDER BY su.id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        $this->same(2,count($targets),'historical activation fixtures are available');$service=new OperationalUserActivationService($this->pdo);
        $base=['email'=>null,'temporary_password'=>'Secure-Manager-1!','effective_from'=>date('Y-m-d'),'reason'=>'User management hierarchy test','official_reference'=>'TEST/UM'];
        $service->activate((string)$targets[0],$base+['username'=>'district.activation.'.substr(str_replace('-','',(string)$targets[0]),0,8),'role_enabled'=>['DISTRICT_VIEWER'],'roles'=>['DISTRICT_VIEWER'=>$districtX]],$districtActor);
        $this->same('ACTIVE',(string)$this->value('SELECT account_status FROM system_user WHERE id=?',[(string)$targets[0]]),'District Admin activates permitted user in own District');
        $this->throws(fn()=>$service->activate((string)$targets[1],$base+['username'=>'blocked.activation.'.substr(str_replace('-','',(string)$targets[1]),0,8),'role_enabled'=>['DISTRICT_VIEWER'],'roles'=>['DISTRICT_VIEWER'=>$districtY]],$districtActor),'District activation rejects another District POST');
        $service->activate((string)$targets[1],$base+['username'=>'national.activation.'.substr(str_replace('-','',(string)$targets[1]),0,8),'role_enabled'=>['DISTRICT_VIEWER'],'roles'=>['DISTRICT_VIEWER'=>$districtY]],$nationalActor);
        $this->same('ACTIVE',(string)$this->value('SELECT account_status FROM system_user WHERE id=?',[(string)$targets[1]]),'National Admin activates permitted District user');
    }

    private function createActor(string $roleCode,?string $locationId,string $name,int $active=1,string $status='APPROVED',?string $effectiveTo=null):string
    {
        $user=$this->createUser($name);$this->createAssignment($user,$roleCode,$locationId,$active,$status,$effectiveTo);return $user;
    }

    private function createAssignment(string $userId,string $roleCode,?string $locationId,int $active=1,string $status='APPROVED',?string $effectiveTo=null):string
    {
        $id=$this->uuid();$roleId=$this->roleId($roleCode);$this->pdo->prepare('INSERT INTO user_account_role(id,user_id,role_id,effective_from,effective_to,approval_status,active,reason) VALUES(?,?,?,CURRENT_DATE(),?,?,?,?)')->execute([$id,$userId,$roleId,$effectiveTo,$status,$active,'User access management test']);
        $level=(string)$this->value('SELECT role_level FROM application_role WHERE id=?',[$roleId]);
        if(in_array($level,['NATIONAL','DISTRICT','ASC','ARPA'],true)){$type=['NATIONAL'=>'NATIONAL','DISTRICT'=>'DISTRICT','ASC'=>'ASC','ARPA'=>'ARPA_DIVISION'][$level];$mode=['NATIONAL'=>'NATIONAL','DISTRICT'=>'INCLUDE_CHILDREN','ASC'=>'EXACT','ARPA'=>'EXACT'][$level];$this->pdo->prepare('INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,effective_to,approval_status,active,reason) VALUES(UUID(),?,?,?,?,?,CURRENT_DATE(),?,?,?,?)')->execute([$userId,$id,$type,$mode,$locationId,$effectiveTo,$status,$active,'User access management test']);}
        return $id;
    }

    private function createUser(string $username):string{$id=$this->uuid();$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$id,$username,$username]);return $id;}
    private function roleId(string $code):string{$id=$this->value('SELECT id FROM application_role WHERE role_code=?',[$code]);if(!$id)throw new RuntimeException("Role {$code} missing");return (string)$id;}
    private function systemAdmin():string{$id=$this->value("SELECT su.id FROM system_user su JOIN user_account_role uar ON uar.user_id=su.id JOIN application_role r ON r.id=uar.role_id WHERE r.role_code='SYSTEM_ADMIN' AND uar.active=1 AND uar.approval_status='APPROVED' LIMIT 1");if(!$id)throw new RuntimeException('SYSTEM_ADMIN fixture missing');return (string)$id;}

    /** @return array{0:string,1:string,2:string,3:string} */
    private function districtFixtures():array
    {
        $sql="WITH RECURSIVE tree(root_id,id) AS (SELECT l.id,l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='DISTRICT' WHERE l.approval_status='APPROVED' UNION DISTINCT SELECT t.root_id,lr.child_location_id FROM tree t JOIN location_relationship lr ON lr.parent_location_id=t.id WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=CURRENT_DATE() AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())) SELECT t.root_id,MIN(l.id) asc_id FROM tree t JOIN location l ON l.id=t.id JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC' GROUP BY t.root_id ORDER BY t.root_id LIMIT 2";
        $rows=$this->pdo->query($sql)->fetchAll();if(count($rows)<2)throw new RuntimeException('Two District/ASC fixtures are required.');return [(string)$rows[0]['root_id'],(string)$rows[0]['asc_id'],(string)$rows[1]['root_id'],(string)$rows[1]['asc_id']];
    }

    private function state():array{return ['users'=>$this->count('SELECT COUNT(*) FROM system_user'),'roles'=>$this->count('SELECT COUNT(*) FROM user_account_role'),'scopes'=>$this->count('SELECT COUNT(*) FROM user_account_scope'),'events'=>$this->count('SELECT COUNT(*) FROM audit_event')];}
    private function value(string $sql,array $params=[]):mixed{$stmt=$this->pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchColumn();}
    private function count(string $sql,array $params=[]):int{return (int)$this->value($sql,$params);}
    private function questions():int{$row=$this->pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch();return (int)($row['Value']??$row[1]??0);}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function pdoThrows(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(PDOException){return;}throw new RuntimeException($message.': expected PDOException');}
    private function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
}

exit((new UserAccessManagementTest())->run());
