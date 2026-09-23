<?php
declare(strict_types=1);

use App\Core\{Auth,Database,ScopeService};
use App\Services\{NotificationService,OfficerAdminDirectEditPolicy,OfficerAdminDirectEditService,OfficerEditRequestService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class OfficerProfileEditRequestWorkflowTest
{
    private PDO $pdo;private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->pdo->beginTransaction();
        try{$this->exercise();}finally{$_SESSION=[];Auth::forgetRequestCache();$this->pdo->rollBack();}
        echo "OfficerProfileEditRequestWorkflowTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        [$target,$other]=$this->targets();$district=(string)$target['district_id'];$otherDistrict=(string)$other['district_id'];
        $districtMaker=$this->actor('DISTRICT_SUBJECT_OFFICER','officer-edit-district-maker',$district);
        $districtAdmin=$this->actor('DISTRICT_ADMIN','officer-edit-district-admin',$district);
        $otherDistrictAdmin=$this->actor('DISTRICT_ADMIN','officer-edit-other-admin',$otherDistrict);
        $nationalMaker=$this->actor('NATIONAL_SUBJECT_OFFICER','officer-edit-national-maker');
        $nationalAdminA=$this->actor('NATIONAL_ADMIN','officer-edit-national-admin-a');
        $nationalAdminB=$this->actor('NATIONAL_ADMIN','officer-edit-national-admin-b');
        $viewer=$this->actor('DISTRICT_VIEWER','officer-edit-viewer',$district);
        $service=new OfficerEditRequestService($this->pdo);$officerId=(string)$target['id'];

        $this->useContext($districtMaker,'DISTRICT_SUBJECT_OFFICER');
        $this->same(true,Auth::can('officer.edit-request'),'District Subject Officer receives edit-request permission');
        $this->same(true,$service->canInitiate($officerId,$districtMaker),'District maker can open an in-scope approved Officer edit');
        $this->same(false,$service->canInitiate((string)$other['id'],$districtMaker),'District maker cannot edit an Officer outside the District');
        $before=$this->officer($officerId);$newName=(string)$before['name_with_initials'].' EDIT';
        $request=$service->submit($officerId,['name_with_initials'=>$newName],(int)$before['version'],$districtMaker);
        $this->same((string)$before['name_with_initials'],(string)$this->value('SELECT name_with_initials FROM officer WHERE id=?',[$officerId]),'submission does not immediately alter Officer master');
        $this->same('SUBMITTED',(string)$this->value('SELECT workflow_status FROM officer_edit_request WHERE id=?',[$request]),'District edit request becomes submitted');
        $storedBefore=json_decode((string)$this->value('SELECT before_json FROM officer_edit_request WHERE id=?',[$request]),true,512,JSON_THROW_ON_ERROR);
        $storedProposed=json_decode((string)$this->value('SELECT proposed_json FROM officer_edit_request WHERE id=?',[$request]),true,512,JSON_THROW_ON_ERROR);
        $this->same((string)$before['name_with_initials'],(string)$storedBefore['name_with_initials'],'request preserves the original changed value');
        $this->same($newName,(string)$storedProposed['name_with_initials'],'request preserves the proposed changed value');
        $this->throws(fn()=>$service->submit($officerId,['temporary_address'=>'Competing edit'],(int)$before['version'],$districtMaker),'a second submitted request at the same governance level is blocked');
        $this->throws(fn()=>$service->submit((string)$other['id'],['name_with_initials'=>'FORGED'],(int)$other['version'],$districtMaker),'forged out-of-District submission is rejected');
        $this->throws(fn()=>$service->submit($officerId,['dad_number'=>'FORGED'],(int)$before['version'],$districtMaker),'DAD Officer Number cannot be changed');
        $this->throws(fn()=>$service->submit($officerId,['primary_office_id'=>$other['primary_office_id']],(int)$before['version'],$districtMaker),'Office Assignment cannot be changed through profile edit');
        $this->throws(fn()=>$service->approve($request,$districtMaker),'District maker cannot approve their own edit request');

        $this->useContext($otherDistrictAdmin,'DISTRICT_ADMIN');$this->throws(fn()=>$service->approve($request,$otherDistrictAdmin),'another District cannot approve the request');
        $this->useContext($districtAdmin,'DISTRICT_ADMIN');$review=$service->review($request,$districtAdmin);$this->same($newName,(string)$review['changes'][0]['proposed'],'review shows the proposed business value');
        $service->approve($request,$districtAdmin);$this->same($newName,(string)$this->value('SELECT name_with_initials FROM officer WHERE id=?',[$officerId]),'same-District Admin approval applies the Officer change');
        $this->same((int)$before['version']+1,(int)$this->value('SELECT version FROM officer WHERE id=?',[$officerId]),'approval increments Officer version exactly once');
        $this->throws(fn()=>$service->approve($request,$districtAdmin),'approved request cannot be applied twice');
        $this->same(1,(int)$this->value("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='officer.edit-request.apply'",[$officerId]),'Officer master application is audited once');
        $auditDetails=json_decode((string)$this->value("SELECT details_json FROM audit_event WHERE target_id=? AND action_key='officer.edit-request.approve' ORDER BY created_at DESC,id DESC LIMIT 1",[$request]),true,512,JSON_THROW_ON_ERROR);
        $this->same(true,isset($auditDetails['before'],$auditDetails['proposed'],$auditDetails['applied']),'approval audit retains before, proposed, and applied values');

        $this->useContext($districtMaker,'DISTRICT_SUBJECT_OFFICER');$afterFirst=$this->officer($officerId);
        $returned=$service->submit($officerId,['primary_mobile'=>'+94771234567'],(int)$afterFirst['version'],$districtMaker);
        $this->useContext($districtAdmin,'DISTRICT_ADMIN');$service->returnForCorrection($returned,'Confirm the contact number.',$districtAdmin);
        $this->useContext($districtMaker,'DISTRICT_SUBJECT_OFFICER');$returnedRow=$service->returnedForMaker($officerId,$districtMaker);$this->same($returned,(string)($returnedRow['id']??''),'original maker can reopen the returned request');
        $service->submit($officerId,['alternative_mobile'=>'+94772345678'],(int)$afterFirst['version'],$districtMaker,$returned);
        $this->same('SUBMITTED',(string)$this->value('SELECT workflow_status FROM officer_edit_request WHERE id=?',[$returned]),'returned request can be corrected and resubmitted');
        $this->useContext($districtAdmin,'DISTRICT_ADMIN');$service->approve($returned,$districtAdmin);
        $this->same('+94771234567',(string)$this->value('SELECT primary_mobile FROM officer WHERE id=?',[$officerId]),'returned proposal retains the original corrected field');
        $this->same('+94772345678',(string)$this->value('SELECT alternative_mobile FROM officer WHERE id=?',[$officerId]),'returned proposal applies the additional correction');

        $this->useContext($districtMaker,'DISTRICT_SUBJECT_OFFICER');$rejectBefore=$this->officer($officerId);$rejected=$service->submit($officerId,['temporary_address'=>'Rejected correction'],(int)$rejectBefore['version'],$districtMaker);
        $this->useContext($districtAdmin,'DISTRICT_ADMIN');$service->reject($rejected,'The supplied correction is not supported.',$districtAdmin);
        $this->same('REJECTED',(string)$this->value('SELECT workflow_status FROM officer_edit_request WHERE id=?',[$rejected]),'District Admin can reject a submitted District edit request');
        $this->same((string)$rejectBefore['temporary_address'],(string)$this->value('SELECT temporary_address FROM officer WHERE id=?',[$officerId]),'rejection leaves the Officer master unchanged');

        $this->useContext($nationalMaker,'NATIONAL_SUBJECT_OFFICER');$nationalBefore=$this->officer($officerId);$nationalRequest=$service->submit($officerId,['temporary_address'=>'National correction'],(int)$nationalBefore['version'],$nationalMaker);
        $this->same((string)$nationalBefore['temporary_address'],(string)$this->value('SELECT temporary_address FROM officer WHERE id=?',[$officerId]),'National Subject Officer edit also waits for approval');
        $this->same(true,(int)$this->value("SELECT COUNT(*) FROM system_notification WHERE recipient_user_id IN(?,?) AND entity_type='OFFICER_EDIT_REQUEST' AND entity_id=? AND workflow_stage='APPROVAL'",[$nationalAdminA,$nationalAdminB,$nationalRequest])>=1,'National edit request notifies an eligible National Admin approver');
        $this->throws(fn()=>$service->approve($nationalRequest,$nationalMaker),'National Subject Officer cannot approve their own edit request');
        $this->useContext($nationalAdminA,'NATIONAL_ADMIN');$service->approve($nationalRequest,$nationalAdminA);$this->same('National correction',(string)$this->value('SELECT temporary_address FROM officer WHERE id=?',[$officerId]),'National Admin approves National Subject Officer edit');

        $nationalAdminBefore=$this->officer($officerId);$adminRequest=$service->submit($officerId,['full_name_en'=>(string)$nationalAdminBefore['full_name_en'].' A'],(int)$nationalAdminBefore['version'],$nationalAdminA);
        $this->throws(fn()=>$service->approve($adminRequest,$nationalAdminA),'National Admin cannot approve their own edit');
        $this->useContext($nationalAdminB,'NATIONAL_ADMIN');$service->approve($adminRequest,$nationalAdminB);$this->same('APPROVED',(string)$this->value('SELECT workflow_status FROM officer_edit_request WHERE id=?',[$adminRequest]),'a different National Admin approves the request');

        $this->useContext($nationalMaker,'NATIONAL_SUBJECT_OFFICER');$staleBefore=$this->officer($officerId);$stale=$service->submit($officerId,['permanent_address'=>(string)$staleBefore['permanent_address'].' STALE'],(int)$staleBefore['version'],$nationalMaker);
        $this->pdo->prepare('UPDATE officer SET version=version+1 WHERE id=?')->execute([$officerId]);
        $this->useContext($nationalAdminB,'NATIONAL_ADMIN');$this->throws(fn()=>$service->approve($stale,$nationalAdminB),'stale Officer version blocks approval after another update');
        $this->same((string)$staleBefore['permanent_address'],(string)$this->value('SELECT permanent_address FROM officer WHERE id=?',[$officerId]),'stale request cannot overwrite the Officer');

        $this->useContext($viewer,'DISTRICT_VIEWER');$this->same(false,Auth::can('officer.edit-request'),'Viewer role receives no edit-request permission');$this->same(false,$service->canInitiate($officerId,$viewer),'Viewer cannot initiate Officer edits');
        $this->useContext($districtMaker,'DISTRICT_SUBJECT_OFFICER');$invalid=$this->officer((string)$other['id']);$this->throws(fn()=>$service->submit($officerId,['primary_mobile'=>'invalid'],(int)$this->value('SELECT version FROM officer WHERE id=?',[$officerId]),$districtMaker),'existing Officer contact validation remains enforced');

        $this->useContext($districtAdmin,'DISTRICT_ADMIN');$context=NotificationService::contextQueryParts('n');$q=$this->pdo->prepare('SELECT n.*,'.implode(',',$context['select']).' FROM system_notification n '.$context['joins']." WHERE n.recipient_user_id=? AND n.entity_type='OFFICER_EDIT_REQUEST' ORDER BY n.created_at DESC LIMIT 1");$q->execute([$districtAdmin]);$match=$q->fetch()?:null;$this->same(true,$match!==null,'District approver receives an Officer edit notification');if($match){$display=NotificationService::displayContext($match);$this->same(true,str_contains((string)$display['officer'],(string)$target['dad_number']),'notification displays Officer DAD number and name');$this->same(true,$display['office']===null||!preg_match('/^[0-9a-f-]{36}$/i',(string)$display['office']),'notification never exposes an Office UUID');}

        $admin=(string)$this->value("SELECT id FROM system_user WHERE username='dems.admin'");$this->useContext($admin,'SYSTEM_ADMIN');$this->same(true,OfficerAdminDirectEditPolicy::allowed(),'canonical dems.admin direct edit remains separate');
        $directBefore=$this->officer($officerId);$direct=$this->fullData($directBefore);$direct['temporary_address']='Immediate direct correction';(new OfficerAdminDirectEditService($this->pdo))->update($officerId,$direct,(int)$directBefore['version'],$admin);$this->same('Immediate direct correction',(string)$this->value('SELECT temporary_address FROM officer WHERE id=?',[$officerId]),'dems.admin direct edit still applies immediately without a request');

        $controller=(string)file_get_contents(BASE_PATH.'/app/Controllers/OfficerController.php');$form=(string)file_get_contents(BASE_PATH.'/app/Views/officers/edit.php');$this->same(true,str_contains($controller,'OfficerEditRequestService')&&str_contains($form,'Submit Changes for Approval'),'Officer edit UI routes normal roles through approval');
    }

    private function targets():array
    {
        $sql="SELECT ofc.id office_id,da.parent_location_id district_id FROM office ofc JOIN office_type ot ON ot.id=ofc.office_type_id AND ot.system_key='ASC_OFFICE' JOIN location_relationship da ON da.child_location_id=ofc.linked_location_id AND da.relationship_type='DISTRICT_ASC' AND da.active=1 AND da.approval_status='APPROVED' AND da.effective_from<=CURRENT_DATE() AND (da.effective_to IS NULL OR da.effective_to>=CURRENT_DATE()) WHERE ofc.approval_status='APPROVED' AND ofc.operational_status='ACTIVE' ORDER BY da.parent_location_id,ofc.id";
        $offices=$this->pdo->query($sql)->fetchAll();$pair=null;foreach($offices as $a)foreach($offices as $b)if((string)$a['district_id']!==(string)$b['district_id']){$pair=[$a,$b];break 2;}if($pair===null)throw new RuntimeException('Two ASC Offices in different Districts are required.');
        return [$this->createOfficer($pair[0],1),$this->createOfficer($pair[1],2)];
    }

    private function createOfficer(array $office,int $sequence):array
    {
        $title=(string)$this->value('SELECT id FROM hr_title WHERE active=1 ORDER BY display_order,id LIMIT 1');$nature=(string)$this->value('SELECT id FROM appointment_nature WHERE active=1 AND class_required=0 ORDER BY display_order,id LIMIT 1');$designation=(string)$this->value('SELECT id FROM designation WHERE active=1 ORDER BY designation_level,name_en,id LIMIT 1');$status=(string)$this->value('SELECT id FROM officer_status WHERE active=1 ORDER BY display_order,id LIMIT 1');if($title===''||$nature===''||$designation===''||$status==='')throw new RuntimeException('Officer reference fixtures are required.');
        $id=$this->uuid();$nic='19901234567'.$sequence;$dad='TEST-EDIT-'.str_pad((string)$sequence,4,'0',STR_PAD_LEFT);$name='OFFICER EDIT '.$sequence;
        $sql="INSERT INTO officer(id,dad_number,nic,nic_normalized,nic_match_key,employee_number,title_id,name_with_initials,full_name_en,date_of_birth,expected_retirement_date,gender,permanent_address,temporary_address,primary_mobile,alternative_mobile,initial_appointment_date,appointment_nature_id,primary_designation_id,arpa_service_permanency,officer_status_id,primary_office_id,effective_from,operational_status,approval_status,version) VALUES(?,?,?,?,?,?,?,?,?,'1990-01-01','2050-01-01','MALE','Test address','',?,NULL,'2020-01-01',?,?,'NOT_PERMANENT_IN_SERVICE',?,?, '2025-01-01','ACTIVE','APPROVED',0)";
        $this->pdo->prepare($sql)->execute([$id,$dad,$nic,$nic,$nic,'EMP-EDIT-'.$sequence,$title,$name,$name,'+9477123456'.$sequence,$nature,$designation,$status,$office['office_id']]);
        $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,is_primary,active,approval_status,reason,approved_at) VALUES(UUID(),?,?,'2025-01-01',1,1,'APPROVED','Officer edit test fixture',NOW())")->execute([$id,$office['office_id']]);
        $row=$this->officer($id);$row['district_id']=$office['district_id'];$row['current_office_id']=$office['office_id'];return $row;
    }

    private function fullData(array $o):array{$fields=['nic','nic_normalized','nic_match_key','employee_number','title_id','name_with_initials','full_name_en','full_name_si','full_name_ta','date_of_birth','expected_retirement_date','gender','civil_status_id','permanent_address','temporary_address','primary_mobile','alternative_mobile','personal_email','official_email','initial_appointment_date','appointment_nature_id','primary_designation_id','class_id','arpa_service_permanency','service_permanented_date','officer_status_id','effective_from','photograph_path'];return array_intersect_key($o,array_flip($fields));}
    private function officer(string $id):array{$s=$this->pdo->prepare('SELECT * FROM officer WHERE id=?');$s->execute([$id]);return $s->fetch()?:throw new RuntimeException('Officer fixture missing.');}
    private function actor(string $role,string $username,?string $location=null):string{$id=$this->uuid();$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$id,$username,$username]);$roleId=(string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$role]);$ra=$this->uuid();$this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(?,?,?,'2025-01-01','APPROVED',1,'Officer edit test')")->execute([$ra,$id,$roleId]);if($role!=='SYSTEM_ADMIN'){$type=str_starts_with($role,'NATIONAL_')?'NATIONAL':(str_starts_with($role,'DISTRICT_')?'DISTRICT':'ASC');$mode=$type==='NATIONAL'?'NATIONAL':($type==='DISTRICT'?'INCLUDE_CHILDREN':'EXACT');$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(UUID(),?,?,?,?,?,'2025-01-01','APPROVED',1,'Officer edit test')")->execute([$id,$ra,$type,$mode,$location]);}return $id;}
    private function useContext(string $user,string $role):void{$_SESSION=['user_id'=>$user,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();$service=new UserContextService($this->pdo);$context=array_values(array_filter($service->availableContexts($user),fn($r)=>(string)$r['role_code']===$role))[0]??null;if(!$context)throw new RuntimeException("Missing {$role} context");$service->select($user,(string)$context['role_assignment_id'],$context['scope_assignment_id']===null?null:(string)$context['scope_assignment_id']);Auth::forgetRequestCache();}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $fn,string $message):void{$this->assertions++;try{$fn();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
}

exit((new OfficerProfileEditRequestWorkflowTest())->run());
