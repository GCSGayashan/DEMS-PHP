<?php
declare(strict_types=1);

use App\Core\{Auth,Database,NicNormalizer};
use App\Services\{OfficerAdminDirectEditPolicy,OfficerAdminDirectEditService,OfficerProfileService,OfficerWorkflowService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class OfficerAdminDirectEditTest
{
    private PDO $pdo;private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->pdo->beginTransaction();
        try{$this->exercise();}finally{$_SESSION=[];Auth::forgetRequestCache();if($this->pdo->inTransaction())$this->pdo->rollBack();}
        echo "OfficerAdminDirectEditTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        $admin=(string)$this->value("SELECT id FROM system_user WHERE username='dems.admin'");
        if($admin==='')throw new RuntimeException('Canonical dems.admin fixture is required.');
        $otherSystem=$this->actor('SYSTEM_ADMIN',null,'officer-direct-other-system');
        $nationalAdmin=$this->actor('NATIONAL_ADMIN',null,'officer-direct-national-admin');
        $target=$this->targetOfficer();$id=(string)$target['id'];$service=new OfficerAdminDirectEditService($this->pdo);$workflow=new OfficerWorkflowService($this->pdo);

        $this->useContext($admin,'SYSTEM_ADMIN');
        $this->same(true,OfficerAdminDirectEditPolicy::allowed(),'canonical dems.admin in SYSTEM_ADMIN context receives direct Officer edit');
        $this->same(true,$workflow->canAccess($id,$admin),'canonical dems.admin can open the approved Officer edit form');
        $workflow->assertEditable($id,$admin);$this->assertions++;
        $this->same(true,$workflow->actions($id,$admin)['can_edit'],'canonical dems.admin sees Edit Officer on an approved profile');

        $this->useContext($otherSystem,'SYSTEM_ADMIN');
        $this->same(false,OfficerAdminDirectEditPolicy::allowed(),'another SYSTEM_ADMIN does not receive canonical direct edit');
        $this->same(false,$workflow->actions($id,$otherSystem)['can_edit'],'another SYSTEM_ADMIN does not receive approved Officer direct-edit action');
        $this->throws(fn()=>$service->update($id,$this->data($target),(int)$target['version'],$otherSystem),'forged direct edit from another SYSTEM_ADMIN is rejected');

        $this->useContext($nationalAdmin,'NATIONAL_ADMIN');
        $this->same(false,OfficerAdminDirectEditPolicy::allowed(),'National Admin does not receive canonical direct edit');
        $this->same(false,$workflow->actions($id,$nationalAdmin)['can_edit'],'National Admin does not receive approved Officer direct-edit action');
        $this->throws(fn()=>$service->update($id,$this->data($target),(int)$target['version'],$nationalAdmin),'forged National Admin direct edit is rejected');

        $this->useContext($admin,'SYSTEM_ADMIN');
        $beforeDad=(string)$target['dad_number'];$beforeApproval=(string)$target['approval_status'];
        $beforeNotifications=$this->count('SELECT COUNT(*) FROM system_notification');
        $beforeWorkflowEvents=$this->count("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key IN('workflow.submit','workflow.approve')",[$id]);
        $beforeAssignments=$this->count('SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id=?',[$id]);
        $beforeArpa=$this->count('SELECT COUNT(*) FROM arpa_division_appointment WHERE officer_id=?',[$id]);
        $beforeSubjects=$this->count('SELECT COUNT(*) FROM arpa_subject_assignment WHERE officer_id=?',[$id]);
        $data=$this->data($target);$data['name_with_initials']='ADMIN CORRECTED OFFICER';$data['primary_mobile']='+94761187358';$data['alternative_mobile']=null;$data['employee_number']='ADMIN-'.substr(str_replace('-','',$id),0,12);$data['personal_email']='officer.'.substr(str_replace('-','',$id),0,8).'@example.test';$data['arpa_service_permanency']='NOT_PERMANENT_IN_SERVICE';$data['service_permanented_date']=null;
        $alternateDesignation=(string)$this->value('SELECT id FROM designation WHERE active=1 AND id<>? ORDER BY id LIMIT 1',[$data['primary_designation_id']]);
        if($alternateDesignation==='')throw new RuntimeException('Alternate active Designation fixture is required.');
        $data['primary_designation_id']=$alternateDesignation;

        $updated=$service->update($id,$data,(int)$target['version'],$admin);
        $this->same('ADMIN CORRECTED OFFICER',$updated['name_with_initials'],'dems.admin directly updates Officer name');
        $this->same('+94761187358',$updated['primary_mobile'],'dems.admin directly updates contact information');
        $this->same($alternateDesignation,$updated['primary_designation_id'],'dems.admin directly updates Designation using existing rules');
        $this->same('NOT_PERMANENT_IN_SERVICE',$updated['arpa_service_permanency'],'dems.admin directly updates Service Permanency');
        $this->same(null,$updated['service_permanented_date'],'non-permanent correction clears Permanented Date');
        $this->same($beforeApproval,$updated['approval_status'],'approved Officer remains approved after direct edit');
        $this->same($admin,$updated['updated_by'],'direct edit records the canonical administrative actor');
        $this->same($beforeDad,$updated['dad_number'],'Officer DAD Number remains immutable');
        $profile=(new OfficerProfileService($this->pdo))->profile($id);
        $this->same('ADMIN CORRECTED OFFICER',$profile['officer']['name_with_initials'],'direct change appears immediately on Officer Profile');
        $this->same($beforeNotifications,$this->count('SELECT COUNT(*) FROM system_notification'),'direct Officer edit creates no approval notification');
        $this->same($beforeWorkflowEvents,$this->count("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key IN('workflow.submit','workflow.approve')",[$id]),'direct Officer edit creates no Submit or Approve workflow event');
        $this->same($beforeAssignments,$this->count('SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id=?',[$id]),'Office Assignments are not modified');
        $this->same($beforeArpa,$this->count('SELECT COUNT(*) FROM arpa_division_appointment WHERE officer_id=?',[$id]),'ARPA appointments are not modified');
        $this->same($beforeSubjects,$this->count('SELECT COUNT(*) FROM arpa_subject_assignment WHERE officer_id=?',[$id]),'ARPA subject assignments are not modified');

        $audit=$this->row("SELECT details_json FROM audit_event WHERE target_id=? AND action_key='officer.admin-direct-edit' ORDER BY id DESC LIMIT 1",[$id]);
        $details=json_decode((string)$audit['details_json'],true);
        $this->same('dems.admin',$details['canonical_username']??null,'audit records canonical username');
        $this->same((string)$target['name_with_initials'],$details['before']['name_with_initials']??null,'audit retains before value');
        $this->same('ADMIN CORRECTED OFFICER',$details['after']['name_with_initials']??null,'audit retains after value');
        $this->same('SYSTEM_ADMIN',$details['active_context']['role_code']??null,'audit retains active System context');

        $this->throws(fn()=>$service->update($id,$data,(int)$target['version'],$admin),'stale Officer version cannot overwrite a newer correction');
        $current=$this->row('SELECT * FROM officer WHERE id=?',[$id]);$data=$this->data($current);
        $service->update($id,$data,(int)$current['version'],$admin);$this->assertions++;

        $other=$this->row('SELECT * FROM officer WHERE id<>? AND nic IS NOT NULL ORDER BY id LIMIT 1',[$id]);
        $duplicateNic=$this->data($this->row('SELECT * FROM officer WHERE id=?',[$id]));$normalized=NicNormalizer::normalize((string)$other['nic']);
        $duplicateNic['nic']=$normalized;$duplicateNic['nic_normalized']=$normalized;$duplicateNic['nic_match_key']=NicNormalizer::matchKey($normalized);
        $this->throws(fn()=>$service->update($id,$duplicateNic,(int)$this->value('SELECT version FROM officer WHERE id=?',[$id]),$admin),'duplicate NIC is rejected while current Officer uniqueness remains valid');

        $duplicateEmployee='DUP-'.substr(str_replace('-','',$id),0,10);$this->pdo->prepare('UPDATE officer SET employee_number=? WHERE id=?')->execute([$duplicateEmployee,$other['id']]);
        $duplicateEmployeeData=$this->data($this->row('SELECT * FROM officer WHERE id=?',[$id]));$duplicateEmployeeData['employee_number']=$duplicateEmployee;
        $this->throws(fn()=>$service->update($id,$duplicateEmployeeData,(int)$this->value('SELECT version FROM officer WHERE id=?',[$id]),$admin),'duplicate Employee Number is rejected');

        $badClass=$this->uuid();$token=substr(str_replace('-','',$badClass),0,12);
        $this->pdo->prepare("INSERT INTO officer_class(id,dad_number,system_key,name_en,effective_from,approval_status,active) VALUES(?,?,?,?,'2025-01-01','APPROVED',1)")->execute([$badClass,'TEST-'.$token,'TEST_'.$token,'Invalid Pair Class']);
        $validClass=(string)$this->value('SELECT class_id FROM officer WHERE id=?',[$id]);
        $this->pdo->prepare("INSERT INTO designation_allowed_class(id,designation_id,class_id,effective_from,approval_status,active) VALUES(UUID(),?,?,'2025-01-01','APPROVED',1)")->execute([$alternateDesignation,$validClass]);
        $invalidPair=$this->data($this->row('SELECT * FROM officer WHERE id=?',[$id]));$invalidPair['class_id']=$badClass;
        $this->throws(fn()=>$service->update($id,$invalidPair,(int)$this->value('SELECT version FROM officer WHERE id=?',[$id]),$admin),'invalid Designation and Class combination is rejected');

        $dadInjection=$this->data($this->row('SELECT * FROM officer WHERE id=?',[$id]));$dadInjection['dad_number']='FORGED-DAD';
        $this->throws(fn()=>$service->update($id,$dadInjection,(int)$this->value('SELECT version FROM officer WHERE id=?',[$id]),$admin),'DAD Number cannot be submitted as a directly editable field');

        $office=(string)$this->value("SELECT id FROM office WHERE approval_status='APPROVED' AND operational_status='ACTIVE' ORDER BY id LIMIT 1");
        if($office==='')throw new RuntimeException('An approved active Office fixture is required.');
        [$submitted,$existingAssignment]=$this->submittedOfficer($target,$otherSystem,$office);
        $submittedBefore=$this->row('SELECT * FROM officer WHERE id=?',[$submitted]);$assignmentBefore=$this->row('SELECT * FROM officer_office_assignment WHERE id=?',[$existingAssignment]);$assignmentCount=$this->count('SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id=?',[$submitted]);
        $candidateBefore=$workflow->initialOfficeReconciliationCandidate($submitted,$admin);$this->same($existingAssignment,$candidateBefore['id']??null,'submitted Officer begins with the existing approved Primary Office reconciliation candidate');

        $this->useContext($otherSystem,'SYSTEM_ADMIN');
        $this->same(false,$workflow->actions($submitted,$otherSystem)['can_edit'],'another SYSTEM_ADMIN does not see Edit Officer for a submitted Officer');
        $this->throws(fn()=>$service->update($submitted,$this->data($submittedBefore),(int)$submittedBefore['version'],$otherSystem),'another SYSTEM_ADMIN cannot forge a submitted Officer direct edit');
        $this->useContext($nationalAdmin,'NATIONAL_ADMIN');
        $this->same(false,$workflow->actions($submitted,$nationalAdmin)['can_edit'],'National Admin does not see Edit Officer for a submitted Officer');
        $this->throws(fn()=>$service->update($submitted,$this->data($submittedBefore),(int)$submittedBefore['version'],$nationalAdmin),'National Admin cannot forge a submitted Officer direct edit');

        $this->useContext($admin,'SYSTEM_ADMIN');
        $this->same(true,$workflow->actions($submitted,$admin)['can_edit'],'dems.admin sees Edit Officer for a submitted Officer');
        $workflow->assertEditable($submitted,$admin);$this->assertions++;
        $permanentWithoutDate=$this->data($submittedBefore);$permanentWithoutDate['arpa_service_permanency']='PERMANENT_IN_SERVICE';$permanentWithoutDate['service_permanented_date']=null;
        $this->throws(fn()=>$service->update($submitted,$permanentWithoutDate,(int)$submittedBefore['version'],$admin),'Permanent In Service still requires Permanented Date during submitted direct edit');

        $notificationsBefore=$this->count('SELECT COUNT(*) FROM system_notification');
        $nonPermanent=$this->data($submittedBefore);$nonPermanent['arpa_service_permanency']='NOT_PERMANENT_IN_SERVICE';$nonPermanent['service_permanented_date']=null;
        $nonPermanentResult=$service->update($submitted,$nonPermanent,(int)$submittedBefore['version'],$admin);
        $this->same('NOT_PERMANENT_IN_SERVICE',$nonPermanentResult['arpa_service_permanency'],'submitted direct edit accepts Not Permanent In Service without a date');
        $this->same(null,$nonPermanentResult['service_permanented_date'],'non-permanent submitted correction keeps Permanented Date null');

        $permanent=$this->data($nonPermanentResult);$permanent['arpa_service_permanency']='PERMANENT_IN_SERVICE';$permanent['service_permanented_date']='2020-05-06';
        $submittedAfter=$service->update($submitted,$permanent,(int)$nonPermanentResult['version'],$admin);
        $this->same('SUBMITTED',$submittedAfter['approval_status'],'submitted direct edit preserves workflow status');
        $this->same($submittedBefore['submitted_by'],$submittedAfter['submitted_by'],'submitted_by is unchanged');
        $this->same($submittedBefore['submitted_at'],$submittedAfter['submitted_at'],'submitted_at is unchanged');
        $this->same($submittedBefore['workflow_origin_role_code'],$submittedAfter['workflow_origin_role_code'],'workflow origin role is unchanged');
        $this->same($submittedBefore['workflow_scope_location_id'],$submittedAfter['workflow_scope_location_id'],'workflow scope is unchanged');
        $this->same(null,$submittedAfter['approved_by'],'submitted direct edit does not set approved_by');
        $this->same(null,$submittedAfter['approved_at'],'submitted direct edit does not set approved_at');
        $this->same($notificationsBefore,$this->count('SELECT COUNT(*) FROM system_notification'),'submitted direct edit creates no approval notification');
        $submittedProfile=(new OfficerProfileService($this->pdo))->profile($submitted);
        $this->same('PERMANENT_IN_SERVICE',$submittedProfile['officer']['arpa_service_permanency'],'corrected Service Permanency appears immediately on the submitted Officer profile');
        $this->same('2020-05-06',$submittedProfile['officer']['service_permanented_date'],'corrected Permanented Date appears immediately on profile');
        $candidateAfter=$workflow->initialOfficeReconciliationCandidate($submitted,$admin);$this->same($existingAssignment,$candidateAfter['id']??null,'reconciliation candidate remains available after direct edit');
        $submittedAudit=$this->row("SELECT details_json FROM audit_event WHERE target_id=? AND action_key='officer.admin-direct-edit' ORDER BY id DESC LIMIT 1",[$submitted]);$submittedDetails=json_decode((string)$submittedAudit['details_json'],true);
        $this->same('SUBMITTED',$submittedDetails['previous_approval_status']??null,'direct-edit audit records the previous submitted status');
        $this->same('SUBMITTED',$submittedDetails['workflow_status_preserved']??null,'direct-edit audit records the preserved submitted status');

        $workflow->reconcileInitialOfficeAndApprove($submitted,$admin);
        $this->same('APPROVED',$this->value('SELECT approval_status FROM officer WHERE id=?',[$submitted]),'reconciliation approval succeeds after invalid master data is corrected');
        $this->same($assignmentBefore,$this->row('SELECT * FROM officer_office_assignment WHERE id=?',[$existingAssignment]),'existing approved Primary Office assignment remains unchanged through edit and reconciliation');
        $this->same($assignmentCount,$this->count('SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id=?',[$submitted]),'submitted edit and reconciliation create no duplicate Office assignment');

        $draft=$this->uuid();$draftDad='DRAFT-'.substr(str_replace('-','',$draft),0,12);
        $this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,officer_status_id,effective_from,operational_status,approval_status) VALUES(?,?,?,?,CURRENT_DATE(),'INACTIVE','DRAFT')")->execute([$draft,$draftDad,'Draft Direct Edit Fixture',$target['officer_status_id']]);
        $this->throws(fn()=>$service->update($draft,$data,0,$admin),'direct administrative editing remains unavailable for Draft Officers');

        $view=(string)file_get_contents(BASE_PATH.'/app/Views/officers/show.php');$form=(string)file_get_contents(BASE_PATH.'/app/Views/officers/edit.php');
        $this->same(true,str_contains($view,"['admin_direct_edit'])?'Edit Officer':'Edit'"),'canonical approved and submitted profile actions are labelled Edit Officer');
        $this->same(true,str_contains($form,'name="version"')&&str_contains($form,'Direct administrative correction')&&str_contains($form,'workflow status, submission details, and assignments are not changed'),'edit form carries optimistic version and explains workflow preservation');
    }

    /** @return array<string,mixed> */
    private function targetOfficer():array
    {
        $sql="SELECT * FROM officer WHERE approval_status='APPROVED' AND nic IS NOT NULL AND title_id IS NOT NULL AND full_name_en IS NOT NULL AND date_of_birth IS NOT NULL AND permanent_address IS NOT NULL AND initial_appointment_date IS NOT NULL AND appointment_nature_id IS NOT NULL AND primary_designation_id IS NOT NULL AND class_id IS NOT NULL AND officer_status_id IS NOT NULL AND effective_from IS NOT NULL AND arpa_service_permanency='PERMANENT_IN_SERVICE' AND service_permanented_date IS NOT NULL AND (primary_mobile IS NOT NULL OR alternative_mobile IS NOT NULL) ORDER BY id LIMIT 1";
        $row=$this->pdo->query($sql)->fetch();if(!$row)throw new RuntimeException('Complete approved Officer fixture is required.');return $row;
    }

    /** @param array<string,mixed> $source @return array{0:string,1:string} */
    private function submittedOfficer(array $source,string $creator,string $office):array
    {
        $id=$this->uuid();$nic='8'.str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT).'V';$data=$this->data($source);
        $data['nic']=$nic;$data['nic_normalized']=$nic;$data['nic_match_key']=NicNormalizer::matchKey($nic);$data['employee_number']='SUB-'.substr(str_replace('-','',$id),0,12);$data['personal_email']=null;$data['official_email']=null;$data['arpa_service_permanency']=null;$data['service_permanented_date']=null;
        $row=array_merge($data,['id'=>$id,'dad_number'=>'SUB-'.substr(str_replace('-','',$id),0,16),'primary_office_id'=>$office,'operational_status'=>'INACTIVE','approval_status'=>'SUBMITTED','created_by'=>$creator,'submitted_by'=>$creator,'submitted_at'=>'2026-09-11 14:40:42','workflow_origin_role_code'=>'DISTRICT_SUBJECT_OFFICER','workflow_scope_location_id'=>(string)$this->value("SELECT l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='DISTRICT' ORDER BY l.id LIMIT 1")]);
        $columns=array_keys($row);$this->pdo->prepare('INSERT INTO officer('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));
        $assignment=$this->uuid();$this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,is_primary,active,approval_status,reason,created_by,submitted_by,submitted_at,approved_by,approved_at) VALUES(?,?,?,'2025-01-01',1,1,'APPROVED','Re Assignment',?,?,NOW(),?,NOW())")->execute([$assignment,$id,$office,$creator,$creator,$creator]);
        return [$id,$assignment];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function data(array $row):array
    {
        $fields=['nic','nic_normalized','nic_match_key','employee_number','title_id','name_with_initials','full_name_en','full_name_si','full_name_ta','date_of_birth','expected_retirement_date','gender','civil_status_id','permanent_address','temporary_address','primary_mobile','alternative_mobile','personal_email','official_email','initial_appointment_date','appointment_nature_id','primary_designation_id','class_id','arpa_service_permanency','service_permanented_date','officer_status_id','effective_from'];
        $data=[];foreach($fields as $field)$data[$field]=$row[$field]??null;return $data;
    }
    private function actor(string $roleCode,?string $location,string $username):string
    {
        $user=$this->uuid();$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$user,$username,$username]);$role=(string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$roleCode]);$level=(string)$this->value('SELECT role_level FROM application_role WHERE id=?',[$role]);$assignment=$this->uuid();$this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(?,?,?,'2025-01-01','APPROVED',1,'Officer direct-edit test')")->execute([$assignment,$user,$role]);
        if($level!=='SYSTEM'){$scope=$this->uuid();$type=$level==='NATIONAL'?'NATIONAL':$level;$mode=$level==='NATIONAL'?'NATIONAL':'EXACT';$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(?,?,?,?,?,?, '2025-01-01','APPROVED',1,'Officer direct-edit test')")->execute([$scope,$user,$assignment,$type,$mode,$location]);}
        return $user;
    }
    private function useContext(string $user,string $roleCode):void
    {
        $_SESSION=['user_id'=>$user,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();$context=array_values(array_filter((new UserContextService($this->pdo))->availableContexts($user),fn($row)=>(string)$row['role_code']===$roleCode))[0]??null;if(!$context)throw new RuntimeException("Missing {$roleCode} context");(new UserContextService($this->pdo))->select($user,(string)$context['role_assignment_id'],$context['scope_assignment_id']===null?null:(string)$context['scope_assignment_id']);Auth::forgetRequestCache();
    }
    private function row(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function count(string $sql,array $params=[]):int{return (int)$this->value($sql,$params);}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
}

exit((new OfficerAdminDirectEditTest())->run());
