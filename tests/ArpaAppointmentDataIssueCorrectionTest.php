<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database,LegacyDatabase};
use App\Services\{ArpaAppointmentDataIssueCorrectionService,ArpaAppointmentReadService,OfficerProfileService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentDataIssueCorrectionTest
{
    private PDO $pdo;private int $assertions=0;private string $actor;private string $asc;private string $outsideAsc;private array $divisions=[];private string $officer;

    public function run():int
    {
        $this->pdo=Database::pdo();$before=$this->state();$legacyBefore=$this->legacyState();
        $this->actor=(string)$this->scalar("SELECT id FROM system_user WHERE username='asctest' AND enabled=1 AND account_status='ACTIVE'");
        if($this->actor==='')throw new RuntimeException('Operational asctest fixture is required.');
        $context=$this->pdo->query("SELECT uar.id role_assignment_id,uas.id scope_assignment_id FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id AND r.role_code='ASC_SUBJECT_OFFICER' JOIN user_account_scope uas ON uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id JOIN location l ON l.id=uas.location_id AND l.dad_number='70004-0000389' WHERE uar.user_id='{$this->actor}' AND uar.active=1 AND uar.approval_status='APPROVED' AND uas.active=1 AND uas.approval_status='APPROVED' LIMIT 1")->fetch();if(!$context)throw new RuntimeException('asctest ASC Subject Officer context is required.');$_SESSION=['user_id'=>$this->actor,'authenticated_at'=>time(),'last_activity_at'=>time()];(new UserContextService($this->pdo))->select($this->actor,(string)$context['role_assignment_id'],(string)$context['scope_assignment_id']);Auth::forgetRequestCache();
        $this->pdo->beginTransaction();
        try{$this->fixtures();$this->authorization();$this->multipleOpenCorrection();$this->dependentCorrection();$this->historicalReview();$this->crossAscGroupIsReadOnly();$this->rollbackAndWorkflowBoundary();$this->staticCoverage();}
        finally{$this->pdo->rollBack();}
        $this->same($before,$this->state(),'correction test leaves target appointment, access, and audit state unchanged');
        $this->same($legacyBefore,$this->legacyState(),'correction test never modifies the legacy source database');
        echo "ArpaAppointmentDataIssueCorrectionTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function fixtures():void
    {
        $this->asc=(string)$this->scalar("SELECT uas.location_id FROM user_account_scope uas JOIN location l ON l.id=uas.location_id JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC' WHERE uas.user_id=? AND uas.scope_type='ASC' AND uas.scope_mode='EXACT' AND uas.active=1 AND uas.approval_status='APPROVED' LIMIT 1",[$this->actor]);
        $read=new ArpaAppointmentReadService($this->pdo);$this->divisions=array_column($read->vacantDivisionsForAsc($this->actor,$this->asc,date('Y-m-d')),'id');
        if(count($this->divisions)<4)throw new RuntimeException('Four vacant ARPA Divisions are required for correction tests.');
        $this->officer=$this->newOfficer();
    }

    private function authorization():void
    {
        $service=new ArpaAppointmentDataIssueCorrectionService($this->pdo);
        $this->same(true,$service->canCorrect($this->actor,$this->asc),'ASC Subject Officer with active approved ASC EXACT scope can correct own-ASC issues');
        $viewer=$this->actor('ASC_VIEWER',$this->asc);$this->same(false,$service->canCorrect($viewer,$this->asc),'ASC Viewer cannot correct data issues');
        $this->outsideAsc=(string)$this->scalar("SELECT l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC' WHERE l.id<>? AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' LIMIT 1",[$this->asc]);
        $outsideSubject=$this->actor('ASC_SUBJECT_OFFICER',$this->outsideAsc);$this->same(false,$service->canCorrect($outsideSubject,$this->asc),'ASC Subject Officer cannot correct another ASC');
        $this->same(1,(int)$this->scalar("SELECT COUNT(*) FROM application_role_permission rp JOIN application_role r ON r.id=rp.role_id JOIN application_permission p ON p.id=rp.permission_id WHERE r.role_code='ASC_SUBJECT_OFFICER' AND p.permission_key=?",[ArpaAppointmentDataIssueCorrectionService::PERMISSION]),'direct-correction permission is mapped to ASC Subject Officer');
        $this->same(0,(int)$this->scalar("SELECT COUNT(*) FROM application_role_permission rp JOIN application_role r ON r.id=rp.role_id JOIN application_permission p ON p.id=rp.permission_id WHERE r.role_code='ASC_VIEWER' AND p.permission_key=?",[ArpaAppointmentDataIssueCorrectionService::PERMISSION]),'direct-correction permission is not mapped to ASC Viewer');
    }

    private function multipleOpenCorrection():void
    {
        $metadata=['source_table'=>'tbl_officer_apoint','source_row_id'=>910001,'source_references'=>['fixture:910001'],'legacy_location_id'=>1234];
        $first=$this->legacyAppointment($this->divisions[0],'PERMANENT','2018-06-10',$metadata);$second=$this->legacyAppointment($this->divisions[0],'ACTING','2025-02-01',$metadata+['source_row_id'=>910002]);
        $rowKey='DIVISION_MULTIPLE_OPEN:'.$this->divisions[0];$service=new ArpaAppointmentDataIssueCorrectionService($this->pdo);
        $this->same('DIVISION_MULTIPLE_OPEN',$service->issue($rowKey)['issue_type']??null,'multiple-open fixture appears in diagnostics');
        $detail=$service->detail($rowKey,$this->actor);$this->same(2,count($detail['appointments']),'grouped issue displays every involved appointment side-by-side');$this->same(true,$detail['correctable'],'own-ASC grouped issue is directly correctable');
        $originBefore=(string)$this->scalar('SELECT origin_metadata_json FROM arpa_division_appointment WHERE id=?',[$second]);$workflowBefore=(int)$this->scalar('SELECT COUNT(*) FROM arpa_appointment_workflow_action');$requestCount=(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_request');$appointmentCount=(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment');
        $this->throws(fn()=>$service->correct($rowKey,['correction_action'=>'MARK_HISTORICAL_ONLY','appointment_id'=>$second,'correction_reason'=>'  '],$this->actor),'blank correction reason is rejected');
        $result=$service->correct($rowKey,['correction_action'=>'MARK_HISTORICAL_ONLY','appointment_id'=>$second,'correction_reason'=>'Duplicate open legacy appointment','remarks'=>'Evidence confirms the Acting row is historical.'],$this->actor);
        $this->same('RESOLVED_BY_CORRECTION',$result['resolution_status'],'genuine multiple-open correction is recorded as resolved');$this->same(false,$result['issue_remaining'],'resolved multiple-open condition disappears after diagnostic recalculation');$this->same(null,$service->issue($rowKey),'resolved issue is absent from the current diagnostic source');
        $this->same(1,(int)$this->scalar('SELECT legacy_history_only FROM arpa_division_appointment WHERE id=?',[$second]),'invalid open legacy row remains preserved as historical-only');$this->same(0,(int)$this->scalar('SELECT legacy_history_only FROM arpa_division_appointment WHERE id=?',[$first]),'selected genuine current row remains operational');
        $this->same($originBefore,(string)$this->scalar('SELECT origin_metadata_json FROM arpa_division_appointment WHERE id=?',[$second]),'original legacy origin metadata remains byte-for-byte unchanged');
        $this->same($workflowBefore,(int)$this->scalar('SELECT COUNT(*) FROM arpa_appointment_workflow_action'),'direct correction creates no appointment workflow event');$this->same($requestCount,(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_request'),'direct correction creates no appointment request');$this->same($appointmentCount,(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment'),'direct correction creates no appointment');
        $ledger=$this->row('SELECT * FROM arpa_appointment_data_correction WHERE id=?',[$result['correction_id']]);$this->same('APPOINTMENT_DATA_ISSUE',$ledger['source'],'correction ledger records the dedicated source');$this->same(true,str_contains((string)$ledger['before_json'],'legacy_history_only'),'before JSON is retained');$this->same(true,str_contains((string)$ledger['after_json'],'legacy_history_only'),'after JSON is retained');
        $this->same(1,(int)$this->scalar("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='arpa.appointment.data-issue.correct'",[$result['correction_id']]),'direct correction writes an audit event');
        $profile=(new OfficerProfileService($this->pdo))->profile($this->officer,[],[$this->asc]);$this->same(true,in_array($result['correction_id'],array_column($profile['appointment_corrections'],'id'),true),'Officer Profile service exposes correction history');
        $this->same(1,count($service->correctionsForAppointment($second)),'appointment detail service exposes correction history');
    }

    private function dependentCorrection():void
    {
        $this->officer=$this->newOfficer();
        $appointment=$this->legacyAppointment($this->divisions[1],'DUTY_COVERING','2025-03-01',['source_table'=>'tbl_officer_apoint','source_row_id'=>920001]);$rowKey='DEPENDENT_WITHOUT_PERMANENT:'.$appointment;$service=new ArpaAppointmentDataIssueCorrectionService($this->pdo);
        $detail=$service->detail($rowKey,$this->actor);$this->same([],array_values(array_filter($detail['permanent_appointments'],fn($r)=>(int)$r['legacy_history_only']===0)),'dependent issue shows no qualifying operational Permanent evidence');
        $permanentBefore=(int)$this->scalar("SELECT COUNT(*) FROM arpa_division_appointment WHERE officer_id=? AND appointment_type='PERMANENT'",[$this->officer]);
        $result=$service->correct($rowKey,['correction_action'=>'MARK_HISTORICAL_ONLY','appointment_id'=>$appointment,'correction_reason'=>'Historical dependent record incorrectly left operational'],$this->actor);
        $this->same('RESOLVED_BY_CORRECTION',$result['resolution_status'],'dependent-without-Permanent can be resolved by correcting the historical operational representation');$this->same($permanentBefore,(int)$this->scalar("SELECT COUNT(*) FROM arpa_division_appointment WHERE officer_id=? AND appointment_type='PERMANENT'",[$this->officer]),'dependent correction does not manufacture a Permanent appointment');
    }

    private function historicalReview():void
    {
        $appointment=$this->legacyAppointment($this->divisions[2],'ACTING','2024-04-01',['source_table'=>'tbl_officer_apoint','source_row_id'=>930001]);$rowKey='DEPENDENT_WITHOUT_PERMANENT:'.$appointment;$service=new ArpaAppointmentDataIssueCorrectionService($this->pdo);
        $result=$service->correct($rowKey,['correction_action'=>'KEEP_AS_HISTORICAL_EXCEPTION','appointment_id'=>$appointment,'correction_reason'=>'Confirmed historical exception; no trustworthy correction is available'],$this->actor);
        $this->same('KEPT_HISTORICAL_EXCEPTION',$result['resolution_status'],'historical exception may be explicitly reviewed without falsifying data');$this->same(true,$result['issue_remaining'],'kept historical exception remains an underlying diagnostic fact');$this->same(0,(int)$this->scalar('SELECT legacy_history_only FROM arpa_division_appointment WHERE id=?',[$appointment]),'keep-as-exception action does not rewrite the appointment');
        $config=DataTableRegistry::definition('arpa-appointment-issues');$config['baseWhere'][]='q.row_key=?';$config['baseParams'][]=$rowKey;$response=(new DataTableQuery($this->pdo,$config,new DataTableRequest(['length'=>10,'filters'=>['category'=>'HISTORICAL_EXCEPTIONS']])))->response();$this->same(0,$response['recordsFiltered'],'reviewed historical exception leaves the active review queue without being called resolved');
        $resolved=DataTableRegistry::definition('arpa-appointment-corrections');$resolved['baseWhere'][]='c.id=?';$resolved['baseParams'][]=$result['correction_id'];$this->same(1,(new DataTableQuery($this->pdo,$resolved,new DataTableRequest(['length'=>10])))->response()['recordsFiltered'],'reviewed exception remains visible in resolved/reviewed audit list');
    }

    private function rollbackAndWorkflowBoundary():void
    {
        $first=$this->legacyAppointment($this->divisions[3],'ACTING','2025-05-10',['source_table'=>'tbl_officer_apoint','source_row_id'=>940001]);$this->legacyAppointment($this->divisions[3],'DUTY_COVERING','2025-05-10',['source_table'=>'tbl_officer_apoint','source_row_id'=>940002]);$rowKey='DIVISION_MULTIPLE_OPEN:'.$this->divisions[3];$service=new ArpaAppointmentDataIssueCorrectionService($this->pdo);$ledgerBefore=(int)$this->scalar('SELECT COUNT(*) FROM arpa_appointment_data_correction');$closureBefore=(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_closure');
        $this->throws(fn()=>$service->correct($rowKey,['correction_action'=>'SET_EFFECTIVE_TO','appointment_id'=>$first,'effective_to'=>'2020-01-01','correction_reason'=>'Invalid rollback test'],$this->actor),'invalid correction rolls back transaction work');
        $this->same($ledgerBefore,(int)$this->scalar('SELECT COUNT(*) FROM arpa_appointment_data_correction'),'failed correction writes no ledger row');$this->same($closureBefore,(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_closure'),'failed correction writes no closure');
        $this->throws(fn()=>$service->correct('DIVISION_MULTIPLE_OPEN:00000000-0000-0000-0000-000000000000',['correction_action'=>'MARK_HISTORICAL_ONLY','correction_reason'=>'Attempted bypass'],$this->actor),'normal or nonexistent business record cannot bypass workflow through correction service');
    }

    private function crossAscGroupIsReadOnly():void
    {
        $outsideDivision=(string)$this->scalar("SELECT lr.child_location_id FROM location_relationship lr JOIN location l ON l.id=lr.child_location_id JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ARPA_DIVISION' WHERE lr.parent_location_id=? AND lr.relationship_type='ASC_ARPA_DIVISION' AND lr.active=1 AND lr.approval_status='APPROVED' LIMIT 1",[$this->outsideAsc]);
        $this->legacyAppointment($outsideDivision,'ACTING','2024-04-01',['source_table'=>'tbl_officer_apoint','source_row_id'=>930002],$this->outsideAsc);$rowKey='OFFICER_MULTIPLE_ACTING:'.$this->officer;$service=new ArpaAppointmentDataIssueCorrectionService($this->pdo);$issue=$service->issue($rowKey);$this->same(true,$issue!==null,'cross-ASC Officer duplicate is diagnosed');
        $groupAsc=(string)$issue['asc_location_id'];$groupActor=$groupAsc===$this->asc?$this->actor:$this->actor('ASC_SUBJECT_OFFICER',$groupAsc);$detail=$service->detail($rowKey,$groupActor);$this->same(false,$detail['correctable'],'group spanning multiple ASCs is read-only for an ASC-scoped Subject Officer');
        $this->throws(fn()=>$service->correct($rowKey,['correction_action'=>'MARK_HISTORICAL_ONLY','appointment_id'=>$detail['appointments'][0]['id'],'correction_reason'=>'Cross-scope attempt'],$groupActor),'cross-ASC grouped issue cannot be changed through an aggregate ASC value');
    }

    private function staticCoverage():void
    {
        $routes=(string)file_get_contents(BASE_PATH.'/routes/web.php');foreach(['/hr/arpa-appointments/issues','/hr/arpa-appointments/issues/{key}/correct'] as $route)$this->same(true,str_contains($routes,$route),"{$route} route is registered");
        $migration=(string)file_get_contents(BASE_PATH.'/database/migrations/043_arpa_appointment_data_issue_corrections.sql');foreach(['arpa.appointment.data-issue.correct','ASC_SUBJECT_OFFICER','arpa_appointment_data_correction'] as $value)$this->same(true,str_contains($migration,$value),"migration contains {$value}");
        $service=(string)file_get_contents(BASE_PATH.'/app/Services/ArpaAppointmentDataIssueCorrectionService.php');$this->same(false,str_contains($service,'arpa_appointment_workflow_action')||str_contains($service,'ArpaAppointmentService'),'correction service is structurally isolated from normal appointment workflow');
        $profile=(string)file_get_contents(BASE_PATH.'/app/Views/officers/show.php');$this->same(true,str_contains($profile,'Appointment Data Correction History'),'Officer Profile renders correction history');
    }

    private function newOfficer():string
    {
        $id=$this->uuid();$status=(string)$this->scalar('SELECT id FROM officer_status WHERE active=1 ORDER BY display_order LIMIT 1');$this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,arpa_service_permanency,officer_status_id,effective_from,operational_status,approval_status,created_by,approved_by,approved_at) VALUES(?,?,?,'PERMANENT_IN_SERVICE',?,CURRENT_DATE(),'ACTIVE','APPROVED',?,?,NOW())")->execute([$id,'TEST-'.substr($id,0,8),'Data Correction Test Officer',$status,$this->actor,$this->actor]);return $id;
    }

    private function legacyAppointment(string $division,string $type,string $from,array $metadata,?string $asc=null):string
    {
        $asc=$asc?:$this->asc;$l=$this->row('SELECT a.dad_number asc_dad,a.name_en asc_name,d.dad_number arpa_dad,d.name_en arpa_name FROM location a JOIN location d ON d.id=? WHERE a.id=?',[$division,$asc]);$request=$this->uuid();$id=$this->uuid();$json=json_encode($metadata,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,legacy_exception,legacy_exception_codes_json,origin_metadata_json) VALUES(?,'LEGACY_IMPORT','APPOINTMENT',?,?,?,?,?,'NATIONAL_APPROVED',1,'[\"TEST_LEGACY_EXCEPTION\"]',?)")->execute([$request,$this->officer,$type,$asc,$division,$from,$json]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,service_permanency_snapshot,service_permanency_source,asc_location_id,arpa_division_location_id,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,legacy_exception,legacy_exception_codes_json,approval_timestamp_provenance,origin_metadata_json) VALUES(?,'LEGACY_IMPORT',?,?,?,'PERMANENT_IN_SERVICE','CURRENT_STATE_ONLY',?,?,?,?,?,?, '{}',?,1,'[\"TEST_LEGACY_EXCEPTION\"]','UNAVAILABLE_FROM_LEGACY_SOURCE',?)")->execute([$id,$request,$this->officer,$type,$asc,$division,$l['asc_dad'],$l['asc_name'],$l['arpa_dad'],$l['arpa_name'],$from,$json]);return $id;
    }

    private function actor(string $role,string $asc):string
    {
        $id=$this->uuid();$assignmentId=$this->uuid();$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,account_status,enabled) VALUES(?,'STAFF',?,'ACTIVE',1)")->execute([$id,'issue-'.strtolower($role).'-'.substr($id,0,6)]);$roleId=(string)$this->scalar('SELECT id FROM application_role WHERE role_code=?',[$role]);$this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason,created_by,approved_by,approved_at) VALUES(?,?,?,CURRENT_DATE(),'APPROVED',1,'Data issue authorization test',?,?,NOW())")->execute([$assignmentId,$id,$roleId,$this->actor,$this->actor]);$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason,created_by,approved_by,approved_at) VALUES(UUID(),?,?,'ASC','EXACT',?,CURRENT_DATE(),'APPROVED',1,'Data issue authorization test',?,?,NOW())")->execute([$id,$assignmentId,$asc,$this->actor,$this->actor]);return $id;
    }

    private function state():array{return ['requests'=>(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_request'),'appointments'=>(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment'),'closures'=>(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_closure'),'corrections'=>(int)$this->scalar('SELECT COUNT(*) FROM arpa_appointment_data_correction'),'audit'=>(int)$this->scalar('SELECT COUNT(*) FROM audit_event'),'users'=>(int)$this->scalar('SELECT COUNT(*) FROM system_user'),'roles'=>(int)$this->scalar('SELECT COUNT(*) FROM user_account_role'),'scopes'=>(int)$this->scalar('SELECT COUNT(*) FROM user_account_scope'),'decisions'=>(int)$this->scalar("SELECT COUNT(*) FROM legacy_arpa_appointment_resolution WHERE resolution_status='CONFIRMED'")];}
    private function legacyState():array{$legacy=LegacyDatabase::pdo();return ['officers'=>(int)$legacy->query('SELECT COUNT(*) FROM tbl_officer')->fetchColumn(),'appointments'=>(int)$legacy->query('SELECT COUNT(*) FROM tbl_officer_apoint')->fetchColumn()];}
    private function scalar(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function row(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function throws(callable $fn,string $message):void{$this->assertions++;try{$fn();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
}

exit((new ArpaAppointmentDataIssueCorrectionTest())->run());
