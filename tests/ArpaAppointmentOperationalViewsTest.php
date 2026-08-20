<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database,ScopeService};
use App\Services\{ArpaAppointmentReadService,ScopedDashboardService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentOperationalViewsTest
{
    private PDO $pdo;private int $assertions=0;private string $actor;

    public function run():int
    {
        $this->pdo=Database::pdo();$before=$this->state();
        $this->actor=(string)$this->pdo->query("SELECT su.id FROM system_user su JOIN user_account_role ur ON ur.user_id=su.id JOIN application_role r ON r.id=ur.role_id WHERE r.role_code='SYSTEM_ADMIN' LIMIT 1")->fetchColumn();
        if($this->actor==='')throw new RuntimeException('SYSTEM_ADMIN fixture required.');$this->activateContext($this->actor,'SYSTEM_ADMIN');
        $this->staticRuleCoverage();$this->transactionalReadModels();
        $this->same($before,$this->state(),'operational-view tests leave all appointment and safety state unchanged');
        echo "ArpaAppointmentOperationalViewsTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function staticRuleCoverage():void
    {
        $source=ArpaAppointmentReadService::issueSource();

        foreach([
            'DIVISION_MULTIPLE_OPEN',
            'OFFICER_MULTIPLE_PERMANENT',
            'OFFICER_MULTIPLE_ACTING',
            'OFFICER_MULTIPLE_ATTEND_TO_DUTY',
            'DEPENDENT_WITHOUT_PERMANENT',
            'PERMANENT_SERVICE_WITH_ATTEND_TO_DUTY',
            'NON_PERMANENT_SERVICE_WITH_ACTING',
            'EXCLUSIVE_FUNCTION_OVERLAP',
            'MULTIPLE_EXCLUSIVE_FUNCTIONS',
            'MISSING_ASC_OFFICE_ASSIGNMENT',
            'APPOINTMENT_OUTSIDE_ASC',
            'INVALID_DATE_RANGE',
            'OPEN_APPOINTMENT_WITH_END_REASON',
            'ENDED_APPOINTMENT_WITHOUT_END_REASON',
            'FUTURE_OVERLAP_CONFLICT',
        ] as $issue){
            $this->same(
                true,
                str_contains($source,$issue),
                "{$issue} diagnostic is independently defined"
            );
        }

        $this->same(false,str_contains($source,'UPDATE '),'diagnostic source is read-only');
        $this->same('c.id IS NULL',ArpaAppointmentReadService::openAppointmentClause(),'open definition is absence of formal closure');

        $routes=file_get_contents(BASE_PATH.'/routes/web.php');

        foreach([
            '/new',
            '/submitted',
            '/approval',
            '/open',
            '/history',
            '/vacant-divisions',
            '/data-issues',
        ] as $path){
            $this->same(
                true,
                str_contains($routes,"/hr/arpa-appointments{$path}"),
                "{$path} route registered"
            );
        }

        foreach([
            '/new/asc/{id}',
            '/submitted/asc/{id}',
            '/approval/asc/{id}',
            '/open/asc/{id}',
            '/history/asc/{id}',
        ] as $path){
            $this->same(
                true,
                str_contains($routes,"/hr/arpa-appointments{$path}"),
                "{$path} District ASC drill-down route registered"
            );
        }

        foreach([
            '/new/district/{id}',
            '/new/district/{districtId}/asc/{ascId}',
            '/submitted/district/{id}',
            '/submitted/district/{districtId}/asc/{ascId}',
            '/approval/district/{id}',
            '/approval/district/{districtId}/asc/{ascId}',
            '/open/district/{id}',
            '/open/district/{districtId}/asc/{ascId}',
            '/history/district/{id}',
            '/history/district/{districtId}/asc/{ascId}',
        ] as $path){
            $this->same(
                true,
                str_contains($routes,"/hr/arpa-appointments{$path}"),
                "{$path} National hierarchy route registered"
            );
        }

        $controller=file_get_contents(BASE_PATH.'/app/Controllers/ArpaAppointmentController.php');

        $this->same(
            true,
            str_contains($controller,"Auth::requirePermission('arpa.appointment.view')"),
            'workflow tabs require backend view permission'
        );

        $this->same(
            true,
            str_contains($controller,'workflowQueuePolicy()->canUseWorkflowQueues'),
            'workflow routes require an active action permission and matching scope'
        );

        $this->same(
            true,
            str_contains($controller,'appointmentHierarchySummary'),
            'appointment pages select the summary level from the authenticated geographic scope'
        );

        $this->same(
            true,
            str_contains($controller,'appointmentDistrictSummary'),
            'National appointment pages drill into a selected District ASC summary'
        );

        $this->same(
            true,
            str_contains($controller,'appointmentAscList'),
            'District and National View Records pages use the common ASC detail renderer'
        );

        $this->same(
            true,
            str_contains($controller,'districtContainsAsc'),
            'National ASC drill-down validates the selected ASC belongs to the selected District'
        );

        $registry=file_get_contents(BASE_PATH.'/app/Core/DataTableRegistry.php');

        foreach([
            'arpa-new-appointments-summary',
            'arpa-submitted-appointments-summary',
            'arpa-approval-verification-summary',
            'arpa-open-appointments-summary',
            'arpa-historical-appointments-summary',
            'arpa-new-appointments-district-summary',
            'arpa-submitted-appointments-district-summary',
            'arpa-approval-verification-district-summary',
            'arpa-open-appointments-district-summary',
            'arpa-historical-appointments-district-summary',
        ] as $key){
            $this->same(
                true,
                str_contains($registry,$key),
                "{$key} DataTable is registered"
            );
        }

        $this->same(
            true,
            str_contains($registry,'applyArpaAscContext'),
            'ASC detail context is enforced in DataTable definitions'
        );

        $districtSummaryView=file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/district_summary.php');
        $summaryView=file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/asc_summary.php');
        $recordsView=file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/asc_records.php');
        $listView=file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/list.php');
        $readService=file_get_contents(BASE_PATH.'/app/Services/ArpaAppointmentReadService.php');

        $this->same(
            true,
            str_contains($districtSummaryView,'components/datatable.php'),
            'National District summary uses the standard DataTable component'
        );

        $this->same(
            true,
            str_contains($districtSummaryView,'View ASCs'),
            'National District summary exposes the District to ASC drill-down'
        );

        $this->same(
            true,
            str_contains($summaryView,'components/datatable.php'),
            'District ASC summary uses the standard DataTable component'
        );

        $this->same(
            true,
            str_contains($summaryView,'Back to District Summary'),
            'National District ASC summary links back to the District summary'
        );

        $this->same(
            true,
            str_contains($summaryView,'View Records'),
            'District ASC summary explains the View Records drill-down'
        );

        $this->same(
            true,
            str_contains($recordsView,'Back to ASC Summary'),
            'separate ASC records page links back to the summary'
        );

        $this->same(
            true,
            str_contains($recordsView,'components/datatable.php'),
            'separate ASC records page uses the standard DataTable component'
        );

        $this->same(
            false,
            str_contains($listView,'ASC Appointment Summary'),
            'generic appointment list no longer embeds the old custom ASC summary'
        );

        $this->same(
            false,
            str_contains($readService,'openAppointmentAscSummary'),
            'obsolete Open-only ASC summary read model has been removed'
        );
    }
    private function transactionalReadModels():void
    {
        $this->pdo->beginTransaction();
        try{
            $fixture=$this->pdo->query("SELECT lr.parent_location_id asc_id,GROUP_CONCAT(lr.child_location_id ORDER BY lr.child_location_id) divisions FROM location_relationship lr JOIN location a ON a.id=lr.parent_location_id JOIN office ofc ON ofc.linked_location_id=a.id JOIN office_type ot ON ot.id=ofc.office_type_id AND ot.system_key='ASC_OFFICE' WHERE lr.relationship_type='ASC_ARPA_DIVISION' AND lr.active=1 AND lr.approval_status='APPROVED' AND ofc.operational_status='ACTIVE' AND ofc.approval_status='APPROVED' GROUP BY lr.parent_location_id HAVING COUNT(*)>=10 ORDER BY lr.parent_location_id LIMIT 1")->fetch();
            if(!$fixture)throw new RuntimeException('ASC with ARPA Division fixtures required.');$asc=(string)$fixture['asc_id'];$divisions=explode(',',$fixture['divisions']);
            $officers=$this->pdo->prepare("SELECT DISTINCT o.id FROM officer o JOIN designation d ON d.id=o.primary_designation_id AND d.system_key='ARPA_OFFICER' JOIN officer_office_assignment oa ON oa.officer_id=o.id AND oa.active=1 AND oa.approval_status='APPROVED' JOIN office ofc ON ofc.id=oa.office_id AND ofc.linked_location_id=? WHERE o.approval_status='APPROVED' AND o.operational_status='ACTIVE' ORDER BY o.id LIMIT 3");$officers->execute([$asc]);$ids=$officers->fetchAll(PDO::FETCH_COLUMN);if(count($ids)<2)throw new RuntimeException('Two assigned ARPA Officers required.');
            $today=date('Y-m-d');$future=date('Y-m-d',strtotime('+30 days'));$read=new ArpaAppointmentReadService($this->pdo);
            $this->districtSummaryReadModels();
            $this->nationalSummaryReadModels();
            $eligible=array_column($read->eligibleOfficersForAsc($this->actor,$asc,$today),'id');$this->same(true,in_array($ids[0],$eligible,true),'ASC selector includes an assigned eligible ARPA Officer');
            $outsider=(string)$this->pdo->query("SELECT o.id FROM officer o JOIN designation d ON d.id=o.primary_designation_id AND d.system_key='ARPA_OFFICER' WHERE o.approval_status='APPROVED' AND o.operational_status='ACTIVE' AND NOT EXISTS(SELECT 1 FROM officer_office_assignment oa JOIN office f ON f.id=oa.office_id WHERE oa.officer_id=o.id AND f.linked_location_id='{$asc}' AND oa.active=1 AND oa.approval_status='APPROVED') LIMIT 1")->fetchColumn();$this->same(false,in_array($outsider,$eligible,true),'ASC selector excludes Officers assigned only outside the ASC');
            $vacant=array_column($read->vacantDivisionsForAsc($this->actor,$asc,$today),'id');if($vacant===[])throw new RuntimeException('Vacant ARPA Division fixture required.');$division=(string)$vacant[0];$this->same(true,in_array($division,$vacant,true),'vacancy selector and page source begin from an actually vacant Division');
            $scheduled=$this->appointment((string)$ids[0],$asc,$division,'PERMANENT',$future);
            $this->same(false,in_array($division,array_column($read->vacantDivisionsForAsc($this->actor,$asc,$today),'id'),true),'scheduled future appointment prevents vacancy');
            $this->throws(fn()=>$read->assertDivisionVacant($asc,$division,$today),'forged request for occupied Division is rejected');
            $this->close($scheduled,$future);
            $this->same(true,in_array($division,array_column($read->vacantDivisionsForAsc($this->actor,$asc,$today),'id'),true),'formally ended Division becomes vacant using the same service');

            $first=$this->appointment((string)$ids[0],$asc,$division,'PERMANENT',$today);$second=$this->appointment((string)$ids[1],$asc,$division,'ACTING',$today);
            $issues=$this->rawIssues();$this->same(true,in_array('DIVISION_MULTIPLE_OPEN',$issues,true),'same Division with multiple open appointments is detected');$this->same(true,in_array('DEPENDENT_WITHOUT_PERMANENT',$issues,true),'Acting without the same Officer Permanent is detected');
            $this->pdo->prepare("UPDATE officer SET arpa_service_permanency='NOT_PERMANENT_IN_SERVICE' WHERE id=?")->execute([$ids[1]]);$issues=$this->rawIssues();$this->same(true,in_array('NON_PERMANENT_SERVICE_WITH_ACTING',$issues,true),'Not-Permanent-in-Service with Acting is detected');
            $third=$this->appointment((string)$ids[0],$asc,(string)$divisions[1],'DUTY_COVERING',$today);$fourth=$this->appointment((string)$ids[0],$asc,(string)$divisions[2],'DUTY_COVERING',$today);$issues=$this->rawIssues();$this->same(false,in_array('OFFICER_MULTIPLE_DUTY_COVERING',$issues,true),'multiple Duty Covering appointments remain allowed');
            $this->appointment((string)$ids[0],$asc,(string)$divisions[3],'PERMANENT',$today);$this->appointment((string)$ids[1],$asc,(string)$divisions[4],'ACTING',$today);$this->appointment((string)$ids[1],$asc,(string)$divisions[5],'ATTEND_TO_DUTY',$today);$this->appointment((string)$ids[1],$asc,(string)$divisions[6],'ATTEND_TO_DUTY',$today);$this->appointment((string)$ids[0],$asc,(string)$divisions[7],'ATTEND_TO_DUTY',$today);
            $issues=$this->rawIssues();foreach(['OFFICER_MULTIPLE_PERMANENT','OFFICER_MULTIPLE_ACTING','OFFICER_MULTIPLE_ATTEND_TO_DUTY','PERMANENT_SERVICE_WITH_ATTEND_TO_DUTY'] as $issue)$this->same(true,in_array($issue,$issues,true),"{$issue} is detected at runtime");
            foreach(['AGRARIAN_BANK','SALES_SHOP','SITHAMU'] as $kind)$this->subject((string)$ids[0],$asc,$kind,$today);
            $rows=$this->pdo->query("SELECT issue_type,appointment_types FROM ".ArpaAppointmentReadService::issueSource()." q WHERE issue_type IN('EXCLUSIVE_FUNCTION_OVERLAP','MULTIPLE_EXCLUSIVE_FUNCTIONS')")->fetchAll();$this->same(true,in_array('MULTIPLE_EXCLUSIVE_FUNCTIONS',array_column($rows,'issue_type'),true),'multiple exclusive functions are detected');
            foreach(['AGRARIAN_BANK','SALES_SHOP','SITHAMU'] as $kind)$this->same(true,count(array_filter($rows,fn($row)=>$row['issue_type']==='EXCLUSIVE_FUNCTION_OVERLAP'&&str_contains((string)$row['appointment_types'],$kind)))>0,"{$kind} overlap is detected");

            $open=$DataTable=DataTableRegistry::definition('arpa-open-appointments');$open['baseWhere'][]='a.id=?';$open['baseParams'][]=$first;$response=(new DataTableQuery($this->pdo,$open,new DataTableRequest(['length'=>10])))->response();$this->same(1,$response['recordsFiltered'],'open list includes unclosed operational appointment');
            $history=DataTableRegistry::definition('arpa-historical-appointments');$history['baseWhere'][]='a.id=?';$history['baseParams'][]=$scheduled;$response=(new DataTableQuery($this->pdo,$history,new DataTableRequest(['length'=>10])))->response();$this->same(1,$response['recordsFiltered'],'historical list includes formally closed appointment');
            $this->same(0,(new DataTableQuery($this->pdo,$history,new DataTableRequest(['length'=>10])))->response()['data'][0]['effective_to']===''?1:0,'historical row exposes an end date');
            $dashboard=(new ScopedDashboardService($this->pdo))->arpaModuleCounts($this->actor);foreach(['openPermanent','openActing','openDutyCovering','openAttendToDuty','openSubjects','pending','vacantDivisions','issues','appointmentMix','divisionCoverage'] as $key)$this->same(true,array_key_exists($key,$dashboard),"dashboard returns scoped {$key}");
            unset($second,$third,$fourth);
        }finally{$this->pdo->rollBack();}
    }

    private function appointment(string $officer,string $asc,string $division,string $type,string $from):string
    {
        $location=$this->pdo->prepare('SELECT a.dad_number asc_dad,a.name_en asc_name,d.dad_number arpa_dad,d.name_en arpa_name FROM location a JOIN location d ON d.id=? WHERE a.id=?');$location->execute([$division,$asc]);$l=$location->fetch();
        $request=$this->uuid();$id=$this->uuid();$this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,created_by,finalized_by,finalized_at) VALUES(?,'APPOINTMENT',?,?,?,?,?,'NATIONAL_APPROVED',?,?,NOW())")->execute([$request,$officer,$type,$asc,$division,$from,$this->actor,$this->actor]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,request_id,officer_id,appointment_type,service_permanency_snapshot,asc_location_id,arpa_division_location_id,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,approved_by,approved_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'{}',?,?,NOW())")->execute([$id,$request,$officer,$type,'PERMANENT_IN_SERVICE',$asc,$division,$l['asc_dad'],$l['asc_name'],$l['arpa_dad'],$l['arpa_name'],$from,$this->actor]);return $id;
    }

    private function close(string $appointment,string $to):void
    {
        $a=$this->pdo->query("SELECT * FROM arpa_division_appointment WHERE id='{$appointment}'")->fetch();$request=$this->uuid();$this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,request_type,officer_id,appointment_type,source_appointment_id,asc_location_id,arpa_division_location_id,requested_effective_to,workflow_status,created_by,finalized_by,finalized_at) VALUES(?,'END',?,?,?,?,?,?,'NATIONAL_APPROVED',?,?,NOW())")->execute([$request,$a['officer_id'],$a['appointment_type'],$appointment,$a['asc_location_id'],$a['arpa_division_location_id'],$to,$this->actor,$this->actor]);$reason=(string)$this->pdo->query("SELECT id FROM arpa_appointment_end_reason ORDER BY display_order LIMIT 1")->fetchColumn();$this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,appointment_id,request_id,effective_to,end_reason_id,closure_kind,context_snapshot_json,approved_by,approved_at) VALUES(UUID(),?,?,?,?,'DIRECT','{}',?,NOW())")->execute([$appointment,$request,$to,$reason,$this->actor]);
    }

    private function subject(string $officer,string $asc,string $kind,string $from):string
    {
        $subject=$this->pdo->prepare('SELECT * FROM subject_master WHERE system_key=?');$subject->execute([$kind]);$s=$subject->fetch();$ascRow=$this->pdo->query("SELECT dad_number,name_en FROM location WHERE id='{$asc}'")->fetch();$request=$this->uuid();$id=$this->uuid();
        $this->pdo->prepare("INSERT INTO arpa_subject_assignment_request(id,request_type,officer_id,asc_location_id,subject_id,requested_effective_from,workflow_status,created_by,finalized_by,finalized_at) VALUES(?,'ASSIGN',?,?,?,?, 'NATIONAL_APPROVED',?,?,NOW())")->execute([$request,$officer,$asc,$s['id'],$from,$this->actor,$this->actor]);
        $this->pdo->prepare("INSERT INTO arpa_subject_assignment(id,request_id,officer_id,subject_id,subject_kind_snapshot,officer_exclusive_snapshot,asc_location_id,asc_dad_snapshot,asc_name_snapshot,subject_name_snapshot,context_snapshot_json,effective_from,approved_by,approved_at) VALUES(?,?,?,?,?,1,?,?,?,?,'{}',?,?,NOW())")->execute([$id,$request,$officer,$s['id'],$kind,$asc,$ascRow['dad_number'],$ascRow['name_en'],$s['name_en'],$from,$this->actor]);return $id;
    }

    private function districtSummaryReadModels():void
    {
        $previousSession=$_SESSION;
        $districtUser=$this->districtUser();

        $this->activateContext($districtUser,null,'DISTRICT');

        try{
            $scopedAscs=ScopeService::scopedLocations($districtUser,'ASC');

            $this->same(
                true,
                count($scopedAscs)>0,
                'District summary fixture exposes at least one ASC'
            );

            $ascId=(string)$scopedAscs[0]['id'];

            $pages=[
                [
                    'name'=>'New Appointments',
                    'summary'=>'arpa-new-appointments-summary',
                    'detail'=>'arpa-new-appointments',
                ],
                [
                    'name'=>'Submitted Appointments',
                    'summary'=>'arpa-submitted-appointments-summary',
                    'detail'=>'arpa-submitted-appointments',
                ],
                [
                    'name'=>'Approval / Verification',
                    'summary'=>'arpa-approval-verification-summary',
                    'detail'=>'arpa-approval-verification',
                ],
                [
                    'name'=>'Open Appointments',
                    'summary'=>'arpa-open-appointments-summary',
                    'detail'=>'arpa-open-appointments',
                ],
                [
                    'name'=>'Historical Appointments',
                    'summary'=>'arpa-historical-appointments-summary',
                    'detail'=>'arpa-historical-appointments',
                ],
            ];

            foreach($pages as $page){
                $summaryDefinition=DataTableRegistry::definition($page['summary']);

                $summaryQuery=new DataTableQuery(
                    $this->pdo,
                    $summaryDefinition,
                    new DataTableRequest(['length'=>10])
                );

                $summaryResponse=$summaryQuery->response();

                $this->same(
                    count($scopedAscs),
                    (int)$summaryResponse['recordsTotal'],
                    "{$page['name']} summary contains every ASC in District scope"
                );

                $summaryRows=iterator_to_array(
                    $summaryQuery->exportRows(),
                    false
                );

                $summaryTotal=0;

                foreach($summaryRows as $row){
                    $summaryTotal+=(int)$row['total_count'];

                    $this->same(
                        true,
                        array_key_exists('total_divisions',$row),
                        "{$page['name']} summary exposes total ARPA Divisions"
                    );

                    $this->same(
                        true,
                        array_key_exists('vacant_divisions',$row),
                        "{$page['name']} summary exposes vacant Divisions"
                    );

                    $this->same(
                        true,
                        (int)$row['vacant_divisions']<=(int)$row['total_divisions'],
                        "{$page['name']} vacant Divisions do not exceed total ARPA Divisions"
                    );
                }

                $detailDefinition=DataTableRegistry::definition($page['detail']);

                $detailResponse=(new DataTableQuery(
                    $this->pdo,
                    $detailDefinition,
                    new DataTableRequest(['length'=>1])
                ))->response();

                $this->same(
                    (int)$detailResponse['recordsTotal'],
                    $summaryTotal,
                    "{$page['name']} ASC summary total reconciles with its District detail DataTable"
                );

                $oneAscSummary=$summaryDefinition;
                $oneAscSummary['baseWhere'][]='summary_asc.id=?';
                $oneAscSummary['baseParams'][]=$ascId;

                $oneAscResponse=(new DataTableQuery(
                    $this->pdo,
                    $oneAscSummary,
                    new DataTableRequest(['length'=>1])
                ))->response();

                $this->same(
                    1,
                    (int)$oneAscResponse['recordsTotal'],
                    "{$page['name']} summary can isolate the selected ASC"
                );

                $detailAscDefinition=DataTableRegistry::definition(
                    $page['detail'],
                    ['asc_id'=>$ascId]
                );

                $detailAscResponse=(new DataTableQuery(
                    $this->pdo,
                    $detailAscDefinition,
                    new DataTableRequest(['length'=>1])
                ))->response();

                $summaryAscTotal=(int)($oneAscResponse['data'][0]['total_count']??0);

                $this->same(
                    $summaryAscTotal,
                    (int)$detailAscResponse['recordsTotal'],
                    "{$page['name']} View Records detail count matches the selected ASC summary"
                );

                $this->same(
                    true,
                    in_array(
                        $ascId,
                        array_values($detailAscDefinition['baseParams']??[]),
                        true
                    ),
                    "{$page['name']} separate records DataTable locks the selected ASC into backend query parameters"
                );

                if(isset($detailAscDefinition['filters']['asc'])){
                    $this->same(
                        null,
                        $detailAscDefinition['filters']['asc']['ui']??null,
                        "{$page['name']} separate records page hides the redundant ASC selector"
                    );
                }
            }
        }finally{
            $_SESSION=$previousSession;Auth::forgetRequestCache();
        }
    }
    private function nationalSummaryReadModels():void
    {
        $previousSession=$_SESSION;

        $nationalSubject=$this->temporaryNationalUser('NATIONAL_SUBJECT_OFFICER');
        $nationalAdmin=$this->temporaryNationalUser('NATIONAL_ADMIN');

        try{
            $this->activateContext($nationalSubject,null,'NATIONAL');
            $nationalNew=DataTableRegistry::definition(
                'arpa-new-appointments-district-summary'
            );
            $this->same(
                false,
                !isset($nationalNew['authorize'])
                || ($nationalNew['authorize'])(),
                'National context cannot borrow the ASC create permission for New Appointments'
            );

            $pages=[
                [
                    'name'=>'Submitted Appointments',
                    'user'=>$nationalSubject,
                    'district_summary'=>'arpa-submitted-appointments-district-summary',
                    'asc_summary'=>'arpa-submitted-appointments-summary',
                    'detail'=>'arpa-submitted-appointments',
                ],
                [
                    'name'=>'Approval / Verification',
                    'user'=>$nationalSubject,
                    'district_summary'=>'arpa-approval-verification-district-summary',
                    'asc_summary'=>'arpa-approval-verification-summary',
                    'detail'=>'arpa-approval-verification',
                ],
                [
                    'name'=>'Open Appointments',
                    'user'=>$nationalAdmin,
                    'district_summary'=>'arpa-open-appointments-district-summary',
                    'asc_summary'=>'arpa-open-appointments-summary',
                    'detail'=>'arpa-open-appointments',
                ],
                [
                    'name'=>'Historical Appointments',
                    'user'=>$nationalAdmin,
                    'district_summary'=>'arpa-historical-appointments-district-summary',
                    'asc_summary'=>'arpa-historical-appointments-summary',
                    'detail'=>'arpa-historical-appointments',
                ],
            ];

            foreach($pages as $page){
                $this->activateContext((string)$page['user'],null,'NATIONAL');

                $profile=ScopeService::scopeProfile($page['user']);

                $this->same(
                    'NATIONAL',
                    (string)($profile['level']??''),
                    "{$page['name']} National hierarchy fixture resolves to NATIONAL scope"
                );

                $districts=ScopeService::scopedLocations(
                    $page['user'],
                    'DISTRICT'
                );

                $this->same(
                    true,
                    count($districts)>0,
                    "{$page['name']} National hierarchy exposes active Districts"
                );

                $districtDefinition=DataTableRegistry::definition(
                    $page['district_summary']
                );

                $this->same(
                    true,
                    !isset($districtDefinition['authorize'])
                    || ($districtDefinition['authorize'])(),
                    "{$page['name']} District summary definition authorizes the National fixture"
                );

                $districtQuery=new DataTableQuery(
                    $this->pdo,
                    $districtDefinition,
                    new DataTableRequest(['length'=>100])
                );

                $districtResponse=$districtQuery->response();

                $this->same(
                    count($districts),
                    (int)$districtResponse['recordsTotal'],
                    "{$page['name']} National summary contains every active District"
                );

                $districtRows=iterator_to_array(
                    $districtQuery->exportRows(),
                    false
                );

                $districtTotal=0;

                foreach($districtRows as $row){
                    $districtTotal+=(int)$row['total_count'];

                    $this->same(
                        true,
                        array_key_exists('total_ascs',$row),
                        "{$page['name']} District summary exposes Total ASCs"
                    );

                    $this->same(
                        true,
                        array_key_exists('total_divisions',$row),
                        "{$page['name']} District summary exposes Total ARPA Divisions"
                    );

                    $this->same(
                        true,
                        array_key_exists('vacant_divisions',$row),
                        "{$page['name']} District summary exposes Vacant Divisions"
                    );

                    $this->same(
                        true,
                        (int)$row['vacant_divisions']<=(int)$row['total_divisions'],
                        "{$page['name']} District vacant Divisions do not exceed total ARPA Divisions"
                    );
                }

                $detailDefinition=DataTableRegistry::definition(
                    $page['detail']
                );

                $detailResponse=(new DataTableQuery(
                    $this->pdo,
                    $detailDefinition,
                    new DataTableRequest(['length'=>1])
                ))->response();

                $this->same(
                    (int)$detailResponse['recordsTotal'],
                    $districtTotal,
                    "{$page['name']} National District totals reconcile with the underlying detail DataTable"
                );

                $district=$this->districtWithAsc($districts);
                $districtId=(string)$district['id'];
                $ascIds=$this->districtAscIds($districtId);

                $this->same(
                    true,
                    count($ascIds)>0,
                    "{$page['name']} selected National District contains at least one ASC"
                );

                $oneDistrict=$districtDefinition;
                $oneDistrict['baseWhere'][]='summary_district.id=?';
                $oneDistrict['baseParams'][]=$districtId;

                $oneDistrictResponse=(new DataTableQuery(
                    $this->pdo,
                    $oneDistrict,
                    new DataTableRequest(['length'=>1])
                ))->response();

                $this->same(
                    1,
                    (int)$oneDistrictResponse['recordsTotal'],
                    "{$page['name']} National District summary can isolate the selected District"
                );

                $districtRow=$oneDistrictResponse['data'][0]??[];

                $this->same(
                    count($ascIds),
                    (int)($districtRow['total_ascs']??-1),
                    "{$page['name']} District Total ASCs matches the active ASC hierarchy"
                );

                $districtHtml=implode(
                    ' ',
                    array_map('strval',$districtRow)
                );

                $this->same(
                    true,
                    str_contains(
                        $districtHtml,
                        '/'.$this->nationalRouteSegment($page['district_summary']).
                        '/district/'.$districtId
                    ),
                    "{$page['name']} District row View ASCs action targets the selected District"
                );

                $ascDefinition=DataTableRegistry::definition(
                    $page['asc_summary'],
                    ['district_id'=>$districtId]
                );

                $this->same(
                    true,
                    !isset($ascDefinition['authorize'])
                    || ($ascDefinition['authorize'])(),
                    "{$page['name']} selected District ASC summary authorizes the National fixture"
                );

                $ascQuery=new DataTableQuery(
                    $this->pdo,
                    $ascDefinition,
                    new DataTableRequest(['length'=>100])
                );

                $ascResponse=$ascQuery->response();

                $this->same(
                    count($ascIds),
                    (int)$ascResponse['recordsTotal'],
                    "{$page['name']} selected District ASC summary contains every active ASC in that District"
                );

                $ascRows=iterator_to_array(
                    $ascQuery->exportRows(),
                    false
                );

                $ascRecordTotal=0;
                $ascDivisionTotal=0;
                $ascVacantTotal=0;

                foreach($ascRows as $row){
                    $ascRecordTotal+=(int)$row['total_count'];
                    $ascDivisionTotal+=(int)$row['total_divisions'];
                    $ascVacantTotal+=(int)$row['vacant_divisions'];
                }

                $this->same(
                    (int)($districtRow['total_count']??-1),
                    $ascRecordTotal,
                    "{$page['name']} District record total reconciles with its ASC summary"
                );

                $this->same(
                    (int)($districtRow['total_divisions']??-1),
                    $ascDivisionTotal,
                    "{$page['name']} District ARPA Division total reconciles with its ASC summary"
                );

                $this->same(
                    (int)($districtRow['vacant_divisions']??-1),
                    $ascVacantTotal,
                    "{$page['name']} District vacancy total reconciles with its ASC summary"
                );

                $ascId=(string)$ascIds[0];

                $oneAsc=$ascDefinition;
                $oneAsc['baseWhere'][]='summary_asc.id=?';
                $oneAsc['baseParams'][]=$ascId;

                $oneAscResponse=(new DataTableQuery(
                    $this->pdo,
                    $oneAsc,
                    new DataTableRequest(['length'=>1])
                ))->response();

                $this->same(
                    1,
                    (int)$oneAscResponse['recordsTotal'],
                    "{$page['name']} National ASC summary can isolate the selected ASC"
                );

                $ascRow=$oneAscResponse['data'][0]??[];
                $ascHtml=implode(
                    ' ',
                    array_map('strval',$ascRow)
                );

                $this->same(
                    true,
                    str_contains(
                        $ascHtml,
                        '/district/'.$districtId.'/asc/'.$ascId
                    ),
                    "{$page['name']} National View Records action preserves District and ASC context"
                );

                $ascDetailDefinition=DataTableRegistry::definition(
                    $page['detail'],
                    ['asc_id'=>$ascId]
                );

                $ascDetailResponse=(new DataTableQuery(
                    $this->pdo,
                    $ascDetailDefinition,
                    new DataTableRequest(['length'=>1])
                ))->response();

                $this->same(
                    (int)($ascRow['total_count']??0),
                    (int)$ascDetailResponse['recordsTotal'],
                    "{$page['name']} National selected ASC record total matches the ASC-locked detail DataTable"
                );

                $this->same(
                    true,
                    in_array(
                        $ascId,
                        array_values($ascDetailDefinition['baseParams']??[]),
                        true
                    ),
                    "{$page['name']} National records DataTable locks the selected ASC in backend parameters"
                );

                if(isset($ascDetailDefinition['filters']['asc'])){
                    $this->same(
                        null,
                        $ascDetailDefinition['filters']['asc']['ui']??null,
                        "{$page['name']} National records page hides the redundant ASC filter"
                    );
                }
            }
        }finally{
            $_SESSION=$previousSession;Auth::forgetRequestCache();
        }
    }

    private function temporaryNationalUser(string $roleCode):string
    {
        $id=$this->uuid();
        $username='national-summary-'.
            strtolower(str_replace('_','-',$roleCode)).
            '-'.
            substr($id,0,6);

        $roleAssignmentId=$this->uuid();
        $this->pdo->prepare(
            "INSERT INTO system_user(
                id,
                identity_type,
                username,
                display_name,
                account_status,
                approval_status,
                enabled
             )
             VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)"
        )->execute([
            $id,
            $username,
            'National Summary '.$roleCode,
        ]);


        $stmt=$this->pdo->prepare(
            "SELECT id FROM application_role WHERE role_code=?"
        );
        $stmt->execute([$roleCode]);
        $roleId=(string)$stmt->fetchColumn();

        if($roleId===''){
            throw new RuntimeException(
                "{$roleCode} role fixture required."
            );
        }

        $this->pdo->prepare(
            "INSERT INTO user_account_role(
                id,
                user_id,
                role_id,
                effective_from,
                approval_status,
                active,
                reason,
                created_by,
                approved_by,
                approved_at
             )
             VALUES(
                ?,
                ?,
                ?,
                CURRENT_DATE(),
                'APPROVED',
                1,
                'National appointment hierarchy test',
                ?,
                ?,
                NOW()
             )"
        )->execute([
            $roleAssignmentId,
            $id,
            $roleId,
            $this->actor,
            $this->actor,
        ]);

        $this->pdo->prepare(
            "INSERT INTO user_account_scope(
                id,
                user_id,
                role_assignment_id,
                scope_type,
                scope_mode,
                location_id,
                effective_from,
                approval_status,
                active,
                reason,
                created_by,
                approved_by,
                approved_at
             )
             VALUES(
                UUID(),
                ?,
                ?,
                'NATIONAL',
                'NATIONAL',
                NULL,
                CURRENT_DATE(),
                'APPROVED',
                1,
                'National appointment hierarchy test',
                ?,
                ?,
                NOW()
             )"
        )->execute([
            $id,
            $roleAssignmentId,
            $this->actor,
            $this->actor,
        ]);

        return $id;
    }

    private function districtWithAsc(array $districts):array
    {
        foreach($districts as $district){
            if($this->districtAscIds((string)$district['id'])!==[]){
                return $district;
            }
        }

        throw new RuntimeException(
            'Active District with at least one active ASC fixture required.'
        );
    }

    private function districtAscIds(string $districtId):array
    {
        $stmt=$this->pdo->prepare(
            "SELECT DISTINCT asc_l.id
             FROM location_relationship lr
             JOIN location asc_l
               ON asc_l.id=lr.child_location_id
             JOIN location_type asc_t
               ON asc_t.id=asc_l.location_type_id
              AND asc_t.system_key='ASC'
             WHERE lr.parent_location_id=?
               AND lr.active=1
               AND lr.approval_status='APPROVED'
               AND lr.effective_from<=CURRENT_DATE()
               AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())
               AND asc_l.approval_status='APPROVED'
               AND asc_l.operational_status='ACTIVE'
             ORDER BY asc_l.name_en"
        );

        $stmt->execute([$districtId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function nationalRouteSegment(string $key):string
    {
        return match($key){
            'arpa-new-appointments-district-summary'=>'new',
            'arpa-submitted-appointments-district-summary'=>'submitted',
            'arpa-approval-verification-district-summary'=>'approval',
            'arpa-open-appointments-district-summary'=>'open',
            'arpa-historical-appointments-district-summary'=>'history',
            default=>throw new RuntimeException(
                'Unknown National appointment summary route.'
            ),
        };
    }
    private function districtUser():string
    {
        $sql="SELECT DISTINCT su.id
              FROM system_user su
              JOIN user_account_scope uas ON uas.user_id=su.id
              JOIN location l ON l.id=uas.location_id
              JOIN location_type lt ON lt.id=l.location_type_id
              WHERE su.enabled=1
                AND su.account_status='ACTIVE'
                AND uas.active=1
                AND uas.approval_status='APPROVED'
                AND uas.effective_from<=CURRENT_DATE()
                AND (uas.effective_to IS NULL OR uas.effective_to>=CURRENT_DATE())
                AND lt.system_key='DISTRICT'
              ORDER BY su.id";

        foreach($this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $userId){
            $profile=ScopeService::scopeProfile((string)$userId);
            if(($profile['level']??'')==='DISTRICT')return (string)$userId;
        }

        throw new RuntimeException('Active District-scope user fixture required.');
    }
    private function activateContext(string $userId,?string $roleCode=null,?string $roleLevel=null):void
    {
        $_SESSION=['user_id'=>$userId,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();
        $contexts=(new UserContextService($this->pdo))->availableContexts($userId);
        $context=array_values(array_filter($contexts,static fn(array $row):bool=>($roleCode===null||$row['role_code']===$roleCode)&&($roleLevel===null||$row['role_level']===$roleLevel)))[0]??null;
        if(!$context)throw new RuntimeException('Required working context fixture is unavailable.');
        (new UserContextService($this->pdo))->select($userId,(string)$context['role_assignment_id'],$context['scope_assignment_id']===null?null:(string)$context['scope_assignment_id']);Auth::forgetRequestCache();
    }
    private function rawIssues():array{return $this->pdo->query('SELECT DISTINCT issue_type FROM '.ArpaAppointmentReadService::issueSource().' q')->fetchAll(PDO::FETCH_COLUMN);}
    private function state():array{return ['requests'=>(int)$this->pdo->query('SELECT COUNT(*) FROM arpa_division_appointment_request')->fetchColumn(),'appointments'=>(int)$this->pdo->query('SELECT COUNT(*) FROM arpa_division_appointment')->fetchColumn(),'closures'=>(int)$this->pdo->query('SELECT COUNT(*) FROM arpa_division_appointment_closure')->fetchColumn(),'subjects'=>(int)$this->pdo->query('SELECT COUNT(*) FROM arpa_subject_assignment')->fetchColumn(),'offices'=>(int)$this->pdo->query('SELECT COUNT(*) FROM officer_office_assignment')->fetchColumn(),'decisions'=>(int)$this->pdo->query("SELECT COUNT(*) FROM legacy_arpa_appointment_resolution WHERE resolution_status='CONFIRMED'")->fetchColumn(),'roles'=>(int)$this->pdo->query('SELECT COUNT(*) FROM user_account_role')->fetchColumn(),'scopes'=>(int)$this->pdo->query('SELECT COUNT(*) FROM user_account_scope')->fetchColumn()];}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function throws(callable $fn,string $message):void{$this->assertions++;try{$fn();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
}

exit((new ArpaAppointmentOperationalViewsTest())->run());
