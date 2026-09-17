<?php
declare(strict_types=1);

use App\Core\{Auth,Database};
use App\Services\{ArpaAdministrativePolicy,ArpaAppointmentAdministrationService,ArpaAppointmentService,OfficerProfileService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAdministrativeWorkflowTest
{
    private PDO $pdo;private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->pdo->beginTransaction();
        try{$this->exercise();}finally{$_SESSION=[];Auth::forgetRequestCache();$this->pdo->rollBack();}
        echo "ArpaAdministrativeWorkflowTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        $admin=(string)$this->value("SELECT id FROM system_user WHERE username='dems.admin'");$this->useContext($admin,'SYSTEM_ADMIN');
        $this->same(true,ArpaAdministrativePolicy::isCanonicalDemsAdmin(),'canonical dems.admin has full request visibility and delete authority');
        [$officer,$asc,$division]=$this->fixtureContext();$service=new ArpaAppointmentAdministrationService($this->pdo);

        $returned=$this->request($officer,$asc,$division,'END','RETURNED','2025-01-10',null);
        $submitted=$this->request($officer,$asc,$division,'APPOINTMENT','SUBMITTED',null,'2026-02-01');
        $legacy=$this->request($officer,$asc,$division,'END','NATIONAL_APPROVED','2025-01-15',null,'LEGACY_IMPORT');
        $profile=(new OfficerProfileService($this->pdo))->profile($officer,[],null,true);$ids=array_column($profile['arpa_workflow_requests'],'id');
        foreach([$returned,$submitted,$legacy] as $id)$this->same(true,in_array($id,$ids,true),'dems.admin profile includes every request status/origin');
        $this->same(false,in_array($submitted,array_column($profile['current_appointments'],'request_id'),true),'submitted workflow is not presented as an authoritative current assignment');

        $this->throws(fn()=>$service->deleteRequest($returned,'',$admin),'delete reason is mandatory');
        $this->pdo->prepare("INSERT INTO arpa_appointment_workflow_action(request_id,action,stage,user_id,previous_status,new_status) VALUES(?,'RETURN_FOR_CORRECTION','ASC',?,'ASC_VERIFIED','RETURNED')")->execute([$returned,$admin]);
        $this->pdo->prepare("INSERT INTO system_notification(id,recipient_user_id,notification_type,module_code,title,message,entity_type,entity_id,workflow_stage,action_status,dedupe_key) VALUES(UUID(),?,'ACTION_REQUIRED','ARPA_APPOINTMENT','Correction','Correction required','ARPA_DIVISION_REQUEST',?,'CORRECTION','PENDING',?)")->execute([$admin,$returned,'test-'.$returned]);
        $service->deleteRequest($returned,'Duplicate returned END request',$admin);
        $this->same(1,(int)$this->value('SELECT COUNT(*) FROM arpa_division_appointment_request WHERE id=? AND deleted_at IS NOT NULL',[$returned]),'eligible request is soft deleted');
        $this->same(1,(int)$this->value('SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE request_id=?',[$returned]),'workflow audit history is retained');
        $this->same('CANCELLED',(string)$this->value('SELECT action_status FROM system_notification WHERE entity_id=?',[$returned]),'only request notification is cancelled');
        $this->same(1,(int)$this->value("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='arpa.appointment.workflow-request.admin-delete'",[$returned]),'administrative deletion is audited');
        $this->throws(fn()=>(new ArpaAppointmentService($this->pdo))->workflow('division',$returned,'SUBMIT','CREATOR',null,$admin),'deleted request cannot be acted on');

        [$appointment,$appointmentRequest,$closure,$endRequest]=$this->authoritativeFixture($officer,$asc,$division,$admin);
        $this->throws(fn()=>$service->deleteRequest($appointmentRequest,'Must not orphan appointment',$admin),'request with authoritative appointment is protected');
        $this->throws(fn()=>$service->deleteRequest($endRequest,'Must not orphan closure',$admin),'request with authoritative closure is protected');

        $nationalSubject=$this->actor('NATIONAL_SUBJECT_OFFICER','arpa-date-national-subject');$nationalAdmin=$this->actor('NATIONAL_ADMIN','arpa-date-national-admin');$systemAdmin=$this->actor('SYSTEM_ADMIN','arpa-date-system-admin');$district=$this->actor('DISTRICT_ADMIN','arpa-date-district',$this->district($asc));$ascActor=$this->actor('ASC_ADMIN','arpa-date-asc',$asc);
        $notifications=(int)$this->value('SELECT COUNT(*) FROM system_notification');$workflow=(int)$this->value('SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE request_id IN(?,?)',[$appointmentRequest,$endRequest]);$requestUpdatedAt=$this->value('SELECT updated_at FROM arpa_division_appointment_request WHERE id=?',[$appointmentRequest]);$endRequestUpdatedAt=$this->value('SELECT updated_at FROM arpa_division_appointment_request WHERE id=?',[$endRequest]);
        foreach([[$nationalSubject,'NATIONAL_SUBJECT_OFFICER','2025-01-02'],[$nationalAdmin,'NATIONAL_ADMIN','2025-01-03'],[$systemAdmin,'SYSTEM_ADMIN','2025-01-04']] as [$actor,$role,$date]){
            $this->useContext($actor,$role);$this->same(true,ArpaAdministrativePolicy::canCorrectDates(),"{$role} may correct dates");
            $service->correctDates($appointment,['effective_from'=>$date,'effective_to'=>'2025-01-20','correction_reason'=>'Test administrative correction'],$actor);
        }
        $row=$service->appointmentForCorrection($appointment);$this->same('2025-01-04',$row['effective_from'],'canonical start date is corrected immediately');$this->same('2025-01-04',$row['requested_effective_from'],'originating request start date stays synchronized');$this->same('2025-01-20',$row['effective_to'],'canonical closure date is corrected');$this->same('2025-01-20',$row['end_request_effective_to'],'END request date stays synchronized');
        $this->same(3,(int)$this->value("SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE appointment_id=? AND correction_action='ADMIN_DATE_CORRECTION'",[$appointment]),'each direct correction is append-only audited');
        $this->same($requestUpdatedAt,$this->value('SELECT updated_at FROM arpa_division_appointment_request WHERE id=?',[$appointmentRequest]),'originating workflow updated_at is preserved');$this->same($endRequestUpdatedAt,$this->value('SELECT updated_at FROM arpa_division_appointment_request WHERE id=?',[$endRequest]),'END workflow updated_at is preserved');
        $this->same($notifications,(int)$this->value('SELECT COUNT(*) FROM system_notification'),'date correction creates no notification');$this->same($workflow,(int)$this->value('SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE request_id IN(?,?)',[$appointmentRequest,$endRequest]),'date correction creates no workflow action');
        $this->throws(fn()=>$service->correctDates($appointment,['effective_from'=>'2025-01-05','effective_to'=>'2025-01-20','correction_reason'=>''],$systemAdmin),'correction reason is mandatory');
        $this->futureAppointment($officer,$asc,$division,$systemAdmin,'2025-02-01','2025-02-10');
        $this->throws(fn()=>$service->correctDates($appointment,['effective_from'=>'2025-01-04','effective_to'=>'2025-02-02','correction_reason'=>'Overlapping correction'],$systemAdmin),'overlapping authoritative appointment is rejected while self is excluded');
        $this->useContext($district,'DISTRICT_ADMIN');$this->same(false,ArpaAdministrativePolicy::canCorrectDates(),'District context cannot correct dates');$this->throws(fn()=>$service->correctDates($appointment,['effective_from'=>'2025-01-05','effective_to'=>'2025-01-20','correction_reason'=>'Denied'],$district),'District forged correction is rejected');
        $this->useContext($ascActor,'ASC_ADMIN');$this->same(false,ArpaAdministrativePolicy::canCorrectDates(),'ASC context cannot correct dates');

        $otherSystem=$systemAdmin;$this->useContext($otherSystem,'SYSTEM_ADMIN');$this->same(false,ArpaAdministrativePolicy::isCanonicalDemsAdmin(),'another SYSTEM_ADMIN is not canonical dems.admin');$this->throws(fn()=>$service->deleteRequest($submitted,'Unauthorized',$otherSystem),'another SYSTEM_ADMIN cannot delete workflow requests');
        $view=(string)file_get_contents(BASE_PATH.'/app/Views/officers/show.php');$this->same(true,str_contains($view,'ARPA Workflow Requests')&&str_contains($view,'edit-dates'),'profile exposes the administrative request section and date Edit action');
    }

    private function fixtureContext():array
    {
        $sql="SELECT o.id officer_id,aa.parent_location_id asc_id,aa.child_location_id division_id
                FROM location_relationship aa
                JOIN location arpa ON arpa.id=aa.child_location_id
                JOIN location_type art ON art.id=arpa.location_type_id AND art.system_key='ARPA_DIVISION'
                CROSS JOIN officer o
               WHERE aa.relationship_type='ASC_ARPA_DIVISION' AND aa.active=1 AND aa.approval_status='APPROVED'
                 AND aa.effective_from<='2025-01-01' AND (aa.effective_to IS NULL OR aa.effective_to>='2025-01-20')
                 AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.arpa_division_location_id=aa.child_location_id)
               LIMIT 1";
        $r=$this->pdo->query($sql)->fetch();if(!$r)throw new RuntimeException('Unused ARPA Division fixture is required.');return [(string)$r['officer_id'],(string)$r['asc_id'],(string)$r['division_id']];
    }
    private function request(string $officer,string $asc,string $division,string $type,string $status,?string $to,?string $from,string $origin='NATIVE'):string{$id=$this->uuid();$appointmentType=$type==='APPOINTMENT'?'PERMANENT':null;$this->pdo->prepare('INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,requested_effective_to,workflow_status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$origin,$type,$officer,$appointmentType,$asc,$division,$from,$to,$status,$this->currentUser()]);return $id;}
    private function authoritativeFixture(string $officer,string $asc,string $division,string $actor):array
    {
        $request=$this->request($officer,$asc,$division,'APPOINTMENT','NATIONAL_APPROVED',null,'2025-01-01');$appointment=$this->uuid();
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,service_permanency_snapshot,service_permanency_source,asc_location_id,arpa_division_location_id,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,approved_by,approved_at) SELECT ?,'NATIVE',?,?,?,'PERMANENT_IN_SERVICE','NATIVE_CURRENT_STATUS',a.id,d.id,a.dad_number,a.name_en,d.dad_number,d.name_en,'{}','2025-01-01',?,NOW() FROM location a JOIN location d ON d.id=? WHERE a.id=?")->execute([$appointment,$request,$officer,'PERMANENT',$actor,$division,$asc]);
        $endRequest=$this->uuid();$this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,source_appointment_id,asc_location_id,arpa_division_location_id,requested_effective_to,workflow_status,created_by) VALUES(?,'NATIVE','END',?,'PERMANENT',?,?,?,'2025-01-10','ASC_APPROVED',?)")->execute([$endRequest,$officer,$appointment,$asc,$division,$actor]);
        $closure=$this->uuid();$endReason=(string)$this->value('SELECT id FROM arpa_appointment_end_reason WHERE active=1 ORDER BY display_order,id LIMIT 1');$this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_kind,context_snapshot_json,approved_by,approved_at) VALUES(?,'NATIVE',?,?,'2025-01-10',?,'DIRECT','{}',?,NOW())")->execute([$closure,$appointment,$endRequest,$endReason,$actor]);return [$appointment,$request,$closure,$endRequest];
    }
    private function futureAppointment(string $officer,string $asc,string $division,string $actor,string $from,string $to):void
    {
        $request=$this->request($officer,$asc,$division,'APPOINTMENT','NATIONAL_APPROVED',null,$from);$appointment=$this->uuid();$this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,service_permanency_snapshot,service_permanency_source,asc_location_id,arpa_division_location_id,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,approved_by,approved_at) SELECT ?,'NATIVE',?,?,?,'PERMANENT_IN_SERVICE','NATIVE_CURRENT_STATUS',a.id,d.id,a.dad_number,a.name_en,d.dad_number,d.name_en,'{}',?, ?,NOW() FROM location a JOIN location d ON d.id=? WHERE a.id=?")->execute([$appointment,$request,$officer,'PERMANENT',$from,$actor,$division,$asc]);$endRequest=$this->uuid();$this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,source_appointment_id,asc_location_id,arpa_division_location_id,requested_effective_to,workflow_status,created_by) VALUES(?,'NATIVE','END',?,'PERMANENT',?,?,?,?, 'ASC_APPROVED',?)")->execute([$endRequest,$officer,$appointment,$asc,$division,$to,$actor]);$endReason=(string)$this->value('SELECT id FROM arpa_appointment_end_reason WHERE active=1 ORDER BY display_order,id LIMIT 1');$this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_kind,context_snapshot_json,approved_by,approved_at) VALUES(UUID(),'NATIVE',?,?,?,?, 'DIRECT','{}',?,NOW())")->execute([$appointment,$endRequest,$to,$endReason,$actor]);
    }
    private function actor(string $role,string $name,?string $location=null):string{$user=$this->uuid();$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$user,$name,$name]);$roleId=(string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$role]);$assignment=$this->uuid();$this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(?,?,?,'2025-01-01','APPROVED',1,'ARPA admin test')")->execute([$assignment,$user,$roleId]);if($role!=='SYSTEM_ADMIN'){$type=str_starts_with($role,'NATIONAL_')?'NATIONAL':(str_starts_with($role,'DISTRICT_')?'DISTRICT':'ASC');$mode=$type==='NATIONAL'?'NATIONAL':($type==='DISTRICT'?'INCLUDE_CHILDREN':'EXACT');$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(UUID(),?,?,?,?,?,'2025-01-01','APPROVED',1,'ARPA admin test')")->execute([$user,$assignment,$type,$mode,$location]);}return $user;}
    private function district(string $asc):string{$s=$this->pdo->prepare("SELECT parent_location_id FROM location_relationship WHERE child_location_id=? AND relationship_type='DISTRICT_ASC' AND active=1 AND approval_status='APPROVED' LIMIT 1");$s->execute([$asc]);return (string)$s->fetchColumn();}
    private function useContext(string $user,string $role):void{$_SESSION=['user_id'=>$user,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();$service=new UserContextService($this->pdo);$context=array_values(array_filter($service->availableContexts($user),fn($row)=>(string)$row['role_code']===$role))[0]??null;if(!$context)throw new RuntimeException("Missing {$role} context");$service->select($user,(string)$context['role_assignment_id'],$context['scope_assignment_id']===null?null:(string)$context['scope_assignment_id']);Auth::forgetRequestCache();}
    private function currentUser():string{return (string)(Auth::user()['id']??'');}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $fn,string $message):void{$this->assertions++;try{$fn();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
}

exit((new ArpaAdministrativeWorkflowTest())->run());
