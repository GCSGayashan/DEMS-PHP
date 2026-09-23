<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database};
use App\Services\{OfficerOfficeAssignmentService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class SubjectOfficerOfficeAssignmentWorkflowTest
{
    private PDO $pdo;private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->pdo->beginTransaction();
        try{$this->exercise();}finally{$_SESSION=[];Auth::forgetRequestCache();$this->pdo->rollBack();}
        echo "SubjectOfficerOfficeAssignmentWorkflowTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        [$districtA,$officeA,$districtB,$officeB]=$this->districtFixtures();
        $districtMaker=$this->actor('DISTRICT_SUBJECT_OFFICER',$districtA);
        $districtAdmin=$this->actor('DISTRICT_ADMIN',$districtA);
        $otherAdmin=$this->actor('DISTRICT_ADMIN',$districtB);
        $nationalMaker=$this->actor('NATIONAL_SUBJECT_OFFICER',null);
        $nationalAdmin=$this->actor('NATIONAL_ADMIN',null);
        $systemAdmin=$this->actor('SYSTEM_ADMIN',null);
        $viewer=$this->actor('DISTRICT_VIEWER',$districtA);
        $service=new OfficerOfficeAssignmentService($this->pdo);$today=date('Y-m-d');

        $own=$this->officer('OWN UNASSIGNED',$districtA,'DISTRICT_SUBJECT_OFFICER');
        $outside=$this->officer('OUTSIDE UNASSIGNED',$districtB,'DISTRICT_SUBJECT_OFFICER');
        $unknown=$this->officer('NATIONAL ONLY',null,null);
        $ended=$this->officer('ENDED OFFICE',$districtA,'DISTRICT_SUBJECT_OFFICER');
        $this->approvedAssignment($ended,$officeA,'2025-01-01',date('Y-m-d',strtotime('-1 day')));
        $assigned=$this->officer('CURRENT OFFICE',$districtA,'DISTRICT_SUBJECT_OFFICER');
        $this->approvedAssignment($assigned,$officeA,'2025-01-01',null);
        $future=$this->officer('FUTURE OFFICE',$districtA,'DISTRICT_SUBJECT_OFFICER');
        $this->approvedAssignment($future,$officeA,date('Y-m-d',strtotime('+30 days')),null);

        $this->useContext($districtMaker);
        $this->same(true,Auth::can('officer.office-assignment.create'),'District Subject Officer receives existing Office Assignment create permission');
        $districtRows=$service->unassignedOfficers($districtMaker['user']);
        $this->same(true,$this->containsOfficer($districtRows,$own),'own-District unassigned Officer is visible');
        $this->same(true,$this->containsOfficer($districtRows,$ended),'Officer with only an ended assignment is unassigned');
        $this->same(false,$this->containsOfficer($districtRows,$assigned),'Officer with a current approved assignment is excluded');
        $this->same(false,$this->containsOfficer($districtRows,$future),'Officer with a future approved assignment is excluded');
        $this->same(false,$this->containsOfficer($districtRows,$outside),'another District Officer is excluded');
        $this->same(false,$this->containsOfficer($districtRows,$unknown),'Officer without authoritative District evidence is not exposed to District users');
        $form=$service->assignmentForm($own,$districtMaker['user']);$officeIds=array_column($form['offices'],'id');
        $this->same(true,in_array($officeA,$officeIds,true),'District Office selector includes an Office in the current District');
        $this->same(false,in_array($officeB,$officeIds,true),'District Office selector excludes another District');
        $this->throws(fn()=>$service->assignmentForm($outside,$districtMaker['user']),'forged out-of-District Officer form access is rejected');
        $request=$service->create(['officer_id'=>$own,'office_id'=>$officeA,'effective_from'=>$today,'reason'=>'District Subject Officer assignment'],$districtMaker['user']);
        $this->same('SUBMITTED',(string)$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$request]),'request is submitted');
        $this->same(0,(int)$this->value('SELECT active FROM officer_office_assignment WHERE id=?',[$request]),'submission does not activate the Office assignment');
        $this->same('DISTRICT_SUBJECT_OFFICER',(string)$this->value('SELECT workflow_origin_role_code FROM officer_office_assignment WHERE id=?',[$request]),'maker working role is persisted');
        $this->same($districtA,(string)$this->value('SELECT workflow_scope_location_id FROM officer_office_assignment WHERE id=?',[$request]),'District governance scope is persisted');
        $pending=$this->findOfficer($service->unassignedOfficers($districtMaker['user']),$own);$this->same('Pending Assignment',(string)$pending['assignment_status'],'pending request is labelled instead of offering a duplicate assignment');$this->same(false,(bool)$pending['can_assign'],'pending request removes the Assign Office action');
        $this->throws(fn()=>$service->create(['officer_id'=>$own,'office_id'=>$officeA,'effective_from'=>$today,'reason'=>'Duplicate request'],$districtMaker['user']),'second pending request for the same Officer is blocked');
        $this->throws(fn()=>$service->create(['officer_id'=>$outside,'office_id'=>$officeA,'effective_from'=>$today,'reason'=>'Forged District request'],$districtMaker['user']),'server rejects forged out-of-scope District request');
        $this->throws(fn()=>$service->approve($request,$districtMaker['user']),'maker cannot self-approve');

        $this->useContext($otherAdmin);$this->same(0,$this->queue($this->dad($own))['recordsFiltered'],'another District queue excludes the request');$this->throws(fn()=>$service->approve($request,$otherAdmin['user']),'another District Admin cannot approve');
        $this->useContext($districtAdmin);$this->same(1,$this->queue($this->dad($own))['recordsFiltered'],'same-District Admin sees the pending request');$this->same($request,(string)$service->reviewForApproval($request,$districtAdmin['user'])['id'],'same-District Admin can review');$service->approve($request,$districtAdmin['user']);
        $this->same(1,(int)$this->value("SELECT COUNT(*) FROM officer_office_assignment WHERE id=? AND approval_status='APPROVED' AND active=1",[$request]),'approval activates the same canonical assignment row');
        $this->same($officeA,(string)$this->value('SELECT primary_office_id FROM officer WHERE id=?',[$own]),'approval synchronizes the primary Office pointer');
        $this->same(false,$this->containsOfficer($service->unassignedOfficers($districtAdmin['user']),$own),'approved Officer disappears from Unassigned Officers');
        $this->throws(fn()=>$service->approve($request,$districtAdmin['user']),'assignment cannot be approved twice');
        $this->same(1,(int)$this->value("SELECT COUNT(*) FROM officer_office_assignment_audit WHERE assignment_id=? AND action_key='APPROVED'",[$request]),'approval is audited exactly once');

        $returnedOfficer=$this->officer('RETURNED REQUEST',$districtA,'DISTRICT_SUBJECT_OFFICER');$this->useContext($districtMaker);$returned=$service->create(['officer_id'=>$returnedOfficer,'office_id'=>$officeA,'effective_from'=>$today,'reason'=>'Needs review'],$districtMaker['user']);$this->useContext($districtAdmin);$service->returnForCorrection($returned,'Clarify the assignment reason.',$districtAdmin['user']);$this->same('RETURNED',(string)$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$returned]),'District Admin can return the request');$this->same(1,(int)$this->value("SELECT COUNT(*) FROM system_notification WHERE recipient_user_id=? AND entity_type='OFFICER_OFFICE_ASSIGNMENT' AND entity_id=? AND title='Office Assignment Returned'",[$districtMaker['user'],$returned]),'return notification targets the original Subject Officer');
        $this->useContext($districtMaker);$returnedForm=$service->assignmentForm($returnedOfficer,$districtMaker['user']);$this->same($returned,(string)$returnedForm['returnedAssignment']['id'],'original maker receives the same returned assignment for correction');$same=$service->create(['assignment_id'=>$returned,'officer_id'=>$returnedOfficer,'office_id'=>$officeA,'effective_from'=>$today,'reason'=>'Clarified assignment reason'],$districtMaker['user']);$this->same($returned,$same,'returned assignment is resubmitted without creating a duplicate row');
        $this->useContext($districtAdmin);$service->reject($returned,'Assignment is not required.',$districtAdmin['user']);$this->same('REJECTED',(string)$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$returned]),'District Admin can reject a resubmitted request');

        $staleOfficer=$this->officer('STALE REQUEST',$districtA,'DISTRICT_SUBJECT_OFFICER');$this->useContext($districtMaker);$stale=$service->create(['officer_id'=>$staleOfficer,'office_id'=>$officeA,'effective_from'=>$today,'reason'=>'Stale assignment test'],$districtMaker['user']);$this->approvedAssignment($staleOfficer,$officeA,'2025-01-01',null);$this->useContext($districtAdmin);$this->throws(fn()=>$service->approve($stale,$districtAdmin['user']),'pending request cannot overwrite a newly current Office assignment');

        $enterpriseOfficer=$this->officer('SYSTEM REVIEW',$districtA,'DISTRICT_SUBJECT_OFFICER');$this->useContext($districtMaker);$enterpriseRequest=$service->create(['officer_id'=>$enterpriseOfficer,'office_id'=>$officeA,'effective_from'=>$today,'reason'=>'Existing system administrator behavior'],$districtMaker['user']);$this->useContext($systemAdmin);$this->same(1,$this->queue($this->dad($enterpriseOfficer))['recordsFiltered'],'SYSTEM_ADMIN retains enterprise pending-queue visibility');$service->approve($enterpriseRequest,$systemAdmin['user']);$this->same('APPROVED',(string)$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$enterpriseRequest]),'SYSTEM_ADMIN retains existing authorized approval behavior');

        $this->useContext($nationalMaker);$nationalRows=$service->unassignedOfficers($nationalMaker['user']);$this->same(true,$this->containsOfficer($nationalRows,$outside)&&$this->containsOfficer($nationalRows,$unknown),'National Subject Officer sees unassigned Officers nationally');$nationalForm=$service->assignmentForm($unknown,$nationalMaker['user']);$this->same(true,in_array($officeB,array_column($nationalForm['offices'],'id'),true),'National Office selector includes an eligible Office nationally');$nationalRequest=$service->create(['officer_id'=>$unknown,'office_id'=>$officeB,'effective_from'=>$today,'reason'=>'National Subject Officer assignment'],$nationalMaker['user']);$this->throws(fn()=>$service->approve($nationalRequest,$nationalMaker['user']),'National Subject Officer cannot self-approve');
        $this->same(1,(int)$this->value("SELECT COUNT(*) FROM system_notification WHERE recipient_user_id=? AND entity_type='OFFICER_OFFICE_ASSIGNMENT' AND entity_id=?",[$nationalAdmin['user'],$nationalRequest]),'National request notification targets an eligible National Admin');$this->useContext($nationalAdmin);$this->same(1,$this->queue($this->dad($unknown))['recordsFiltered'],'National Admin sees the National request');$service->approve($nationalRequest,$nationalAdmin['user']);$this->same('APPROVED',(string)$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$nationalRequest]),'National Admin approval activates the request');

        $this->useContext($viewer);$this->same(false,Auth::can('officer.office-assignment.create'),'Viewer receives no Office Assignment creation permission');$this->throws(fn()=>$service->assignmentForm($ended,$viewer['user']),'Viewer cannot open the assignment form');$this->throws(fn()=>$service->create(['officer_id'=>$ended,'office_id'=>$officeA,'effective_from'=>$today,'reason'=>'Forged viewer request'],$viewer['user']),'service rejects a forged Viewer submission');
        $this->useContext($districtMaker);$this->same(false,Auth::can('officer.office-assignment.end'),'Subject Officer receives no End permission');$this->same(false,Auth::can('officer.office-assignment.set-primary'),'Subject Officer receives no Set Primary permission');
        $this->same(true,(int)$this->value("SELECT COUNT(*) FROM system_notification WHERE recipient_user_id=? AND entity_type='OFFICER_OFFICE_ASSIGNMENT' AND entity_id=?",[$districtAdmin['user'],$request])>=1,'District request notification targets an eligible same-District Admin');

        $view=(string)file_get_contents(BASE_PATH.'/app/Views/officers/unassigned.php');$profile=(string)file_get_contents(BASE_PATH.'/app/Views/officers/show.php');$this->same(true,str_contains($view,'Pending Assignment')&&str_contains($view,'Assign Office'),'Unassigned UI distinguishes pending and assignable Officers');$this->same(true,str_contains($profile,"officeAssignmentAction['can_assign']")&&str_contains($profile,'AssignmentDirectEditPolicy')&&str_contains($profile,'AssignmentDeletePolicy'),'Assign visibility is separate from existing Edit/Delete policies');
    }

    private function districtFixtures():array
    {
        $rows=$this->pdo->query("SELECT district.id district_id,asc_office.id office_id FROM location district JOIN location_type dt ON dt.id=district.location_type_id AND dt.system_key='DISTRICT' JOIN location_relationship lr ON lr.parent_location_id=district.id AND lr.relationship_type='DISTRICT_ASC' AND lr.active=1 AND lr.approval_status='APPROVED' JOIN office asc_office ON asc_office.linked_location_id=lr.child_location_id JOIN office_type ot ON ot.id=asc_office.office_type_id AND ot.system_key='ASC_OFFICE' WHERE asc_office.approval_status='APPROVED' AND asc_office.operational_status='ACTIVE' ORDER BY district.dad_number,asc_office.dad_number")->fetchAll();foreach($rows as $a)foreach($rows as $b)if((string)$a['district_id']!==(string)$b['district_id'])return [(string)$a['district_id'],(string)$a['office_id'],(string)$b['district_id'],(string)$b['office_id']];throw new RuntimeException('Two District Office scopes are required.');
    }
    private function officer(string $label,?string $scope,?string $origin):string{$id=$this->uuid();$status=(string)$this->value('SELECT id FROM officer_status WHERE active=1 ORDER BY display_order,id LIMIT 1');$this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,arpa_service_permanency,primary_mobile,officer_status_id,effective_from,operational_status,approval_status,workflow_origin_role_code,workflow_scope_location_id) VALUES(?,?,?,'NOT_PERMANENT_IN_SERVICE','+94761187358',?,CURRENT_DATE(),'ACTIVE','APPROVED',?,?)")->execute([$id,'SOA-'.substr(str_replace('-','',$id),0,14),$label,$status,$origin,$scope]);return $id;}
    private function approvedAssignment(string $officer,string $office,string $from,?string $to):void{$this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,effective_to,is_primary,active,approval_status,reason,approved_at) VALUES(UUID(),?,?,?,?,1,1,'APPROVED','Subject Officer workflow fixture',NOW())")->execute([$officer,$office,$from,$to]);}
    private function actor(string $roleCode,?string $location):array{$user=$this->uuid();$username='soa'.substr(str_replace('-','',$user),0,18);$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$user,$username,$username]);$role=(string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$roleCode]);$level=(string)$this->value('SELECT role_level FROM application_role WHERE id=?',[$role]);$ra=$this->uuid();$this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(?,?,?,CURRENT_DATE(),'APPROVED',1,'Subject Office Assignment test')")->execute([$ra,$user,$role]);$scope=null;if($level!=='SYSTEM'){$scope=$this->uuid();$type=$level==='NATIONAL'?'NATIONAL':$level;$mode=$level==='NATIONAL'?'NATIONAL':($level==='DISTRICT'?'INCLUDE_CHILDREN':'EXACT');$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(?,?,?,?,?,?,CURRENT_DATE(),'APPROVED',1,'Subject Office Assignment test')")->execute([$scope,$user,$ra,$type,$mode,$location]);}return ['user'=>$user,'role_assignment'=>$ra,'scope_assignment'=>$scope];}
    private function useContext(array $actor):void{$_SESSION=['user_id'=>$actor['user'],'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();(new UserContextService($this->pdo))->select($actor['user'],$actor['role_assignment'],$actor['scope_assignment']);Auth::forgetRequestCache();}
    private function queue(string $dad):array{return (new DataTableQuery($this->pdo,DataTableRegistry::definition('pending-officer-office-assignments'),new DataTableRequest(['length'=>25,'search'=>['value'=>$dad]])))->response();}
    private function containsOfficer(array $rows,string $id):bool{return in_array($id,array_column($rows,'id'),true);}
    private function findOfficer(array $rows,string $id):array{foreach($rows as $row)if((string)$row['id']===$id)return $row;throw new RuntimeException('Expected Officer was not found.');}
    private function dad(string $id):string{return (string)$this->value('SELECT dad_number FROM officer WHERE id=?',[$id]);}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $call,string $message):void{$this->assertions++;try{$call();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
}

exit((new SubjectOfficerOfficeAssignmentWorkflowTest())->run());
