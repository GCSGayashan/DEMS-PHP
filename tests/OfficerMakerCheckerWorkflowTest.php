<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database};
use App\Services\{OfficerWorkflowService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class OfficerMakerCheckerWorkflowTest
{
    private PDO $pdo;private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->pdo->beginTransaction();
        try{$this->testDistrictAndNationalFlows();$this->testCodeContracts();}
        finally{if($this->pdo->inTransaction())$this->pdo->rollBack();$_SESSION=[];Auth::forgetRequestCache();}
        echo "OfficerMakerCheckerWorkflowTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function testDistrictAndNationalFlows():void
    {
        $districts=$this->pdo->query("SELECT l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='DISTRICT' WHERE l.approval_status='APPROVED' AND l.operational_status='ACTIVE' ORDER BY l.id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if(count($districts)!==2)throw new RuntimeException('Two District fixtures are required.');
        [$districtA,$districtB]=array_map('strval',$districts);
        $districtMaker=$this->actor('DISTRICT_SUBJECT_OFFICER',$districtA);$districtChecker=$this->actor('DISTRICT_ADMIN',$districtA);$otherChecker=$this->actor('DISTRICT_ADMIN',$districtB);
        $nationalMaker=$this->actor('NATIONAL_SUBJECT_OFFICER',null);$nationalChecker=$this->actor('NATIONAL_ADMIN',null);
        $systemMaker=$this->actor('SYSTEM_ADMIN',null);$systemChecker=$this->actor('SYSTEM_ADMIN',null);
        $service=new OfficerWorkflowService($this->pdo);

        $this->useContext($districtMaker);
        $this->same(true,Auth::can('officer.create'),'District Subject Officer can create Officers');
        $this->same(true,Auth::can('officer.submit'),'District Subject Officer can submit Officers');
        $this->same(false,Auth::can('officer.approve'),'District Subject Officer cannot approve Officers');
        $districtContext=$service->creationContext($districtMaker['user']);
        $this->same($districtA,$districtContext['scope_location_id'],'District maker snapshot uses the active District context');
        $districtOfficer=$this->officer($districtMaker['user'],'DISTRICT_SUBJECT_OFFICER',$districtA,'SUBMITTED');
        $this->same(true,$service->canAccess($districtOfficer,$districtMaker['user']),'District maker can review their submitted Officer');
        $this->throws(fn()=>$service->approve($districtOfficer,$districtMaker['user']),'maker cannot self-approve');
        $queueDad=(string)$this->value('SELECT dad_number FROM officer WHERE id=?',[$districtOfficer]);
        $queue=(new DataTableQuery($this->pdo,DataTableRegistry::definition('officer-workflow'),new DataTableRequest(['length'=>25,'search'=>['value'=>$queueDad]])))->response();
        $this->same(1,$queue['recordsFiltered'],'maker workflow queue contains their submission');

        $office=(string)$this->value("SELECT id FROM office WHERE linked_location_id=? AND approval_status='APPROVED' AND operational_status='ACTIVE' LIMIT 1",[$districtA]);
        if($office==='')throw new RuntimeException('District Office fixture is required.');
        $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,is_primary,active,approval_status,reason) VALUES(UUID(),?,?,CURRENT_DATE(),1,1,'APPROVED','Maker-checker test')")->execute([$districtOfficer,$office]);
        $directory=DataTableRegistry::definition('officers');
        $before=(new DataTableQuery($this->pdo,$directory,new DataTableRequest(['length'=>25,'search'=>['value'=>$this->value('SELECT dad_number FROM officer WHERE id=?',[$districtOfficer])]])))->response();
        $this->same(0,$before['recordsFiltered'],'unapproved Officer is excluded from the normal directory even with an Office assignment');

        $this->useContext($otherChecker);
        $this->same(false,$service->canAccess($districtOfficer,$otherChecker['user']),'another District cannot view the submission');
        $this->throws(fn()=>$service->approve($districtOfficer,$otherChecker['user']),'another District cannot approve the submission');

        $this->useContext($districtChecker);
        $this->same(true,Auth::can('officer.approve'),'District Admin receives Officer approval permission');
        $this->same(true,$service->canAccess($districtOfficer,$districtChecker['user']),'same-District Admin can review the submission');
        $service->approve($districtOfficer,$districtChecker['user']);
        $this->same('APPROVED',$this->value('SELECT approval_status FROM officer WHERE id=?',[$districtOfficer]),'same-District Admin approves the canonical Officer');
        $after=(new DataTableQuery($this->pdo,DataTableRegistry::definition('officers'),new DataTableRequest(['length'=>25,'search'=>['value'=>$this->value('SELECT dad_number FROM officer WHERE id=?',[$districtOfficer])]])))->response();
        $this->same(1,$after['recordsFiltered'],'approved scoped Officer appears in the normal directory');

        $returned=$this->officer($districtMaker['user'],'DISTRICT_SUBJECT_OFFICER',$districtA,'SUBMITTED');
        $service->returnForCorrection($returned,'Correct the Officer details.',$districtChecker['user']);
        $this->same('DRAFT',$this->value('SELECT approval_status FROM officer WHERE id=?',[$returned]),'return uses the same Officer record as a draft');
        $this->useContext($districtMaker);$service->assertEditable($returned,$districtMaker['user']);$service->submit($returned,$districtMaker['user']);
        $this->same('SUBMITTED',$this->value('SELECT approval_status FROM officer WHERE id=?',[$returned]),'maker corrects and resubmits the same Officer ID');
        $this->same(1,(int)$this->value("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='workflow.return'",[$returned]),'return action is audited');

        $this->useContext($nationalMaker);
        $this->same(true,Auth::can('officer.create')&&Auth::can('officer.submit'),'National Subject Officer can create and submit Officers');
        $this->same(false,Auth::can('officer.approve'),'National Subject Officer cannot approve Officers');
        $nationalOfficer=$this->officer($nationalMaker['user'],'NATIONAL_SUBJECT_OFFICER',null,'SUBMITTED');
        $this->useContext($nationalChecker);$this->same(true,$service->canAccess($nationalOfficer,$nationalChecker['user']),'National Admin can review National submission');$service->approve($nationalOfficer,$nationalChecker['user']);
        $this->same('APPROVED',$this->value('SELECT approval_status FROM officer WHERE id=?',[$nationalOfficer]),'National Admin approves National submission');

        $this->useContext($systemMaker);$systemOfficer=$this->officer($systemMaker['user'],null,null,'SUBMITTED');
        $this->useContext($systemChecker);$service->approve($systemOfficer,$systemChecker['user']);
        $this->same('APPROVED',$this->value('SELECT approval_status FROM officer WHERE id=?',[$systemOfficer]),'SYSTEM_ADMIN legacy Officer approval behavior remains available');
    }

    private function testCodeContracts():void
    {
        $controller=(string)file_get_contents(BASE_PATH.'/app/Controllers/OfficerController.php');$view=(string)file_get_contents(BASE_PATH.'/app/Views/officers/index.php');$routes=(string)file_get_contents(BASE_PATH.'/routes/web.php');
        $this->same(true,str_contains($controller,'OfficerWorkflowService')&&str_contains($controller,'returnForCorrection'),'Officer controller uses scoped workflow service for writes');
        $this->same(true,str_contains($view,'Officer Workflow')&&str_contains($view,'Add Officer'),'existing Officer module contains maker and checker UI');
        $this->same(true,str_contains($routes,"/hr/officers/{id}/return"),'Officer return route is registered');
        $this->same(true,in_array("o.approval_status='APPROVED'",DataTableRegistry::definition('officers')['baseWhere'],true),'normal Officer directory explicitly requires approval');
    }

    private function actor(string $roleCode,?string $locationId):array
    {
        $user=$this->uuid();$username='mc'.substr(str_replace('-','',$user),0,18);$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$user,$username,$username]);
        $role=(string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$roleCode]);$level=(string)$this->value('SELECT role_level FROM application_role WHERE id=?',[$role]);$roleAssignment=$this->uuid();$this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(?,?,?,CURRENT_DATE(),'APPROVED',1,'Maker-checker test')")->execute([$roleAssignment,$user,$role]);
        $scope=null;if($level!=='SYSTEM'){$scope=$this->uuid();$type=$level==='NATIONAL'?'NATIONAL':'DISTRICT';$mode=$level==='NATIONAL'?'NATIONAL':'INCLUDE_CHILDREN';$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(?,?,?,?,?,?,CURRENT_DATE(),'APPROVED',1,'Maker-checker test')")->execute([$scope,$user,$roleAssignment,$type,$mode,$locationId]);}
        return ['user'=>$user,'role'=>$roleCode,'role_assignment'=>$roleAssignment,'scope_assignment'=>$scope];
    }

    private function useContext(array $actor):void
    {
        $_SESSION=['user_id'=>$actor['user'],'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();(new UserContextService($this->pdo))->select($actor['user'],$actor['role_assignment'],$actor['scope_assignment']);Auth::forgetRequestCache();
    }

    private function officer(string $creator,?string $originRole,?string $scope,string $status):string
    {
        $id=$this->uuid();$dad='MC-'.substr(str_replace('-','',$id),0,16);$officerStatus=(string)$this->pdo->query('SELECT id FROM officer_status WHERE active=1 LIMIT 1')->fetchColumn();
        $this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,officer_status_id,effective_from,operational_status,approval_status,created_by,submitted_by,submitted_at,workflow_origin_role_code,workflow_scope_location_id) VALUES(?,?,?, ?,CURRENT_DATE(),'INACTIVE',?,?,?,NOW(),?,?)")->execute([$id,$dad,'Maker Checker Officer',$officerStatus,$status,$creator,$creator,$originRole,$scope]);return $id;
    }

    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $call,string $message):void{$this->assertions++;try{$call();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
}

exit((new OfficerMakerCheckerWorkflowTest())->run());
