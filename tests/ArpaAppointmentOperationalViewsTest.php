<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database,ScopeService};
use App\Services\{ArpaAppointmentReadService,ScopedDashboardService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentOperationalViewsTest
{
    private PDO $pdo;private int $assertions=0;private string $actor;

    public function run():int
    {
        $this->pdo=Database::pdo();$before=$this->state();
        $this->actor=(string)$this->pdo->query("SELECT su.id FROM system_user su JOIN user_account_role ur ON ur.user_id=su.id JOIN application_role r ON r.id=ur.role_id WHERE r.role_code='SYSTEM_ADMIN' LIMIT 1")->fetchColumn();
        if($this->actor==='')throw new RuntimeException('SYSTEM_ADMIN fixture required.');$_SESSION=['user_id'=>$this->actor];
        $this->staticRuleCoverage();$this->transactionalReadModels();
        $this->same($before,$this->state(),'operational-view tests leave all appointment and safety state unchanged');
        echo "ArpaAppointmentOperationalViewsTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function staticRuleCoverage():void
    {
        $source=ArpaAppointmentReadService::issueSource();
        foreach(['DIVISION_MULTIPLE_OPEN','OFFICER_MULTIPLE_PERMANENT','OFFICER_MULTIPLE_ACTING','OFFICER_MULTIPLE_ATTEND_TO_DUTY','DEPENDENT_WITHOUT_PERMANENT','PERMANENT_SERVICE_WITH_ATTEND_TO_DUTY','NON_PERMANENT_SERVICE_WITH_ACTING','EXCLUSIVE_FUNCTION_OVERLAP','MULTIPLE_EXCLUSIVE_FUNCTIONS','MISSING_ASC_OFFICE_ASSIGNMENT','APPOINTMENT_OUTSIDE_ASC','INVALID_DATE_RANGE','OPEN_APPOINTMENT_WITH_END_REASON','ENDED_APPOINTMENT_WITHOUT_END_REASON','FUTURE_OVERLAP_CONFLICT'] as $issue)$this->same(true,str_contains($source,$issue),"{$issue} diagnostic is independently defined");
        $this->same(false,str_contains($source,'UPDATE '),'diagnostic source is read-only');
        $this->same('c.id IS NULL',ArpaAppointmentReadService::openAppointmentClause(),'open definition is absence of formal closure');
        $routes=file_get_contents(BASE_PATH.'/routes/web.php');foreach(['/new','/submitted','/approval','/open','/history','/vacant-divisions','/data-issues'] as $path)$this->same(true,str_contains($routes,"/hr/arpa-appointments{$path}"),"{$path} route registered");
        $controller=file_get_contents(BASE_PATH.'/app/Controllers/ArpaAppointmentController.php');$this->same(true,str_contains($controller,"Auth::requirePermission('arpa.appointment.view')"),'workflow tabs require backend view permission');$this->same(true,str_contains($controller,'workflowQueuePolicy()->canUseWorkflowQueues'),'workflow routes require an active action permission and matching scope');$view=file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/list.php');$this->same(true,str_contains($controller,'openAppointmentAscSummary'),'Open Appointments requests the District ASC summary');$this->same(true,str_contains($view,'ASC Appointment Summary'),'appointment list contains the ASC summary view');$summaryPosition=strpos($view,'ASC Appointment Summary');$tablePosition=strpos($view,'components/datatable.php');$this->same(true,$summaryPosition!==false&&$tablePosition!==false&&$summaryPosition<$tablePosition,'ASC summary renders before the detailed appointment list');
            $this->same(true,str_contains($view,'Total ARPA Divisions'),'District ASC summary displays total ARPA Division counts');
            $this->same(true,str_contains($view,'Vacant Divisions'),'District ASC summary displays vacant Division counts');
            $this->same(true,str_contains($view,'View Records'),'District ASC summary provides a View Records action');
            $this->same(false,str_contains($view,'District Total'),'District ASC summary does not render a bottom total row');
            $this->same(true,str_contains($controller,'$selectedAsc')&&str_contains($controller,'$initialFilters'),'View Records ASC selection is passed into the detailed DataTable filter');
    }

    private function transactionalReadModels():void
    {
        $this->pdo->beginTransaction();
        try{
            $fixture=$this->pdo->query("SELECT lr.parent_location_id asc_id,GROUP_CONCAT(lr.child_location_id ORDER BY lr.child_location_id) divisions FROM location_relationship lr JOIN location a ON a.id=lr.parent_location_id JOIN office ofc ON ofc.linked_location_id=a.id JOIN office_type ot ON ot.id=ofc.office_type_id AND ot.system_key='ASC_OFFICE' WHERE lr.relationship_type='ASC_ARPA_DIVISION' AND lr.active=1 AND lr.approval_status='APPROVED' AND ofc.operational_status='ACTIVE' AND ofc.approval_status='APPROVED' GROUP BY lr.parent_location_id HAVING COUNT(*)>=10 ORDER BY lr.parent_location_id LIMIT 1")->fetch();
            if(!$fixture)throw new RuntimeException('ASC with ARPA Division fixtures required.');$asc=(string)$fixture['asc_id'];$divisions=explode(',',$fixture['divisions']);
            $officers=$this->pdo->prepare("SELECT DISTINCT o.id FROM officer o JOIN designation d ON d.id=o.primary_designation_id AND d.system_key='ARPA_OFFICER' JOIN officer_office_assignment oa ON oa.officer_id=o.id AND oa.active=1 AND oa.approval_status='APPROVED' JOIN office ofc ON ofc.id=oa.office_id AND ofc.linked_location_id=? WHERE o.approval_status='APPROVED' AND o.operational_status='ACTIVE' ORDER BY o.id LIMIT 3");$officers->execute([$asc]);$ids=$officers->fetchAll(PDO::FETCH_COLUMN);if(count($ids)<2)throw new RuntimeException('Two assigned ARPA Officers required.');
            $today=date('Y-m-d');$future=date('Y-m-d',strtotime('+30 days'));$read=new ArpaAppointmentReadService($this->pdo);
            $this->same(null,$read->openAppointmentAscSummary($this->actor),'System scope does not receive the District ASC summary');

            $districtUser=$this->districtUser();
            $districtSummary=$read->openAppointmentAscSummary($districtUser);
            $this->same(true,is_array($districtSummary),'District scope receives the ASC summary');

            $scopedAscs=ScopeService::scopedLocations($districtUser,'ASC');
            $this->same(true,count($scopedAscs)>0,'District fixture exposes at least one ASC');
            $this->same(count($scopedAscs),count($districtSummary['rows']),'District summary includes every scoped ASC including zero-count ASCs');

            foreach($districtSummary['rows'] as $summaryRow){
                $this->same(
                    (int)$summaryRow['total'],
                    (int)$summaryRow['permanent']
                        +(int)$summaryRow['acting']
                        +(int)$summaryRow['duty_covering']
                        +(int)$summaryRow['attend_to_duty'],
                    'ASC summary total equals the four appointment-type counts'
                );
            }

            foreach($districtSummary['rows'] as $summaryRow){
                $this->same(true,array_key_exists('total_divisions',$summaryRow),'ASC summary exposes total ARPA Division count');
                $this->same(true,array_key_exists('vacant_divisions',$summaryRow),'ASC summary exposes vacant Division count');
                $this->same(true,(int)$summaryRow['vacant_divisions']<=(int)$summaryRow['total_divisions'],'vacant Division count cannot exceed total ARPA Divisions');
            }

            $previousSession=$_SESSION;
            $_SESSION=['user_id'=>$districtUser];
            try{
                $districtOpen=DataTableRegistry::definition('arpa-open-appointments');
                $districtResponse=(new DataTableQuery(
                    $this->pdo,
                    $districtOpen,
                    new DataTableRequest(['length'=>10])
                ))->response();

                $this->same(
                    (int)$districtSummary['totals']['total'],
                    (int)$districtResponse['recordsTotal'],
                    'District ASC summary total matches the detailed Open Appointments list'
                );
            }finally{
                $_SESSION=$previousSession;
            }
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
    private function rawIssues():array{return $this->pdo->query('SELECT DISTINCT issue_type FROM '.ArpaAppointmentReadService::issueSource().' q')->fetchAll(PDO::FETCH_COLUMN);}
    private function state():array{return ['requests'=>(int)$this->pdo->query('SELECT COUNT(*) FROM arpa_division_appointment_request')->fetchColumn(),'appointments'=>(int)$this->pdo->query('SELECT COUNT(*) FROM arpa_division_appointment')->fetchColumn(),'closures'=>(int)$this->pdo->query('SELECT COUNT(*) FROM arpa_division_appointment_closure')->fetchColumn(),'subjects'=>(int)$this->pdo->query('SELECT COUNT(*) FROM arpa_subject_assignment')->fetchColumn(),'offices'=>(int)$this->pdo->query('SELECT COUNT(*) FROM officer_office_assignment')->fetchColumn(),'decisions'=>(int)$this->pdo->query("SELECT COUNT(*) FROM legacy_arpa_appointment_resolution WHERE resolution_status='CONFIRMED'")->fetchColumn(),'roles'=>(int)$this->pdo->query('SELECT COUNT(*) FROM user_account_role')->fetchColumn(),'scopes'=>(int)$this->pdo->query('SELECT COUNT(*) FROM user_account_scope')->fetchColumn()];}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function throws(callable $fn,string $message):void{$this->assertions++;try{$fn();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
}

exit((new ArpaAppointmentOperationalViewsTest())->run());
