<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database,ScopeService};
use App\Services\{OfficerOfficeAssignmentService,OfficerPersonnelValidator,OfficerProfileService,OfficerWorkflowService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class OfficerMakerCheckerWorkflowTest
{
    private PDO $pdo;private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->pdo->beginTransaction();
        try{$this->testServicePermanencyValidation();$this->testDistrictAndNationalFlows();$this->testCodeContracts();}
        finally{if($this->pdo->inTransaction())$this->pdo->rollBack();$_SESSION=[];Auth::forgetRequestCache();}
        echo "OfficerMakerCheckerWorkflowTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function testServicePermanencyValidation():void
    {
        $this->throws(fn()=>OfficerPersonnelValidator::servicePermanency(null,null),'missing Service Permanency is rejected');
        $this->throws(fn()=>OfficerPersonnelValidator::servicePermanency('PERMANENT_IN_SERVICE',null),'Permanent In Service without Permanented Date is rejected');
        $valid=OfficerPersonnelValidator::servicePermanency('PERMANENT_IN_SERVICE','2020-05-06');
        $this->same('PERMANENT_IN_SERVICE',$valid['arpa_service_permanency'],'Permanent In Service with a valid Permanented Date is accepted');
        $this->same('2020-05-06',$valid['service_permanented_date'],'valid Permanented Date is retained');
        $other=OfficerPersonnelValidator::servicePermanency('NOT_PERMANENT_IN_SERVICE',null);
        $this->same('NOT_PERMANENT_IN_SERVICE',$other['arpa_service_permanency'],'other valid Service Permanency is accepted without a date');
        $this->same(null,$other['service_permanented_date'],'Permanented Date remains optional for a non-permanent Officer');
        $this->throws(fn()=>OfficerPersonnelValidator::servicePermanency('PERMANENT_IN_SERVICE','2025-02-30'),'invalid Permanented Date is rejected');
        $this->throws(fn()=>OfficerPersonnelValidator::servicePermanency('PERMANENT_IN_SERVICE',null),'edit cannot remove Permanented Date while Service Permanency remains Permanent In Service');
        $changed=OfficerPersonnelValidator::servicePermanency('NOT_PERMANENT_IN_SERVICE','2020-05-06');
        $this->same(null,$changed['service_permanented_date'],'changing away from Permanent In Service clears the current Permanented Date');

        $telephoneOnly=OfficerPersonnelValidator::contactNumbers('+94761187358',null);
        $this->same('+94761187358',$telephoneOnly['primary_mobile'],'Telephone Number alone is accepted');
        $this->same(null,$telephoneOnly['alternative_mobile'],'blank WhatsApp Number remains null');
        $whatsAppOnly=OfficerPersonnelValidator::contactNumbers(null,'+94758581250');
        $this->same(null,$whatsAppOnly['primary_mobile'],'blank Telephone Number remains null');
        $this->same('+94758581250',$whatsAppOnly['alternative_mobile'],'WhatsApp Number alone is accepted');
        $both=OfficerPersonnelValidator::contactNumbers('+94761187358','+94758581250');
        $this->same(['primary_mobile'=>'+94761187358','alternative_mobile'=>'+94758581250'],$both,'both valid contact numbers are accepted');
        $this->throws(fn()=>OfficerPersonnelValidator::contactNumbers(null,null),'both contact numbers blank is rejected');
        $this->throws(fn()=>OfficerPersonnelValidator::contactNumbers('076-invalid',null),'invalid supplied Telephone Number is rejected');
        $this->throws(fn()=>OfficerPersonnelValidator::contactNumbers(null,'075-invalid'),'invalid supplied WhatsApp Number is rejected');
    }

    private function testDistrictAndNationalFlows():void
    {
        $districts=$this->pdo->query("SELECT l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='DISTRICT' WHERE l.approval_status='APPROVED' AND l.operational_status='ACTIVE' ORDER BY l.id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if(count($districts)!==2)throw new RuntimeException('Two District fixtures are required.');
        [$districtA,$districtB]=array_map('strval',$districts);
        $districtMaker=$this->actor('DISTRICT_SUBJECT_OFFICER',$districtA);$districtChecker=$this->actor('DISTRICT_ADMIN',$districtA);$otherChecker=$this->actor('DISTRICT_ADMIN',$districtB);
        $nationalMaker=$this->actor('NATIONAL_SUBJECT_OFFICER',null);$nationalChecker=$this->actor('NATIONAL_ADMIN',null);
        $systemMaker=$this->actor('SYSTEM_ADMIN',null);$systemChecker=$this->actor('SYSTEM_ADMIN',null);
        $service=new OfficerWorkflowService($this->pdo);$officeService=new OfficerOfficeAssignmentService($this->pdo);

        $this->useContext($districtMaker);
        $this->same(true,Auth::can('officer.create'),'District Subject Officer can create Officers');
        $this->same(true,Auth::can('officer.submit'),'District Subject Officer can submit Officers');
        $this->same(false,Auth::can('officer.approve'),'District Subject Officer cannot approve Officers');
        $districtContext=$service->creationContext($districtMaker['user']);
        $this->same($districtA,$districtContext['scope_location_id'],'District maker snapshot uses the active District context');
        $districtOffices=ScopeService::scopedOffices($districtMaker['user']);
        $this->same(true,count($districtOffices)>=2,'District Subject Officer receives District-scoped Office options');
        $office=(string)$districtOffices[0]['id'];$replacementOffice=(string)$districtOffices[1]['id'];
        $otherDistrictOffice=(string)$this->value("SELECT id FROM office WHERE linked_location_id=? AND approval_status='APPROVED' AND operational_status='ACTIVE' LIMIT 1",[$districtB]);
        $this->same(false,ScopeService::canAccessOffice($districtMaker['user'],$otherDistrictOffice),'District Office selector excludes another District');
        $permanency='PERMANENT_IN_SERVICE';$permanentedDate='2020-05-06';
        $districtOfficer=$this->officer($districtMaker['user'],'DISTRICT_SUBJECT_OFFICER',$districtA,'SUBMITTED',$permanency,$permanentedDate);
        $initialAssignment=$officeService->saveInitialForOfficer($districtOfficer,$office,date('Y-m-d'),$districtMaker['user']);
        $pendingProfile=(new OfficerProfileService($this->pdo))->profile($districtOfficer);
        $this->same($permanency,$pendingProfile['officer']['arpa_service_permanency'],'submitted Officer profile retains Service Permanency');
        $this->same($permanentedDate,$pendingProfile['officer']['service_permanented_date'],'submitted Officer profile retains Permanented Date');
        $this->same(null,$this->value('SELECT photograph_path FROM officer WHERE id=?',[$districtOfficer]),'Officer can be created and submitted without a photograph');
        $this->same('SUBMITTED',$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$initialAssignment]),'initial Office assignment is submitted with the Officer');
        $this->same(0,(int)$this->value("SELECT COUNT(*) FROM officer_office_assignment WHERE id=? AND approval_status='APPROVED'",[$initialAssignment]),'pending Officer Office assignment is not operational');
        $crossDistrictOfficer=$this->officer($districtMaker['user'],'DISTRICT_SUBJECT_OFFICER',$districtA,'SUBMITTED');
        $this->throws(fn()=>$officeService->saveInitialForOfficer($crossDistrictOfficer,$otherDistrictOffice,date('Y-m-d'),$districtMaker['user']),'cross-District initial Office selection is rejected server-side');
        $this->same(true,$service->canAccess($districtOfficer,$districtMaker['user']),'District maker can review their submitted Officer');
        $this->throws(fn()=>$service->approve($districtOfficer,$districtMaker['user']),'maker cannot self-approve');
        $queueDad=(string)$this->value('SELECT dad_number FROM officer WHERE id=?',[$districtOfficer]);
        $queue=(new DataTableQuery($this->pdo,DataTableRegistry::definition('officer-workflow'),new DataTableRequest(['length'=>25,'search'=>['value'=>$queueDad]])))->response();
        $this->same(1,$queue['recordsFiltered'],'maker workflow queue contains their submission');

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
        $approvedProfile=(new OfficerProfileService($this->pdo))->profile($districtOfficer);
        $this->same($permanency,$approvedProfile['officer']['arpa_service_permanency'],'approval preserves Service Permanency');
        $this->same($permanentedDate,$approvedProfile['officer']['service_permanented_date'],'approval preserves Permanented Date');
        $this->same(null,$this->value('SELECT photograph_path FROM officer WHERE id=?',[$districtOfficer]),'Officer approval does not require a photograph');
        $this->same('APPROVED',$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$initialAssignment]),'Officer approval atomically approves the initial Office assignment');
        $this->same($office,$this->value('SELECT primary_office_id FROM officer WHERE id=?',[$districtOfficer]),'approved current initial Office is synchronized as Primary Office');
        $after=(new DataTableQuery($this->pdo,DataTableRegistry::definition('officers'),new DataTableRequest(['length'=>25,'search'=>['value'=>$this->value('SELECT dad_number FROM officer WHERE id=?',[$districtOfficer])]])))->response();
        $this->same(1,$after['recordsFiltered'],'approved scoped Officer appears in the normal directory');

        $returned=$this->officer($districtMaker['user'],'DISTRICT_SUBJECT_OFFICER',$districtA,'SUBMITTED',$permanency,$permanentedDate);
        $returnedAssignment=$officeService->saveInitialForOfficer($returned,$office,date('Y-m-d'),$districtMaker['user']);
        $service->returnForCorrection($returned,'Correct the Officer details.',$districtChecker['user']);
        $this->same('DRAFT',$this->value('SELECT approval_status FROM officer WHERE id=?',[$returned]),'return uses the same Officer record as a draft');
        $this->same('RETURNED',$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$returnedAssignment]),'initial Office assignment is returned with its Officer');
        $this->useContext($districtMaker);$service->assertEditable($returned,$districtMaker['user']);
        $this->pdo->prepare('UPDATE officer SET service_permanented_date=NULL WHERE id=?')->execute([$returned]);
        $this->throws(fn()=>$service->submit($returned,$districtMaker['user']),'returned Permanent In Service Officer cannot be resubmitted without Permanented Date');
        $this->same('DRAFT',$this->value('SELECT approval_status FROM officer WHERE id=?',[$returned]),'failed resubmission leaves the returned Officer in Draft');
        $this->pdo->prepare('UPDATE officer SET service_permanented_date=? WHERE id=?')->execute([$permanentedDate,$returned]);
        $this->pdo->prepare('UPDATE officer SET primary_mobile=NULL,alternative_mobile=NULL WHERE id=?')->execute([$returned]);
        $this->throws(fn()=>$service->submit($returned,$districtMaker['user']),'returned Officer cannot be resubmitted with both contact numbers blank');
        $this->same('DRAFT',$this->value('SELECT approval_status FROM officer WHERE id=?',[$returned]),'failed contact validation leaves the returned Officer in Draft');
        $this->pdo->prepare('UPDATE officer SET primary_mobile=? WHERE id=?')->execute(['+94761187358',$returned]);
        $sameAssignment=$officeService->saveInitialForOfficer($returned,$replacementOffice,date('Y-m-d'),$districtMaker['user']);$this->same($returnedAssignment,$sameAssignment,'returned Officer changes the same initial Office assignment');$this->same(1,(int)$this->value('SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id=? AND reason=?',[$returned,OfficerOfficeAssignmentService::INITIAL_OFFICER_REASON]),'Office correction creates no duplicate initial assignment');$service->submit($returned,$districtMaker['user']);
        $this->same('SUBMITTED',$this->value('SELECT approval_status FROM officer WHERE id=?',[$returned]),'maker corrects and resubmits the same Officer ID');
        $this->same($permanency,$this->value('SELECT arpa_service_permanency FROM officer WHERE id=?',[$returned]),'return and resubmission preserve Service Permanency');
        $this->same($permanentedDate,$this->value('SELECT service_permanented_date FROM officer WHERE id=?',[$returned]),'return and resubmission preserve Permanented Date');
        $this->same('SUBMITTED',$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$returnedAssignment]),'corrected initial Office assignment is resubmitted with the Officer');
        $this->same(1,(int)$this->value("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='workflow.return'",[$returned]),'return action is audited');

        $this->useContext($nationalMaker);
        $this->same(true,Auth::can('officer.create')&&Auth::can('officer.submit'),'National Subject Officer can create and submit Officers');
        $this->same(false,Auth::can('officer.approve'),'National Subject Officer cannot approve Officers');
        $nationalOfficer=$this->officer($nationalMaker['user'],'NATIONAL_SUBJECT_OFFICER',null,'SUBMITTED');
        $nationalOffices=ScopeService::scopedOffices($nationalMaker['user']);$this->same(true,count($nationalOffices)>0,'National Subject Officer receives National-context Office options');$nationalAssignment=$officeService->saveInitialForOfficer($nationalOfficer,(string)$nationalOffices[0]['id'],date('Y-m-d'),$nationalMaker['user']);
        $this->useContext($nationalChecker);$this->same(true,$service->canAccess($nationalOfficer,$nationalChecker['user']),'National Admin can review National submission');$service->approve($nationalOfficer,$nationalChecker['user']);
        $this->same('APPROVED',$this->value('SELECT approval_status FROM officer WHERE id=?',[$nationalOfficer]),'National Admin approves National submission');
        $this->same('APPROVED',$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$nationalAssignment]),'National approval activates the selected initial Office assignment');

        $this->useContext($systemMaker);$systemOfficer=$this->officer($systemMaker['user'],null,null,'SUBMITTED');$systemAssignment=$officeService->saveInitialForOfficer($systemOfficer,(string)$nationalOffices[0]['id'],date('Y-m-d'),$systemMaker['user']);
        $this->useContext($systemChecker);$service->approve($systemOfficer,$systemChecker['user']);
        $this->same('APPROVED',$this->value('SELECT approval_status FROM officer WHERE id=?',[$systemOfficer]),'SYSTEM_ADMIN legacy Officer approval behavior remains available');
        $this->same('APPROVED',$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$systemAssignment]),'SYSTEM_ADMIN initial Office follows the existing Officer approval path');
        $optional=$this->officer($systemMaker['user'],null,null,'SUBMITTED');$service->approve($optional,$systemChecker['user']);$this->same(0,(int)$this->value('SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id=?',[$optional]),'Officer creation remains valid without an initial Office');
    }

    private function testCodeContracts():void
    {
        $controller=(string)file_get_contents(BASE_PATH.'/app/Controllers/OfficerController.php');$workflow=(string)file_get_contents(BASE_PATH.'/app/Services/OfficerWorkflowService.php');$personnelValidator=(string)file_get_contents(BASE_PATH.'/app/Services/OfficerPersonnelValidator.php');$view=(string)file_get_contents(BASE_PATH.'/app/Views/officers/index.php');$form=(string)file_get_contents(BASE_PATH.'/app/Views/officers/form.php');$edit=(string)file_get_contents(BASE_PATH.'/app/Views/officers/edit.php');$show=(string)file_get_contents(BASE_PATH.'/app/Views/officers/show.php');$routes=(string)file_get_contents(BASE_PATH.'/routes/web.php');
        $this->same(true,str_contains($controller,'OfficerWorkflowService')&&str_contains($controller,'returnForCorrection'),'Officer controller uses scoped workflow service for writes');
        $this->same(true,str_contains($view,'Officer Workflow')&&str_contains($view,'Add Officer'),'existing Officer module contains maker and checker UI');
        $this->same(true,str_contains($routes,"/hr/officers/{id}/return"),'Officer return route is registered');
        $this->same(true,in_array("o.approval_status='APPROVED'",DataTableRegistry::definition('officers')['baseWhere'],true),'normal Officer directory explicitly requires approval');
        $this->same(true,str_contains($form,'JPG/PNG, max 5 MB (Optional)')&&!preg_match('/name="photograph"[^>]*\brequired\b/',$form),'Officer create form marks photograph optional');
        $this->same(false,str_contains($controller,'Officer photograph is required.'),'Officer creation has no mandatory-photograph validation');
        $this->same(true,str_contains($controller,"UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE")&&str_contains($controller,"'image/jpeg'=>'jpg'")&&str_contains($controller,"'image/png'=>'png'")&&str_contains($controller,'move_uploaded_file'),'optional valid JPG/PNG upload retains secure storage path');
        $this->same(true,str_contains($controller,'Photograph must be JPG/JPEG or PNG.'),'invalid photograph types remain rejected');
        $this->same(true,str_contains($controller,'Photograph must be 5 MB or smaller.'),'photographs larger than 5 MB remain rejected');
        $this->same(true,str_contains($edit,'Optional. Upload only to replace current photograph.')&&!preg_match('/name="photograph"[^>]*\brequired\b/',$edit),'edit keeps an existing photograph when no replacement is uploaded');
        $this->same(true,str_contains($form,'Office Assignment')&&str_contains($form,'name="initial_office_id"')&&str_contains($form,'name="office_effective_from"'),'Officer create form includes optional initial Office fields');
        $this->same(true,str_contains($controller,'ScopeService::scopedOffices($actor)')&&str_contains($controller,'saveInitialForOfficer'),'Officer create uses the existing scoped Office lookup and assignment service');
        $this->same(true,str_contains($workflow,'approveInitialForOfficer')&&str_contains($workflow,'returnInitialForCorrection')&&str_contains($workflow,'submitInitialForOfficer'),'Officer workflow transaction coordinates initial Office state');
        $this->same(true,str_contains($show,'Initial Office Assignment')&&str_contains($show,'initialOfficeAssignment'),'pending Officer review displays the selected initial Office');
        $this->same(true,str_contains($form,'name="arpa_service_permanency"')&&str_contains($form,'name="service_permanented_date"'),'Officer create form captures Service Permanency and Permanented Date');
        $this->same(true,str_contains($edit,"\$v('arpa_service_permanency')")&&str_contains($edit,"\$v('service_permanented_date')"),'Officer edit pre-populates both canonical service fields');
        $this->same(true,str_contains($controller,"'arpa_service_permanency'=>\$servicePermanency")&&str_contains($controller,"'service_permanented_date'=>\$permanentedDate"),'Officer update persists both canonical service fields');
        $this->same(true,str_contains($controller,'arpa_service_permanency,service_permanented_date')&&str_contains($controller,'OfficerPersonnelValidator::servicePermanency'),'Officer creation persists canonical service fields through centralized validation');
        $this->same(true,substr_count($show,"\$officer['arpa_service_permanency']")>=3&&str_contains($show,"\$officer['service_permanented_date']"),'profile header and Service Information use the same canonical Officer values');
        $this->same(true,preg_match('/name="arpa_service_permanency"[^>]*required/',$form)===1&&str_contains($form,'Service Permanency *'),'Add form requires Service Permanency');
        $this->same(true,str_contains($form,"permanentedDate.required=required")&&str_contains($edit,"permanentedDate.required=required"),'Add and Edit dynamically require Permanented Date for Permanent In Service');
        $this->same(true,str_contains($workflow,'OfficerPersonnelValidator::servicePermanency'),'transactional resubmission and approval revalidate Service Permanency');
        $this->same(true,str_contains($personnelValidator,'Permanented Date is invalid.')&&str_contains($personnelValidator,'Permanented Date is required for an Officer who is Permanent In Service.'),'central validator retains strict date and conditional-required rules');
        $this->same(true,str_contains($form,'Telephone Number')&&str_contains($form,'WhatsApp Number')&&str_contains($form,'At least one contact number is required.'),'Add form explains the shared contact-number requirement');
        $this->same(true,!preg_match('/name="primary_mobile"[^>]*required/',$form)&&!preg_match('/name="alternative_mobile"[^>]*required/',$form),'Add form does not require both contact inputs individually');
        $this->same(true,str_contains($personnelValidator,'Please provide at least one contact number.')&&str_contains($workflow,'OfficerPersonnelValidator::contactNumbers'),'contact requirement is centralized and transactionally rechecked on resubmission');
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

    private function officer(string $creator,?string $originRole,?string $scope,string $status,?string $servicePermanency='NOT_PERMANENT_IN_SERVICE',?string $permanentedDate=null,?string $telephone='+94761187358',?string $whatsApp=null):string
    {
        $id=$this->uuid();$dad='MC-'.substr(str_replace('-','',$id),0,16);$officerStatus=(string)$this->pdo->query('SELECT id FROM officer_status WHERE active=1 LIMIT 1')->fetchColumn();
        $this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,arpa_service_permanency,service_permanented_date,primary_mobile,alternative_mobile,officer_status_id,effective_from,operational_status,approval_status,created_by,submitted_by,submitted_at,workflow_origin_role_code,workflow_scope_location_id) VALUES(?,?,?,?,?,?,?, ?,CURRENT_DATE(),'INACTIVE',?,?,?,NOW(),?,?)")->execute([$id,$dad,'Maker Checker Officer',$servicePermanency,$permanentedDate,$telephone,$whatsApp,$officerStatus,$status,$creator,$creator,$originRole,$scope]);return $id;
    }

    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $call,string $message):void{$this->assertions++;try{$call();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
}

exit((new OfficerMakerCheckerWorkflowTest())->run());
