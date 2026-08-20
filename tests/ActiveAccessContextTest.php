<?php
declare(strict_types=1);

use App\Core\{Auth,Database,ScopeService};
use App\Services\{UserAccessManagementService,UserContextService};

require dirname(__DIR__) . '/bootstrap.php';

final class ActiveAccessContextTest
{
    private PDO $pdo;
    private int $assertions = 0;

    public function run(): int
    {
        $this->pdo = Database::pdo();
        $before = $this->state();
        $this->pdo->beginTransaction();
        try {
            $this->exercise();
        } finally {
            $_SESSION = [];
            Auth::forgetRequestCache();
            $this->pdo->rollBack();
        }
        $this->same($before, $this->state(), 'all context fixtures roll back');
        echo "ActiveAccessContextTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function exercise(): void
    {
        [$districtX,$ascX,$districtY,$ascY] = $this->districtFixtures();
        $service = new UserContextService($this->pdo);

        $multi = $this->createUser('context.multi');
        [$districtRole,$districtScope] = $this->createAssignment($multi,'DISTRICT_ADMIN',$districtX);
        [$ascRole,$ascScope] = $this->createAssignment($multi,'ASC_VIEWER',$ascY);
        $this->authenticateAs($multi);

        $contexts = $service->availableContexts($multi);
        $this->same(2,count($contexts),'multiple role/location assignments produce separate contexts');
        $this->same(null,Auth::activeContext(),'multi-context user is not auto-selected');
        $this->same([],Auth::permissions(),'interactive permissions are denied until a context is selected');
        $this->same('RESTRICTED',ScopeService::scopeProfile($multi)['level'],'interactive scope is denied until a context is selected');

        $service->select($multi,$districtRole,$districtScope);Auth::forgetRequestCache();
        $this->same($districtRole,Auth::activeRoleAssignmentId(),'District role assignment becomes active');
        $this->same($districtScope,Auth::activeScopeAssignmentId(),'District scope assignment becomes active');
        $this->same(true,Auth::can('data.approve'),'District Admin permission is available in District context');
        $this->same(true,ScopeService::canAccessLocationForPermission($multi,'data.approve',$ascX),'District context reaches its descendant ASC');
        $this->same(false,ScopeService::canAccessLocationForPermission($multi,'data.approve',$ascY),'District permission cannot borrow the Viewer scope');
        $authority=(new UserAccessManagementService($this->pdo))->authority($multi);
        $this->same('DISTRICT',$authority['kind'],'User Management authority comes from active District context');
        $this->same([$districtX],$authority['district_ids'],'User Management authority uses only selected District scope');

        $service->select($multi,$ascRole,$ascScope);Auth::forgetRequestCache();
        $this->same(false,Auth::can('data.approve'),'District Admin permission disappears in ASC Viewer context');
        $this->same(true,Auth::can('data.view'),'ASC Viewer permission remains available');
        $this->same(true,ScopeService::canAccessLocationForPermission($multi,'data.view',$ascY),'ASC Viewer reaches its selected ASC');
        $this->same(false,ScopeService::canAccessLocationForPermission($multi,'data.view',$ascX),'ASC Viewer cannot borrow District context geography');
        $this->throws(fn()=>(new UserAccessManagementService($this->pdo))->authority($multi),'ASC Viewer context cannot borrow District User Management authority');

        $nationalMixed=$this->createUser('context.national.mixed');
        [$nationalRole,$nationalScope]=$this->createAssignment($nationalMixed,'NATIONAL_ADMIN',null);
        [$subjectRole,$subjectScope]=$this->createAssignment($nationalMixed,'DISTRICT_SUBJECT_OFFICER',$districtX);
        $this->authenticateAs($nationalMixed);
        $service->select($nationalMixed,$subjectRole,$subjectScope);Auth::forgetRequestCache();
        $this->same(false,Auth::can('user.activate'),'District Subject Officer context cannot borrow National Admin permissions');
        $this->throws(fn()=>(new UserAccessManagementService($this->pdo))->authority($nationalMixed),'District Subject Officer context cannot borrow National management authority');
        $service->select($nationalMixed,$nationalRole,$nationalScope);Auth::forgetRequestCache();
        $this->same(true,Auth::can('user.activate'),'National Admin permission returns after explicit context switch');
        $this->same('NATIONAL',(new UserAccessManagementService($this->pdo))->authority($nationalMixed)['kind'],'National authority returns only in National context');

        $other=$this->createUser('context.other');
        [$otherRole,$otherScope]=$this->createAssignment($other,'ASC_VIEWER',$ascX);
        $this->throws(fn()=>$service->select($nationalMixed,$otherRole,$otherScope),'another user role assignment cannot be selected');
        $this->throws(fn()=>$service->select($multi,$districtRole,$ascScope),'scope from another role assignment cannot be selected');

        $incompatible=$this->createUser('context.incompatible');
        [$incompatibleRole]=$this->createAssignment($incompatible,'ASC_VIEWER',$ascX);
        $this->pdo->prepare("UPDATE user_account_scope SET scope_type='NATIONAL',scope_mode='NATIONAL',location_id=NULL WHERE role_assignment_id=?")->execute([$incompatibleRole]);
        $this->same([], $service->availableContexts($incompatible), 'an ASC role with a National scope cannot become a working context');

        $multiScope=$this->createUser('context.multi.scope');
        [$multiScopeRole,$firstScope]=$this->createAssignment($multiScope,'ASC_VIEWER',$ascX);
        $secondScope=$this->uuid();
        $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(?,?,?,'ASC','EXACT',?,CURRENT_DATE(),'APPROVED',1,'Active context test')")->execute([$secondScope,$multiScope,$multiScopeRole,$ascY]);
        $this->authenticateAs($multiScope);
        $this->same(2,count($service->availableContexts($multiScope)),'multiple valid scopes on one role become separate contexts');
        $service->select($multiScope,$multiScopeRole,$firstScope);Auth::forgetRequestCache();
        $this->same(true,ScopeService::canAccessLocationForPermission($multiScope,'data.view',$ascX),'first same-role scope is usable when selected');
        $this->same(false,ScopeService::canAccessLocationForPermission($multiScope,'data.view',$ascY),'second same-role scope is not merged into the first context');
        $service->select($multiScope,$multiScopeRole,$secondScope);Auth::forgetRequestCache();
        $this->same(true,ScopeService::canAccessLocationForPermission($multiScope,'data.view',$ascY),'second same-role scope becomes usable after explicit switch');

        $unassigned=$this->createUser('context.unavailable');
        $this->authenticateAs($unassigned);
        $this->same([],Auth::availableContexts(),'user without an effective assignment has no available context');
        $this->same(null,Auth::activeContext(),'user without a context remains access-unavailable');

        $single=$this->createUser('context.single');
        [$singleRole,$singleScope]=$this->createAssignment($single,'ASC_VIEWER',$ascX);
        $this->authenticateAs($single);
        $auto=Auth::activeContext();
        $this->same($singleRole,(string)($auto['role_assignment_id']??''),'one-context user is selected automatically');
        $this->same($singleScope,(string)($_SESSION['access_context']['scope_assignment_id']??''),'automatic selection stores only assignment identifiers');

        $this->pdo->prepare("UPDATE user_account_role SET effective_to=DATE_SUB(CURRENT_DATE(),INTERVAL 1 DAY) WHERE id=?")->execute([$singleRole]);
        Auth::forgetRequestCache();
        $this->same(null,Auth::activeContext(false),'expired selected role invalidates the context');
        $this->same(false,isset($_SESSION['access_context']),'invalid role context is removed from session');

        $scopeInvalid=$this->createUser('context.scope.invalid');
        [$scopeInvalidRole,$scopeInvalidScope]=$this->createAssignment($scopeInvalid,'ASC_VIEWER',$ascX);
        $this->authenticateAs($scopeInvalid);$service->select($scopeInvalid,$scopeInvalidRole,$scopeInvalidScope);Auth::forgetRequestCache();
        $this->pdo->prepare("UPDATE user_account_scope SET approval_status='SUBMITTED' WHERE id=?")->execute([$scopeInvalidScope]);
        Auth::forgetRequestCache();
        $this->same(null,Auth::activeContext(false),'unapproved selected scope invalidates the context');

        $disabledScopeUser=$this->createUser('context.scope.disabled');
        [$disabledRole,$disabledScope]=$this->createAssignment($disabledScopeUser,'ASC_VIEWER',$ascX);
        $this->authenticateAs($disabledScopeUser);$service->select($disabledScopeUser,$disabledRole,$disabledScope);Auth::forgetRequestCache();
        $this->pdo->prepare('UPDATE user_account_scope SET active=0 WHERE id=?')->execute([$disabledScope]);Auth::forgetRequestCache();
        $this->same(null,Auth::activeContext(false),'inactive selected scope invalidates the context');

        $view=(string)file_get_contents(dirname(__DIR__).'/app/Views/auth/select_context.php');
        $layout=(string)file_get_contents(dirname(__DIR__).'/app/Views/layouts/admin.php');
        $this->same(true,str_contains($view,'Csrf::field()'),'context selection POST uses CSRF protection');
        $this->same(true,str_contains($layout,'Current Context'),'authenticated header displays active context');
        $this->same(true,str_contains($layout,"url('select-context')"),'authenticated header supports context switching');
        $this->same(true,str_contains((string)file_get_contents(dirname(__DIR__).'/app/Controllers/AuthController.php'),'initializeContextAfterLogin'),'login initializes or requests a working context');
    }

    /** @return array{0:string,1:string} */
    private function createAssignment(string $userId,string $roleCode,?string $locationId):array
    {
        $roleAssignmentId=$this->uuid();$roleId=(string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$roleCode]);
        if($roleId==='')throw new RuntimeException("Role {$roleCode} is missing");
        $this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(?,?,?,CURRENT_DATE(),'APPROVED',1,'Active context test')")->execute([$roleAssignmentId,$userId,$roleId]);
        $level=(string)$this->value('SELECT role_level FROM application_role WHERE id=?',[$roleId]);
        $scopeId=$this->uuid();
        $type=['NATIONAL'=>'NATIONAL','DISTRICT'=>'DISTRICT','ASC'=>'ASC','ARPA'=>'ARPA_DIVISION'][$level]??null;
        if($type===null)return [$roleAssignmentId,''];
        $mode=['NATIONAL'=>'NATIONAL','DISTRICT'=>'INCLUDE_CHILDREN','ASC'=>'EXACT','ARPA'=>'EXACT'][$level];
        $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(?,?,?,?,?,?,CURRENT_DATE(),'APPROVED',1,'Active context test')")->execute([$scopeId,$userId,$roleAssignmentId,$type,$mode,$locationId]);
        return [$roleAssignmentId,$scopeId];
    }

    private function createUser(string $username):string
    {
        $id=$this->uuid();
        $this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$id,$username,$username]);
        return $id;
    }

    private function authenticateAs(string $userId):void
    {
        $_SESSION=['user_id'=>$userId,'authenticated_at'=>time(),'last_activity_at'=>time()];
        Auth::forgetRequestCache();
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private function districtFixtures():array
    {
        $sql="WITH RECURSIVE tree(root_id,id) AS (SELECT l.id,l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='DISTRICT' WHERE l.approval_status='APPROVED' UNION DISTINCT SELECT t.root_id,lr.child_location_id FROM tree t JOIN location_relationship lr ON lr.parent_location_id=t.id WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=CURRENT_DATE() AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())) SELECT t.root_id,MIN(l.id) asc_id FROM tree t JOIN location l ON l.id=t.id JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC' GROUP BY t.root_id ORDER BY t.root_id LIMIT 2";
        $rows=$this->pdo->query($sql)->fetchAll();
        if(count($rows)<2)throw new RuntimeException('Two District/ASC fixtures are required.');
        return [(string)$rows[0]['root_id'],(string)$rows[0]['asc_id'],(string)$rows[1]['root_id'],(string)$rows[1]['asc_id']];
    }

    private function state():array{return ['users'=>$this->count('SELECT COUNT(*) FROM system_user'),'roles'=>$this->count('SELECT COUNT(*) FROM user_account_role'),'scopes'=>$this->count('SELECT COUNT(*) FROM user_account_scope'),'events'=>$this->count('SELECT COUNT(*) FROM audit_event')];}
    private function value(string $sql,array $params=[]):mixed{$stmt=$this->pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchColumn();}
    private function count(string $sql,array $params=[]):int{return (int)$this->value($sql,$params);}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
}

exit((new ActiveAccessContextTest())->run());
