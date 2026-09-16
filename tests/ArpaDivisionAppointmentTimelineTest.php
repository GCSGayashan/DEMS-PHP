<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database};
use App\Controllers\ArpaAppointmentController;
use App\Services\{ArpaDivisionTimelineService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaDivisionAppointmentTimelineTest
{
    private PDO $pdo;
    private string $actor;
    private string $asc;
    private string $officer;
    private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();
        $this->pdo->beginTransaction();
        try{$this->exercise();}
        finally{$_SESSION=[];Auth::forgetRequestCache();$this->pdo->rollBack();}
        echo "ArpaDivisionAppointmentTimelineTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function exercise():void
    {
        $context=$this->row("SELECT su.id user_id,uar.id role_assignment_id,uas.id scope_assignment_id,uas.location_id
                            FROM system_user su
                            JOIN user_account_role uar ON uar.user_id=su.id
                            JOIN application_role r ON r.id=uar.role_id AND r.role_code='ASC_SUBJECT_OFFICER'
                            JOIN user_account_scope uas ON uas.role_assignment_id=uar.id AND uas.user_id=su.id
                            JOIN location l ON l.id=uas.location_id AND l.dad_number='70004-0000389'
                            WHERE su.username='asctest' AND uar.active=1 AND uar.approval_status='APPROVED'
                              AND uas.active=1 AND uas.approval_status='APPROVED' LIMIT 1");
        if(!$context)throw new RuntimeException('The asctest ASC context fixture is required.');
        $this->actor=(string)$context['user_id'];$this->asc=(string)$context['location_id'];
        $this->officer=$this->officer('Timeline Gap Officer');
        $this->authenticate($this->actor,(string)$context['role_assignment_id'],(string)$context['scope_assignment_id']);

        $gap=$this->division($this->asc,'Timeline Historical Gap');
        $first=$this->appointment($gap,'2025-01-01','2025-04-30',true);
        $second=$this->appointment($gap,'2025-07-01',null,true);
        $this->officer=$this->officer('Timeline Issue Officer');
        $issueDivision=$this->division($this->asc,'Timeline Data Issue');
        $issueAppointment=$this->appointment($issueDivision,'2025-01-01','2025-12-31',false);
        $empty=$this->division($this->asc,'Timeline Missing Baseline');
        $this->officer=$this->officer('Timeline Overlap Officer');
        $overlap=$this->division($this->asc,'Timeline Multiple Open');
        $this->appointment($overlap,'2025-01-01',null,true);
        $this->appointment($overlap,'2026-01-01',null,true);

        $definition=DataTableRegistry::definition('arpa-division-timelines');
        $this->same(true,$definition['export'],'timeline list supports scoped CSV export');
        $this->same(['ARPA Division DAD','ARPA Division','ASC','District','Timeline Status','Data Issues','Actions'],array_column($definition['columns'],'label'),'timeline list columns are stable');
        $compact=DataTableRegistry::definition('arpa-division-timelines',['asc_id'=>$this->asc]);
        $this->same(['ARPA Division DAD','ARPA Division','Timeline Status','Data Issues','Actions'],array_column($compact['columns'],'label'),'ASC drill-down list omits redundant hierarchy columns');
        $definition['baseWhere'][]='d.id=?';$definition['baseParams'][]=$gap;
        $page=(new DataTableQuery($this->pdo,$definition,new DataTableRequest(['draw'=>8,'length'=>10])))->response();
        $this->same(1,$page['recordsFiltered'],'ASC-scoped list returns its Division without an N+1 query');
        $this->same('Current - Has Uncovered Period',strip_tags((string)$page['data'][0]['timeline_status']),'list identifies the current timeline with an informational uncovered period');

        $_SERVER['REQUEST_URI']='/hr/arpa-appointments/timeline';$ascLanding=$this->render(fn()=>(new ArpaAppointmentController())->appointmentTimeline());
        $this->same(true,str_contains($ascLanding,'ARPA Division Timelines'),'ASC context opens the Division list directly');
        $this->same(true,str_contains($ascLanding,rawurlencode($this->asc))||str_contains($ascLanding,$this->asc),'ASC landing DataTable is fixed to the active ASC');

        $timeline=(new ArpaDivisionTimelineService($this->pdo))->timeline($gap,$this->actor);
        $this->same(2,$timeline['summary']['total_appointments'],'canonical appointments are not duplicated');
        $this->same(1,$timeline['summary']['missing_periods'],'bounded uncovered period is shown');
        $missing=array_values(array_filter($timeline['entries'],fn($row)=>$row['entry_kind']==='MISSING_PERIOD'));
        $this->same('2025-05-01',$missing[0]['effective_from'],'gap starts after the prior assignment');
        $this->same('2025-06-30',$missing[0]['effective_to'],'gap ends before the next assignment');
        $appointmentIds=array_column(array_values(array_filter($timeline['entries'],fn($row)=>$row['entry_kind']==='APPOINTMENT')),'appointment_id');
        $this->same([$first,$second],$appointmentIds,'appointment entries remain chronological');

        $emptyTimeline=(new ArpaDivisionTimelineService($this->pdo))->timeline($empty,$this->actor);
        $this->same('2025-01-01',$emptyTimeline['diagnostic']['required_next_start'],'empty Division begins at the canonical baseline');

        $issueTimeline=(new ArpaDivisionTimelineService($this->pdo))->timeline($issueDivision,$this->actor);
        $this->same(1,$issueTimeline['summary']['data_issues'],'unresolved canonical Data Issue appears on the same timeline');
        $issueEntry=array_values(array_filter($issueTimeline['entries'],fn($row)=>$row['entry_kind']==='DATA_ISSUE'))[0];
        $this->same('ENDED_APPOINTMENT_WITHOUT_END_REASON',$issueEntry['issue_type'],'issue retains its canonical correction key and type');
        $this->same(true,str_contains((string)$issueEntry['row_key'],$issueAppointment),'issue links to the affected canonical appointment');

        $overlapTimeline=(new ArpaDivisionTimelineService($this->pdo))->timeline($overlap,$this->actor);
        $this->same(true,in_array('MULTIPLE_OPEN_ASSIGNMENTS',$overlapTimeline['diagnostic']['timeline_statuses'],true),'multiple open appointments are diagnosed on the Division timeline');
        $this->same(true,in_array('OVERLAP',$overlapTimeline['diagnostic']['timeline_statuses'],true),'overlapping periods are diagnosed on the Division timeline');
        $this->same(true,in_array('DIVISION_MULTIPLE_OPEN',array_column($overlapTimeline['issues'],'issue_type'),true),'multiple-open Data Issue appears in the timeline issue stream');

        $_SERVER['REQUEST_URI']='/hr/arpa-appointments/timeline/'.$gap;extract($timeline,EXTR_SKIP);ob_start();require BASE_PATH.'/app/Views/arpa_appointments/timeline/detail.php';$html=(string)ob_get_clean();
        $this->same(true,str_contains($html,'Add Historical Appointment'),'authorized timeline exposes the existing historical appointment entry point');
        $this->same(true,str_contains($html,'effective_from=2025-05-01'),'historical action pre-fills the exact missing-period start');
        $this->same(true,str_contains($html,'effective_to=2025-06-30'),'historical action pre-fills the bounded missing-period end');
        $this->same(true,str_contains($html,'Uncovered Period'),'timeline labels a normal gap as informational uncovered history');
        $this->same(true,str_contains($html,'do not need to be filled completely'),'timeline explains that complete gap filling is not required');
        $this->same(true,str_contains($html,'arpa_division_location_id='),'historical action retains the selected Division');
        $this->same(true,str_contains($html,'Current'),'open effective appointment is labelled Current');
        $this->same(true,str_contains($html,'Historical / Ended'),'closed appointment is labelled Historical / Ended');

        extract($issueTimeline,EXTR_OVERWRITE);ob_start();require BASE_PATH.'/app/Views/arpa_appointments/timeline/detail.php';$issueHtml=(string)ob_get_clean();
        $this->same(true,str_contains($issueHtml,'Appointment Data Issue'),'Data Issue is visually distinct on the timeline');
        $this->same(true,str_contains($issueHtml,'/issues/'),'timeline reuses the existing Data Issue detail/correction route');

        $otherAsc=(string)$this->value("SELECT l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC'
                                       WHERE l.id<>? AND l.approval_status='APPROVED' AND l.operational_status='ACTIVE' LIMIT 1",[$this->asc]);
        $outside=$this->division($otherAsc,'Timeline Outside ASC');
        $this->throws(fn()=>(new ArpaDivisionTimelineService($this->pdo))->timeline($outside,$this->actor),'ASC direct detail cannot cross scope');
        $outsideDefinition=DataTableRegistry::definition('arpa-division-timelines');$outsideDefinition['baseWhere'][]='d.id=?';$outsideDefinition['baseParams'][]=$outside;
        $this->same(0,(new DataTableQuery($this->pdo,$outsideDefinition,new DataTableRequest(['length'=>10])))->response()['recordsFiltered'],'ASC Division list excludes an unrelated ASC');
        $forgedAscDefinition=DataTableRegistry::definition('arpa-division-timelines',['asc_id'=>$otherAsc]);
        $this->same(0,(new DataTableQuery($this->pdo,$forgedAscDefinition,new DataTableRequest(['length'=>10])))->response()['recordsTotal'],'ASC cannot widen the Division DataTable with a forged ASC context');

        $district=(string)$this->value("SELECT parent_location_id FROM location_relationship WHERE child_location_id=? AND relationship_type='DISTRICT_ASC'
                                       AND active=1 AND approval_status='APPROVED' AND effective_from<=CURRENT_DATE()
                                       AND (effective_to IS NULL OR effective_to>=CURRENT_DATE()) LIMIT 1",[$this->asc]);
        [$districtUser,$districtRole,$districtScope]=$this->scopedUser('timeline.district','DISTRICT_VIEWER','DISTRICT','INCLUDE_CHILDREN',$district);
        $this->authenticate($districtUser,$districtRole,$districtScope);
        $controllerSource=(string)file_get_contents(BASE_PATH.'/app/Controllers/ArpaAppointmentController.php');
        $this->same(true,str_contains($controllerSource,"if(\$level==='DISTRICT')")&&str_contains($controllerSource,'renderTimelineAscSummary($district)'),'District root flow selects ASC summary before any Division list');
        $this->same($gap,(new ArpaDivisionTimelineService($this->pdo))->timeline($gap,$districtUser)['division']['id'],'District context sees descendant ASC Division timeline');
        $districtDefinition=DataTableRegistry::definition('arpa-division-timelines');$districtDefinition['baseWhere'][]='d.id=?';$districtDefinition['baseParams'][]=$gap;
        $this->same(1,(new DataTableQuery($this->pdo,$districtDefinition,new DataTableRequest(['length'=>10])))->response()['recordsFiltered'],'District Division list includes a permitted descendant ASC');
        $ascSummaryDefinition=DataTableRegistry::definition('arpa-division-timeline-asc-summary',['district_id'=>$district]);$ascSummaryDefinition['baseWhere'][]='d.asc_location_id=?';$ascSummaryDefinition['baseParams'][]=$this->asc;
        $ascSummary=(new DataTableQuery($this->pdo,$ascSummaryDefinition,new DataTableRequest(['length'=>10])))->response();
        $this->same(1,$ascSummary['recordsFiltered'],'District ASC summary returns its scoped ASC once');
        $this->same(true,(int)$ascSummary['data'][0]['gap_count']>=2,'ASC summary aggregates canonical baseline and historical-gap statuses');
        $this->same(true,(int)$ascSummary['data'][0]['data_issue_count']>=1,'ASC summary aggregates unresolved Data Issues');
        $this->same(true,str_contains((string)$ascSummary['data'][0]['actions'],'/timeline/asc/'),'District ASC summary drills through the District-authorized route');
        $districtDrill=DataTableRegistry::definition('arpa-division-timelines',['asc_id'=>$this->asc]);
        $this->same(true,in_array('d.asc_location_id=?',$districtDrill['baseWhere'],true),'District ASC drill-down fixes the Division query to the selected ASC');
        $otherDistrictAsc=(string)$this->value("SELECT lr.child_location_id FROM location_relationship lr JOIN location l ON l.id=lr.child_location_id
                                               WHERE lr.relationship_type='DISTRICT_ASC' AND lr.parent_location_id<>? AND lr.active=1
                                                 AND lr.approval_status='APPROVED' AND lr.effective_from<=CURRENT_DATE()
                                                 AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())
                                                 AND l.approval_status='APPROVED' AND l.operational_status='ACTIVE' LIMIT 1",[$district]);
        $crossDistrict=DataTableRegistry::definition('arpa-division-timelines',['asc_id'=>$otherDistrictAsc]);
        $this->same(0,(new DataTableQuery($this->pdo,$crossDistrict,new DataTableRequest(['length'=>10])))->response()['recordsTotal'],'District cannot obtain Divisions for an ASC in another District');

        [$nationalUser,$nationalRole,$nationalScope]=$this->scopedUser('timeline.national','NATIONAL_VIEWER','NATIONAL','NATIONAL',null);
        $this->authenticate($nationalUser,$nationalRole,$nationalScope);
        $this->same(true,str_contains($controllerSource,"in_array(\$level,['NATIONAL','SYSTEM'],true)")&&str_contains($controllerSource,'arpa-division-timeline-district-summary'),'National and System root flow selects District summary first');
        $this->same($outside,(new ArpaDivisionTimelineService($this->pdo))->timeline($outside,$nationalUser)['division']['id'],'National context sees an enterprise Division timeline');
        $nationalDefinition=DataTableRegistry::definition('arpa-division-timelines');$nationalDefinition['baseWhere'][]='d.id=?';$nationalDefinition['baseParams'][]=$outside;
        $this->same(1,(new DataTableQuery($this->pdo,$nationalDefinition,new DataTableRequest(['length'=>10])))->response()['recordsFiltered'],'National Division list includes a permitted enterprise Division');
        $districtSummaryDefinition=DataTableRegistry::definition('arpa-division-timeline-district-summary');$districtSummaryDefinition['baseWhere'][]='d.district_location_id=?';$districtSummaryDefinition['baseParams'][]=$district;
        $districtSummary=(new DataTableQuery($this->pdo,$districtSummaryDefinition,new DataTableRequest(['length'=>10])))->response();
        $this->same(1,$districtSummary['recordsFiltered'],'National District summary returns the selected District once');
        $this->same(true,(int)$districtSummary['data'][0]['total_divisions']>0,'District summary aggregates Division counts without loading timelines');
        $nationalAscDefinition=DataTableRegistry::definition('arpa-division-timeline-asc-summary',['district_id'=>$district,'drill_level'=>'NATIONAL']);$nationalAscDefinition['baseWhere'][]='d.asc_location_id=?';$nationalAscDefinition['baseParams'][]=$this->asc;
        $nationalAsc=(new DataTableQuery($this->pdo,$nationalAscDefinition,new DataTableRequest(['length'=>10])))->response();
        $this->same(true,str_contains((string)$nationalAsc['data'][0]['actions'],'/timeline/district/'.$district.'/asc/'),'National District summary drills to ASC through the explicit District route');
        $this->same(true,str_contains($controllerSource,'districtContainsAsc($districtId,$ascId)'),'National ASC drill-down validates the selected District/ASC relationship server-side');

        $routes=(string)file_get_contents(BASE_PATH.'/routes/web.php');$tabs=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/tabs.php');
        $this->same(true,str_contains($routes,"/hr/arpa-appointments/timeline/{id}"),'timeline detail route is registered');
        $this->same(true,str_contains($tabs,'Appointment Timeline'),'timeline is visible within the ARPA Assignment module');
    }

    private function division(string $asc,string $name):string
    {
        if($asc==='')throw new RuntimeException('An ASC is required.');
        $id=$this->uuid();$type=(string)$this->value("SELECT id FROM location_type WHERE system_key='ARPA_DIVISION'");
        $this->pdo->prepare("INSERT INTO location(id,dad_number,location_type_id,name_en,effective_from,operational_status,approval_status) VALUES(?,?,?,?,?,'ACTIVE','APPROVED')")
            ->execute([$id,'TEST-'.substr(str_replace('-','',$id),0,12),$type,$name,'2025-01-01']);
        $this->pdo->prepare("INSERT INTO location_relationship(id,parent_location_id,child_location_id,relationship_type,effective_from,approval_status,active) VALUES(?,?,?,'ASC_ARPA_DIVISION','2025-01-01','APPROVED',1)")
            ->execute([$this->uuid(),$asc,$id]);
        return $id;
    }

    private function appointment(string $division,string $from,?string $to,bool $withReason):string
    {
        $request=$this->uuid();$appointment=$this->uuid();$historyOnly=$to===null?0:1;
        $location=$this->row('SELECT a.dad_number asc_dad,a.name_en asc_name,d.dad_number arpa_dad,d.name_en arpa_name FROM location a JOIN location d ON d.id=? WHERE a.id=?',[$division,$this->asc]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,legacy_history_only,created_by,finalized_by,finalized_at) VALUES(?,'LEGACY_IMPORT','APPOINTMENT',?,'PERMANENT',?,?,?,'DISTRICT_APPROVED',?,?,?,NOW())")
            ->execute([$request,$this->officer,$this->asc,$division,$from,$historyOnly,$this->actor,$this->actor]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,service_permanency_snapshot,service_permanency_source,asc_location_id,arpa_division_location_id,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,approved_by,approved_at,approval_timestamp_provenance,legacy_history_only) VALUES(?,'LEGACY_IMPORT',?,?,'PERMANENT','PERMANENT_IN_SERVICE','EXACT_PERMANENTED_DATE',?,?,?,?,?,?,'{}',?,?,NULL,'UNAVAILABLE_FROM_LEGACY_SOURCE',?)")
            ->execute([$appointment,$request,$this->officer,$this->asc,$division,$location['asc_dad'],$location['asc_name'],$location['arpa_dad'],$location['arpa_name'],$from,$this->actor,$historyOnly]);
        if($to!==null){$reason=$withReason?(string)$this->value('SELECT id FROM arpa_appointment_end_reason ORDER BY display_order LIMIT 1'):null;$this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_kind,context_snapshot_json,approved_by,approved_at,approval_timestamp_provenance) VALUES(?,'LEGACY_IMPORT',?,?,?,?,'DIRECT','{}',?,NULL,'UNAVAILABLE_FROM_LEGACY_SOURCE')")->execute([$this->uuid(),$appointment,$request,$to,$reason,$this->actor]);}
        return $appointment;
    }

    private function officer(string $name):string
    {
        $id=$this->uuid();$status=(string)$this->value("SELECT id FROM officer_status WHERE active=1 ORDER BY display_order LIMIT 1");$office=(string)$this->value('SELECT id FROM office WHERE linked_location_id=? AND approval_status=\'APPROVED\' AND operational_status=\'ACTIVE\' LIMIT 1',[$this->asc]);
        $this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,officer_status_id,effective_from,operational_status,approval_status,arpa_service_permanency) VALUES(?,?,?,?,'2025-01-01','ACTIVE','APPROVED','PERMANENT_IN_SERVICE')")
            ->execute([$id,'TEST-'.substr(str_replace('-','',$id),0,12),$name,$status]);
        $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,is_primary,active,approval_status,reason,created_by,approved_by,approved_at) VALUES(?,?,?,'2025-01-01',1,1,'APPROVED','Timeline test',?,?,NOW())")
            ->execute([$this->uuid(),$id,$office,$this->actor,$this->actor]);
        return $id;
    }

    private function scopedUser(string $username,string $roleCode,string $scopeType,string $scopeMode,?string $location):array
    {
        $user=$this->uuid();$roleAssignment=$this->uuid();$scopeAssignment=$this->uuid();$role=(string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$roleCode]);
        $this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$user,$username,$username]);
        $this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(?,?,?,'2025-01-01','APPROVED',1,'Timeline test')")->execute([$roleAssignment,$user,$role]);
        $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(?,?,?,?,?,?,'2025-01-01','APPROVED',1,'Timeline test')")->execute([$scopeAssignment,$user,$roleAssignment,$scopeType,$scopeMode,$location]);
        return [$user,$roleAssignment,$scopeAssignment];
    }

    private function authenticate(string $user,string $role,string $scope):void{$_SESSION=['user_id'=>$user,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();(new UserContextService($this->pdo))->select($user,$role,$scope);Auth::forgetRequestCache();}
    private function render(callable $callback):string{http_response_code(200);ob_start();$callback();return (string)ob_get_clean();}
    private function row(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
}

exit((new ArpaDivisionAppointmentTimelineTest())->run());
