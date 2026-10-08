<?php
declare(strict_types=1);

use App\Core\{Auth,Database};
use App\Services\{ArpaAdministrativePolicy,ArpaAppointmentAdministrationService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaNationalSubjectDirectEditTest
{
    private PDO $pdo;private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->pdo->beginTransaction();
        try{$this->exercise();}finally{$_SESSION=[];Auth::forgetRequestCache();$this->pdo->rollBack();}
        echo "ArpaNationalSubjectDirectEditTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        [$first,$second]=$this->divisionFixtures();$officer=(string)$this->value("SELECT id FROM officer WHERE approval_status='APPROVED' ORDER BY id LIMIT 1");
        $admin=(string)$this->value("SELECT id FROM system_user WHERE username='dems.admin'");
        $nationalSubject=$this->actor('NATIONAL_SUBJECT_OFFICER','arpa-direct-national-subject');
        $nationalAdmin=$this->actor('NATIONAL_ADMIN','arpa-direct-national-admin');
        $nationalViewer=$this->actor('NATIONAL_VIEWER','arpa-direct-national-viewer');
        $districtSubject=$this->actor('DISTRICT_SUBJECT_OFFICER','arpa-direct-district-subject',(string)$first['district_id']);
        $ascSubject=$this->actor('ASC_SUBJECT_OFFICER','arpa-direct-asc-subject',(string)$first['asc_id']);
        $service=new ArpaAppointmentAdministrationService($this->pdo);

        $open=$this->appointment($officer,(string)$first['asc_id'],(string)$first['division_id'],'2024-02-01',null,$admin);
        $closed=$this->appointment($officer,(string)$second['asc_id'],(string)$second['division_id'],'2025-01-01','2025-01-10',$admin);
        $requestsBefore=(int)$this->value('SELECT COUNT(*) FROM arpa_division_appointment_request');
        $workflowBefore=(int)$this->value('SELECT COUNT(*) FROM arpa_appointment_workflow_action');
        $notificationsBefore=(int)$this->value('SELECT COUNT(*) FROM system_notification');

        $this->useContext($nationalSubject,'NATIONAL_SUBJECT_OFFICER');
        $this->same(true,ArpaAdministrativePolicy::canCorrectDates(),'National Subject Officer direct-edit policy is enabled in the active National context');
        $context=ArpaAdministrativePolicy::assertDateCorrection();$this->same('NATIONAL_SUBJECT_OFFICER',(string)$context['role_code'],'direct-edit assertion retains the selected National Subject Officer context');
        $openForm=$service->appointmentForCorrection($open['appointment']);$this->same(null,$openForm['closure_id'],'National Subject Officer can load an open current assignment for editing');
        $service->correctDates($open['appointment'],['effective_from'=>'2024-02-02','correction_reason'=>'Correct current assignment start'], $nationalSubject);
        $this->same('2024-02-02',(string)$this->value('SELECT effective_from FROM arpa_division_appointment WHERE id=?',[$open['appointment']]),'current assignment correction takes effect immediately');
        $this->same('2024-02-02',(string)$this->value('SELECT requested_effective_from FROM arpa_division_appointment_request WHERE id=?',[$open['request']]),'current assignment request snapshot stays synchronized');
        $this->same(0,(int)$this->value('SELECT COUNT(*) FROM arpa_division_appointment_closure WHERE appointment_id=?',[$open['appointment']]),'editing an open assignment does not fabricate a closure');

        $historyForm=$service->appointmentForCorrection($closed['appointment']);$this->same(true,$historyForm['closure_id']!==null,'National Subject Officer can load a historical assignment for editing');
        $service->correctDates($closed['appointment'],['effective_from'=>'2025-01-01','effective_to'=>'2025-01-15','correction_reason'=>'Correct historical assignment end'], $nationalSubject);
        $this->same('2025-01-15',(string)$this->value('SELECT effective_to FROM arpa_division_appointment_closure WHERE appointment_id=?',[$closed['appointment']]),'historical correction takes effect immediately');
        $this->same('2025-01-15',(string)$this->value('SELECT requested_effective_to FROM arpa_division_appointment_request WHERE id=?',[$closed['end_request']]),'historical END request snapshot stays synchronized');
        $this->same(2,(int)$this->value("SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE appointment_id IN(?,?) AND corrected_by=? AND correction_action='ADMIN_DATE_CORRECTION'",[$open['appointment'],$closed['appointment'],$nationalSubject]),'both National Subject Officer direct edits create correction audit records');
        $this->same(2,(int)$this->value("SELECT COUNT(*) FROM audit_event WHERE target_id IN(?,?) AND actor_user_id=? AND action_key='arpa.appointment.admin-date-correction'",[$open['appointment'],$closed['appointment'],$nationalSubject]),'both direct edits create application audit events for the actor');
        $audit=(string)$this->value("SELECT after_json FROM arpa_appointment_data_correction WHERE appointment_id=? ORDER BY created_at DESC,id DESC LIMIT 1",[$closed['appointment']]);
        $this->same(true,str_contains($audit,'NATIONAL_SUBJECT_OFFICER')&&str_contains($audit,'2025-01-15'),'audit retains active context and corrected values');
        $this->same($requestsBefore,(int)$this->value('SELECT COUNT(*) FROM arpa_division_appointment_request'),'direct edits create no maker-checker request');
        $this->same($workflowBefore,(int)$this->value('SELECT COUNT(*) FROM arpa_appointment_workflow_action'),'direct edits create no workflow action');
        $this->same($notificationsBefore,(int)$this->value('SELECT COUNT(*) FROM system_notification'),'direct edits create no workflow notification');

        $this->throws(fn()=>$service->correctDates($closed['appointment'],['effective_from'=>'2025-02-01','effective_to'=>'2025-01-15','correction_reason'=>'Invalid range'],$nationalSubject),'invalid direct-edit date ranges remain rejected');
        $this->appointment($officer,(string)$second['asc_id'],(string)$second['division_id'],'2025-01-20','2025-01-31',$admin);
        $this->throws(fn()=>$service->correctDates($closed['appointment'],['effective_from'=>'2025-01-01','effective_to'=>'2025-01-20','correction_reason'=>'Invalid overlap'],$nationalSubject),'overlapping direct edits remain rejected');

        foreach([
            [$nationalAdmin,'NATIONAL_ADMIN'],[$nationalViewer,'NATIONAL_VIEWER'],
            [$districtSubject,'DISTRICT_SUBJECT_OFFICER'],[$ascSubject,'ASC_SUBJECT_OFFICER'],
        ] as [$actor,$role]){
            $this->useContext($actor,$role);$this->same(false,ArpaAdministrativePolicy::canCorrectDates(),"{$role} receives no ARPA direct-edit authority");
            $this->throws(fn()=>$service->correctDates($open['appointment'],['effective_from'=>'2024-02-03','correction_reason'=>'Forged direct edit'],$actor),"{$role} cannot forge an ARPA direct edit");
        }

        $_SESSION=[];Auth::forgetRequestCache();$this->same(false,ArpaAdministrativePolicy::canCorrectDates(),'unauthenticated direct-edit access is denied');
        $this->throws(fn()=>$service->correctDates($open['appointment'],['effective_from'=>'2024-02-03','correction_reason'=>'Unauthenticated direct edit'],$nationalSubject),'direct service invocation cannot bypass authorization');
        $this->useContext($admin,'SYSTEM_ADMIN');$this->same(true,ArpaAdministrativePolicy::canCorrectDates(),'canonical dems.admin direct editing remains unchanged');

        $profile=(string)file_get_contents(BASE_PATH.'/app/Views/officers/show.php');$detail=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/appointment_detail.php');$controller=(string)file_get_contents(BASE_PATH.'/app/Controllers/ArpaAppointmentController.php');
        $this->same(true,str_contains($profile,'$canAdminDateCorrect')&&str_contains($profile,"/edit-dates')) ?>\">Edit"),'Officer profile current/history tables use the shared direct-edit policy flag');
        $this->same(true,str_contains($detail,'$canAdminDateCorrect')&&str_contains($detail,'Edit Dates'),'appointment detail uses the same direct-edit policy flag');
        $this->same(true,str_contains($controller,'ArpaAdministrativePolicy::assertDateCorrection()')&&str_contains($controller,'if(!ArpaAdministrativePolicy::canCorrectDates())'),'GET and POST endpoints enforce the direct-edit policy server-side');
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function divisionFixtures():array
    {
        $sql="SELECT aa.parent_location_id asc_id,aa.child_location_id division_id,da.parent_location_id district_id
                FROM location_relationship aa
                JOIN location_relationship da ON da.child_location_id=aa.parent_location_id AND da.relationship_type='DISTRICT_ASC'
               WHERE aa.relationship_type='ASC_ARPA_DIVISION'
                 AND aa.active=1 AND aa.approval_status='APPROVED' AND aa.effective_from<='2024-02-02' AND (aa.effective_to IS NULL OR aa.effective_to>='2025-01-31')
                 AND da.active=1 AND da.approval_status='APPROVED' AND da.effective_from<='2024-02-02' AND (da.effective_to IS NULL OR da.effective_to>='2025-01-31')
                 AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.arpa_division_location_id=aa.child_location_id)
               GROUP BY aa.parent_location_id,aa.child_location_id,da.parent_location_id
               ORDER BY aa.child_location_id LIMIT 2";
        $rows=$this->pdo->query($sql)->fetchAll();if(count($rows)!==2)throw new RuntimeException('Two unused ARPA Division fixtures are required.');return [$rows[0],$rows[1]];
    }

    /** @return array{appointment:string,request:string,end_request:?string} */
    private function appointment(string $officer,string $asc,string $division,string $from,?string $to,string $actor):array
    {
        $request=$this->uuid();$appointment=$this->uuid();
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,created_by) VALUES(?,'NATIVE','APPOINTMENT',?,'PERMANENT',?,?,?,'NATIONAL_APPROVED',?)")->execute([$request,$officer,$asc,$division,$from,$actor]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,service_permanency_snapshot,service_permanency_source,asc_location_id,arpa_division_location_id,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,approved_by,approved_at) SELECT ?,'NATIVE',?,?,?,'PERMANENT_IN_SERVICE','NATIVE_CURRENT_STATUS',a.id,d.id,a.dad_number,a.name_en,d.dad_number,d.name_en,'{}',?,?,NOW() FROM location a JOIN location d ON d.id=? WHERE a.id=?")->execute([$appointment,$request,$officer,'PERMANENT',$from,$actor,$division,$asc]);
        $endRequest=null;
        if($to!==null){
            $endRequest=$this->uuid();$reason=(string)$this->value('SELECT id FROM arpa_appointment_end_reason WHERE active=1 ORDER BY display_order,id LIMIT 1');
            $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,source_appointment_id,asc_location_id,arpa_division_location_id,requested_effective_to,workflow_status,created_by) VALUES(?,'NATIVE','END',?,'PERMANENT',?,?,?,?,'ASC_APPROVED',?)")->execute([$endRequest,$officer,$appointment,$asc,$division,$to,$actor]);
            $this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_kind,context_snapshot_json,approved_by,approved_at) VALUES(UUID(),'NATIVE',?,?,?,?,'DIRECT','{}',?,NOW())")->execute([$appointment,$endRequest,$to,$reason,$actor]);
        }
        return ['appointment'=>$appointment,'request'=>$request,'end_request'=>$endRequest];
    }

    private function actor(string $role,string $username,?string $location=null):string
    {
        $user=$this->uuid();$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$user,$username,$username]);$roleId=(string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$role]);$assignment=$this->uuid();$this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(?,?,?,'2025-01-01','APPROVED',1,'ARPA direct-edit test')")->execute([$assignment,$user,$roleId]);if($role!=='SYSTEM_ADMIN'){$type=str_starts_with($role,'NATIONAL_')?'NATIONAL':(str_starts_with($role,'DISTRICT_')?'DISTRICT':'ASC');$mode=$type==='NATIONAL'?'NATIONAL':($type==='DISTRICT'?'INCLUDE_CHILDREN':'EXACT');$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(UUID(),?,?,?,?,?,'2025-01-01','APPROVED',1,'ARPA direct-edit test')")->execute([$user,$assignment,$type,$mode,$location]);}return $user;
    }

    private function useContext(string $user,string $role):void{$_SESSION=['user_id'=>$user,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();$service=new UserContextService($this->pdo);$context=array_values(array_filter($service->availableContexts($user),fn(array $row):bool=>(string)$row['role_code']===$role))[0]??null;if(!$context)throw new RuntimeException("Missing {$role} context");$service->select($user,(string)$context['role_assignment_id'],$context['scope_assignment_id']===null?null:(string)$context['scope_assignment_id']);Auth::forgetRequestCache();}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $call,string $message):void{$this->assertions++;try{$call();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
}

exit((new ArpaNationalSubjectDirectEditTest())->run());
