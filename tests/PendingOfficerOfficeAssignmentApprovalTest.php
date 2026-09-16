<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database,ScopeService};
use App\Services\{OfficerOfficeAssignmentService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class PendingOfficerOfficeAssignmentApprovalTest
{
    private PDO $pdo;
    private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->pdo->beginTransaction();
        try{$this->testTargetOfficeScopedApproval();$this->testCodeContracts();}
        finally{if($this->pdo->inTransaction())$this->pdo->rollBack();$_SESSION=[];Auth::forgetRequestCache();}
        echo "PendingOfficerOfficeAssignmentApprovalTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function testTargetOfficeScopedApproval():void
    {
        $districts=$this->pdo->query("SELECT o.linked_location_id FROM office o JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='DISTRICT_OFFICE' WHERE o.approval_status='APPROVED' AND o.operational_status='ACTIVE' ORDER BY o.dad_number LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if(count($districts)!==2)throw new RuntimeException('Two District Office fixtures are required.');
        $districtA=$this->actor('DISTRICT_ADMIN',(string)$districts[0]);$districtB=$this->actor('DISTRICT_ADMIN',(string)$districts[1]);
        $this->useContext($districtA);$officeA=$this->firstAscOffice($districtA['user']);
        $this->useContext($districtB);$officeB=$this->firstAscOffice($districtB['user']);
        $ascA=$this->actor('ASC_ADMIN',(string)$this->value('SELECT linked_location_id FROM office WHERE id=?',[$officeA]));$systemMaker=$this->actor('SYSTEM_ADMIN',null);
        $service=new OfficerOfficeAssignmentService($this->pdo);$today=date('Y-m-d');

        $officer=$this->officer();$this->useContext($systemMaker);
        $assignment=$service->create(['officer_id'=>$officer,'office_id'=>$officeA,'effective_from'=>$today,'reason'=>'Pending target Office approval test'],$systemMaker['user']);
        $this->same(0,(int)$this->value('SELECT active FROM officer_office_assignment WHERE id=?',[$assignment]),'submitted assignment is explicitly non-operational');
        $this->same(null,$this->value('SELECT primary_office_id FROM officer WHERE id=?',[$officer]),'submitted assignment does not set Officer primary Office');

        $this->useContext($ascA);
        $this->same(true,Auth::can('officer.office-assignment.approve'),'ASC Admin active context has Office-assignment approval permission');
        $queue=$this->queue($this->dad($officer));$this->same(1,$queue['recordsFiltered'],'submitted assignment is visible to the Admin responsible for its target ASC');
        $this->same($assignment,$queue['data'][0]['actions']!==''?$assignment:'','queue exposes a review action without requiring current Officer visibility');
        $directory=(new DataTableQuery($this->pdo,DataTableRegistry::definition('officers'),new DataTableRequest(['length'=>25,'search'=>['value'=>$this->dad($officer)]])))->response();
        $this->same(0,$directory['recordsFiltered'],'normal Officer Directory still excludes an Officer without an approved current Office assignment');
        $review=$service->reviewForApproval($assignment,$ascA['user']);$this->same($assignment,$review['id'],'direct review resolves the submitted row through target Office scope');
        $this->same([],$review['current_offices'],'limited review does not invent an existing current Office');
        $service->approve($assignment,$ascA['user']);
        $this->same(1,(int)$this->value("SELECT COUNT(*) FROM officer_office_assignment WHERE id=? AND approval_status='APPROVED' AND active=1",[$assignment]),'approval updates and activates the same assignment row');
        $this->same(1,(int)$this->value('SELECT is_primary FROM officer_office_assignment WHERE id=?',[$assignment]),'first current approved Office assignment becomes primary');
        $this->same($officeA,$this->value('SELECT primary_office_id FROM officer WHERE id=?',[$officer]),'Officer primary Office pointer is synchronized on approval');
        $this->same(1,(int)$this->value("SELECT COUNT(*) FROM officer_office_assignment_audit WHERE assignment_id=? AND action_key='APPROVED'",[$assignment]),'approval is append-only audited');

        $otherOfficer=$this->officer();$this->useContext($systemMaker);$outside=$service->create(['officer_id'=>$otherOfficer,'office_id'=>$officeB,'effective_from'=>$today,'reason'=>'Outside ASC target test'],$systemMaker['user']);
        $this->useContext($ascA);$this->same(0,$this->queue($this->dad($otherOfficer))['recordsFiltered'],'ASC Admin queue excludes another ASC target Office');
        $this->throws(fn()=>$service->reviewForApproval($outside,$ascA['user']),'forged direct review outside target Office scope is rejected');
        $this->throws(fn()=>$service->approve($outside,$ascA['user']),'forged direct approval outside target Office scope is rejected');

        $districtOfficer=$this->officer();$this->useContext($systemMaker);$districtPending=$service->create(['officer_id'=>$districtOfficer,'office_id'=>$officeA,'effective_from'=>$today,'reason'=>'District queue target test'],$systemMaker['user']);
        $this->useContext($districtA);$this->same(1,$this->queue($this->dad($districtOfficer))['recordsFiltered'],'District Admin sees a pending target Office within the District descendants');
        $this->same($districtPending,$service->reviewForApproval($districtPending,$districtA['user'])['id'],'District Admin can review the in-District target Office');
        $this->useContext($districtB);$this->same(0,$this->queue($this->dad($districtOfficer))['recordsFiltered'],'District Admin queue excludes another District target Office');

        $makerOfficer=$this->officer();$this->useContext($ascA);$own=$service->create(['officer_id'=>$makerOfficer,'office_id'=>$officeA,'effective_from'=>$today,'reason'=>'Maker-checker queue test'],$ascA['user']);
        $this->same(0,$this->queue($this->dad($makerOfficer))['recordsFiltered'],'maker is not offered their own submitted assignment for approval');
        $this->throws(fn()=>$service->reviewForApproval($own,$ascA['user']),'maker cannot open own assignment as an approver');
        $this->throws(fn()=>$service->approve($own,$ascA['user']),'maker cannot approve own assignment');
        $this->same('SUBMITTED',$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$own]),'failed self-approval leaves the pending row unchanged');
    }

    private function testCodeContracts():void
    {
        $routes=(string)file_get_contents(BASE_PATH.'/routes/web.php');$controller=(string)file_get_contents(BASE_PATH.'/app/Controllers/OfficerController.php');$view=(string)file_get_contents(BASE_PATH.'/app/Views/officers/office_assignments/review.php');
        $this->same(true,str_contains($routes,'/hr/officers/office-assignments/{assignmentId}/review')&&str_contains($routes,'/hr/officers/office-assignments/{assignmentId}/approve'),'separate review and approval routes are registered before Officer profile routing');
        $this->same(true,str_contains($controller,"requirePermission('officer.office-assignment.approve')")&&str_contains($controller,'reviewForApproval'),'controller requires approval permission and target-scoped review model');
        $this->same(true,str_contains($view,'Proposed Office Assignment')&&str_contains($view,'Current Offices in Your Working Context'),'direct review exposes only limited decision information');
    }

    private function queue(string $dad):array{return (new DataTableQuery($this->pdo,DataTableRegistry::definition('pending-officer-office-assignments'),new DataTableRequest(['length'=>25,'search'=>['value'=>$dad]])))->response();}
    private function firstAscOffice(string $actor):string{foreach(ScopeService::scopedOffices($actor) as $office)if($office['office_type']==='ASC_OFFICE')return (string)$office['id'];throw new RuntimeException('A scoped ASC Office fixture is required.');}
    private function dad(string $officer):string{return (string)$this->value('SELECT dad_number FROM officer WHERE id=?',[$officer]);}

    private function actor(string $roleCode,?string $locationId):array
    {
        $user=$this->uuid();$username='poa'.substr(str_replace('-','',$user),0,18);$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$user,$username,$username]);
        $role=(string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$roleCode]);$level=(string)$this->value('SELECT role_level FROM application_role WHERE id=?',[$role]);$roleAssignment=$this->uuid();$this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(?,?,?,CURRENT_DATE(),'APPROVED',1,'Pending Office approval test')")->execute([$roleAssignment,$user,$role]);
        $scope=null;if($level!=='SYSTEM'){$scope=$this->uuid();$scopeType=$level==='NATIONAL'?'NATIONAL':$level;$mode=$level==='NATIONAL'?'NATIONAL':($level==='DISTRICT'?'INCLUDE_CHILDREN':'EXACT');$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(?,?,?,?,?,?,CURRENT_DATE(),'APPROVED',1,'Pending Office approval test')")->execute([$scope,$user,$roleAssignment,$scopeType,$mode,$locationId]);}
        return ['user'=>$user,'role_assignment'=>$roleAssignment,'scope_assignment'=>$scope];
    }

    private function useContext(array $actor):void{$_SESSION=['user_id'=>$actor['user'],'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();(new UserContextService($this->pdo))->select($actor['user'],$actor['role_assignment'],$actor['scope_assignment']);Auth::forgetRequestCache();}
    private function officer():string{$id=$this->uuid();$dad='POA-'.substr(str_replace('-','',$id),0,16);$status=(string)$this->pdo->query('SELECT id FROM officer_status WHERE active=1 LIMIT 1')->fetchColumn();$this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,arpa_service_permanency,primary_mobile,officer_status_id,effective_from,operational_status,approval_status) VALUES(?,?,?,'NOT_PERMANENT_IN_SERVICE','+94761187358',?,CURRENT_DATE(),'ACTIVE','APPROVED')")->execute([$id,$dad,'Pending Office Test Officer',$status]);return $id;}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $call,string $message):void{$this->assertions++;try{$call();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
}

exit((new PendingOfficerOfficeAssignmentApprovalTest())->run());
