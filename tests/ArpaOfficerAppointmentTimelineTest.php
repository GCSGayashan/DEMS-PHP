<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database};
use App\Services\{ArpaOfficerTimelineService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaOfficerAppointmentTimelineTest
{
    private int $assertions=0;

    public function run():int
    {
        $service=new ArpaOfficerTimelineService(Database::pdo());
        $permanent=$this->period('permanent','PERMANENT','division-a','2025-01-01',null);

        $this->same([],array_column($service->derivedConflicts([$permanent],'PERMANENT_IN_SERVICE'),'issue_type'),'one Permanent appointment displays without an Officer conflict');

        $acting=$this->period('acting','ACTING','division-b','2025-04-01','2025-06-30');
        $this->same([],array_column($service->derivedConflicts([$permanent,$acting],'PERMANENT_IN_SERVICE'),'issue_type'),'Permanent plus Acting is not treated as a generic overlap');
        $laterActing=$this->period('later-acting','ACTING','division-b','2026-04-01','2026-06-30');
        $this->same([],array_column($service->derivedConflicts([$permanent,$acting,$laterActing],'PERMANENT_IN_SERVICE'),'issue_type'),'an Officer timeline gap between non-overlapping appointments is allowed');

        $secondPermanent=$this->period('permanent-2','PERMANENT','division-c','2025-05-01',null);
        $this->contains('OFFICER_MULTIPLE_PERMANENT',array_column($service->derivedConflicts([$permanent,$secondPermanent],'PERMANENT_IN_SERVICE'),'issue_type'),'overlapping Permanent appointments are flagged');

        $acting2=$this->period('acting-2','ACTING','division-c','2025-06-01',null);
        $this->contains('OFFICER_MULTIPLE_ACTING',array_column($service->derivedConflicts([$permanent,$acting,$acting2],'PERMANENT_IN_SERVICE'),'issue_type'),'overlapping Acting appointments are flagged');
        $this->contains('DEPENDENT_WITHOUT_QUALIFYING_PERMANENT',array_column($service->derivedConflicts([$acting],'PERMANENT_IN_SERVICE'),'issue_type'),'Acting without a Permanent base is flagged');
        $this->contains('NON_PERMANENT_SERVICE_WITH_ACTING',array_column($service->derivedConflicts([$permanent,$acting],'NOT_PERMANENT_IN_SERVICE'),'issue_type'),'Acting for a non-permanent-in-service Officer is flagged');

        $attend=$this->period('attend','ATTEND_TO_DUTY','division-b','2025-04-01','2025-06-30');
        $this->same([],array_column($service->derivedConflicts([$permanent,$attend],'NOT_PERMANENT_IN_SERVICE'),'issue_type'),'Attend to Duty is valid for a non-permanent Officer with a Permanent base');
        $attend2=$this->period('attend-2','ATTEND_TO_DUTY','division-c','2025-06-01',null);
        $this->contains('OFFICER_MULTIPLE_ATTEND_TO_DUTY',array_column($service->derivedConflicts([$permanent,$attend,$attend2],'NOT_PERMANENT_IN_SERVICE'),'issue_type'),'overlapping Attend to Duty appointments are flagged');

        $dutyA=$this->period('duty-a','DUTY_COVERING','division-b','2025-04-01',null);
        $dutyB=$this->period('duty-b','DUTY_COVERING','division-c','2025-04-01',null);
        $this->same([],array_column($service->derivedConflicts([$permanent,$dutyA,$dutyB],'PERMANENT_IN_SERVICE'),'issue_type'),'concurrent Duty Covering in different Divisions follows the existing allowed rule');
        $dutyDuplicate=$this->period('duty-c','DUTY_COVERING','division-b','2025-05-01',null);
        $this->contains('OFFICER_DUPLICATE_DUTY_COVERING',array_column($service->derivedConflicts([$permanent,$dutyA,$dutyDuplicate],'PERMANENT_IN_SERVICE'),'issue_type'),'duplicate Duty Covering in the same Division is flagged');

        $definition=DataTableRegistry::definition('arpa-officer-timelines');
        $this->same(['DAD Officer Number','Officer Name','NIC','Service Permanency','Permanent Appointment','Additional Appointments','Timeline Status','Data Issues','Actions'],array_column($definition['columns'],'label'),'Officer Timeline list uses the requested one-row-per-Officer columns');
        $this->same(true,$definition['export'],'Officer Timeline uses the server-side DataTable export pattern');
        $source=ArpaOfficerTimelineService::officerListSource(true);
        $this->same(true,str_contains($source,'JOIN visible_locations period_scope')&&str_contains($source,'JOIN visible_locations issue_scope'),'appointments and Data Issues are both constrained by the Active Working Context');
        $this->same(true,str_contains(ArpaOfficerTimelineService::periodSource(),'UNION ALL')&&str_contains(ArpaOfficerTimelineService::periodSource(),"source_kind"),'canonical list combines operational appointments and reserving requests without creating another model');

        $view=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/timeline/officer_detail.php');
        $this->same(true,str_contains($view,'Review / Correct Data')&&str_contains($view,"hr/arpa-appointments/issues/"),'existing Data Issues reuse the existing correction/detail action');
        $this->same(true,str_contains($view,'Affected appointment rows'),'issues visually identify all participating canonical rows');
        $modeView=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/timeline/mode_tabs.php');
        $this->same(true,str_contains($view,'Canonical ARPA Appointments')&&str_contains($modeView,'ARPA Division Timeline'),'Officer detail combines cross-Division history and retains the Division mode');

        $controller=(string)file_get_contents(BASE_PATH.'/app/Controllers/ArpaAppointmentController.php');
        $routes=(string)file_get_contents(BASE_PATH.'/routes/web.php');
        $this->same(true,str_contains($controller,'ArpaOfficerTimelineService(Database::pdo())')&&str_contains($controller,"catch(DomainException \$e)"),'direct Officer timeline access is revalidated server-side with a controlled forbidden response');
        $this->same(true,str_contains($routes,"/hr/arpa-appointments/timeline/officers/{id}")&&strpos($routes,"/timeline/officers/{id}")<strpos($routes,"/timeline/{id}"),'specific Officer route is registered before the Division detail route');

        $pdo=Database::pdo();$context=$pdo->query("SELECT su.id user_id,uar.id role_id,uas.id scope_id,uas.location_id
                                                  FROM system_user su JOIN user_account_role uar ON uar.user_id=su.id
                                                  JOIN application_role r ON r.id=uar.role_id AND r.role_code='ASC_SUBJECT_OFFICER'
                                                  JOIN user_account_scope uas ON uas.role_assignment_id=uar.id AND uas.user_id=su.id
                                                  WHERE su.username='asctest' AND uar.active=1 AND uar.approval_status='APPROVED'
                                                    AND uas.active=1 AND uas.approval_status='APPROVED' LIMIT 1")->fetch();
        if($context){
            $_SESSION=['user_id'=>$context['user_id'],'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();
            (new UserContextService($pdo))->select((string)$context['user_id'],(string)$context['role_id'],(string)$context['scope_id']);Auth::forgetRequestCache();
            $scopedDefinition=DataTableRegistry::definition('arpa-officer-timelines');
            $page=(new DataTableQuery($pdo,$scopedDefinition,new DataTableRequest(['draw'=>3,'length'=>1])))->response();
            $this->same(true,$page['recordsTotal']>=0&&count($page['data'])<=10,'server-side Officer Timeline DataTable executes under ASC scope');
            $stmt=$pdo->prepare('SELECT officer_id FROM arpa_division_appointment WHERE asc_location_id<>? LIMIT 1');$stmt->execute([$context['location_id']]);$outside=$stmt->fetchColumn();
            if($outside)$this->throws(fn()=>(new ArpaOfficerTimelineService($pdo))->timeline((string)$outside,(string)$context['user_id']),'ASC direct Officer timeline cannot cross its Active Working Context');
            $_SESSION=[];Auth::forgetRequestCache();
        }

        echo "ArpaOfficerAppointmentTimelineTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    /** @return array<string,mixed> */
    private function period(string $id,string $type,string $division,string $from,?string $to):array
    {
        return ['source_id'=>$id,'source_kind'=>'OPERATIONAL','appointment_type'=>$type,
            'arpa_division_location_id'=>$division,'effective_from'=>$from,'effective_to'=>$to];
    }

    private function same(mixed $expected,mixed $actual,string $message):void
    {
        $this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
    }

    private function contains(string $expected,array $actual,string $message):void
    {
        $this->assertions++;if(!in_array($expected,$actual,true))throw new RuntimeException($message.': '.var_export($actual,true));
    }

    private function throws(callable $callback,string $message):void
    {
        $this->assertions++;try{$callback();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');
    }
}

exit((new ArpaOfficerAppointmentTimelineTest())->run());
