<?php
declare(strict_types=1);

use App\Core\Database;

require dirname(__DIR__).'/bootstrap.php';

final class DirectSubmissionWorkflowTest
{
    private int $assertions=0;

    public function run(): int
    {
        $pdo=Database::pdo();
        $before=[
            'division_created'=>(int)$pdo->query("SELECT COUNT(*) FROM arpa_division_appointment_request WHERE workflow_status='CREATED'")->fetchColumn(),
            'subject_created'=>(int)$pdo->query("SELECT COUNT(*) FROM arpa_subject_assignment_request WHERE workflow_status='CREATED'")->fetchColumn(),
            'office_assignment_drafts'=>(int)$pdo->query("SELECT COUNT(*) FROM officer_office_assignment WHERE approval_status='DRAFT'")->fetchColumn(),
        ];

        $arpaService=$this->file('app/Services/ArpaAppointmentService.php');
        $arpaController=$this->file('app/Controllers/ArpaAppointmentController.php');
        $offices=$this->file('app/Controllers/OfficeController.php');
        $officers=$this->file('app/Controllers/OfficerController.php');
        $officeAssignments=$this->file('app/Services/OfficerOfficeAssignmentService.php');
        $masters=$this->file('app/Controllers/HrMasterController.php');
        $users=$this->file('app/Controllers/UserManagementController.php');
        $access=$this->file('app/Services/UserAccessManagementService.php');

        foreach([
            'createAndSubmitDivisionAppointmentRequest',
            'createAndSubmitEndRequest',
            'createAndSubmitTransferRequest',
            'createAndSubmitSubjectAssignmentRequest',
            'createAndSubmitSubjectEndRequest',
            'updateAndResubmitRequest',
        ] as $method)$this->contains("function {$method}",$arpaService,"ARPA service provides {$method}");
        $this->contains("workflow('division', \$id, 'SUBMIT', 'CREATOR'",$arpaService,'division requests use the existing submit transition');
        $this->contains("workflow('subject', \$id, 'SUBMIT', 'CREATOR'",$arpaService,'subject requests use the existing submit transition');
        $this->contains('createAndSubmitDivisionAppointmentRequest($data,$actor)',$arpaController,'normal ARPA creation submits directly');
        $this->contains("'/hr/arpa-appointments/submitted'",$arpaController,'ARPA submission redirects to Submitted');
        $this->contains('updateAndResubmitRequest($entity,$id,$_POST,$actor)',$arpaController,'returned ARPA requests resubmit in the correction action');
        $this->contains("'SUBMITTED',?,NOW(),?,NOW()",$offices,'new Offices store submitted audit fields');
        $this->contains("'INACTIVE','SUBMITTED'",$officers,'new Officers remain inactive while submitted');
        $this->contains("'SUBMITTED',?,NOW(),?,NOW()",$masters,'new HR masters store submitted audit fields');
        $this->contains("approval_status,created_by,submitted_by,submitted_at",$officeAssignments,'new Office assignments include direct submission audit fields');
        $this->contains("'SUBMITTED',?,?,NOW()",$officeAssignments,'new Office assignments begin submitted');
        $this->contains('createSubmittedAssignment(',$access,'user roles have an atomic submitted creation path');
        $this->contains('createDraftAssignment(',$access,'historical draft creation compatibility remains available');
        $this->contains('submitAssignment($actorId, $id)',$access,'role and linked location submission remains transactional');
        $this->contains("'REQUESTED','SUBMITTED'",$users,'new user requests begin submitted');
        $this->contains("'CUSTOM',0,1,0,'SUBMITTED',0",$users,'custom roles remain inactive while submitted');
        $this->contains("approval_status='APPROVED',active=1",$users,'custom roles activate only after approval');
        $this->contains('createSubmittedAssignment(',$users,'normal user role assignment uses direct submission');
        $this->contains("'SUBMITTED',0,?,NOW(),?,NOW()",$users,'new assigned locations remain inactive while submitted');
        $this->contains("created_by']===(string)Auth::user()['id']",$users,'user-management maker-checker remains enforced');
        $this->contains("if(\$r['created_by']===\$actorId||\$r['submitted_by']===\$actorId)",$officeAssignments,'Office-assignment maker-checker remains enforced');

        foreach([
            'app/Views/arpa_appointments/division_form.php',
            'app/Views/arpa_appointments/subject_form.php',
            'app/Views/offices/form.php',
            'app/Views/officers/form.php',
            'app/Views/officers/office_assignment_form.php',
            'app/Views/hr/masters.php',
            'app/Views/users/roles.php',
            'app/Views/users/role_assignments.php',
            'app/Views/users/scope_assignments.php',
        ] as $view){$content=$this->file($view);$this->same(false,str_contains($content,'Save Draft'),"{$view} no longer offers Save Draft");}

        $locationController=$this->file('app/Controllers/LocationController.php');
        $this->contains("'INACTIVE','SUBMITTED'",$locationController,'completed Location entry is submitted directly');
        $this->contains("Auth::requirePermission('location.approve')",$locationController,'Location approval remains separately authorized');
        $this->contains('You cannot approve a Location you created.',$locationController,'Location maker-checker remains enforced');
        $this->same(false,str_contains($this->file('app/Views/locations/form.php'),'Save Draft'),'Location form no longer offers Save Draft');
        $routes=$this->file('routes/web.php');
        $this->contains("/locations/{id}/submit",$routes,'existing Location drafts retain a submit route');
        $this->contains("/locations/{id}/approve",$routes,'Location approval has a separate POST route');
        $registry=$this->file('app/Core/DataTableRegistry.php');
        $this->contains("Auth::can('location.approve')",$registry,'Location approval action remains permission-controlled');

        $after=[
            'division_created'=>(int)$pdo->query("SELECT COUNT(*) FROM arpa_division_appointment_request WHERE workflow_status='CREATED'")->fetchColumn(),
            'subject_created'=>(int)$pdo->query("SELECT COUNT(*) FROM arpa_subject_assignment_request WHERE workflow_status='CREATED'")->fetchColumn(),
            'office_assignment_drafts'=>(int)$pdo->query("SELECT COUNT(*) FROM officer_office_assignment WHERE approval_status='DRAFT'")->fetchColumn(),
        ];
        $this->same($before,$after,'focused workflow verification does not alter existing drafts');

        echo "DirectSubmissionWorkflowTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function file(string $path): string
    {
        $content=file_get_contents(BASE_PATH.'/'.$path);
        if($content===false)throw new RuntimeException("Unable to read {$path}");
        return $content;
    }

    private function contains(string $needle,string $haystack,string $message):void
    {
        $this->same(true,str_contains($haystack,$needle),$message);
    }

    private function same(mixed $expected,mixed $actual,string $message):void
    {
        $this->assertions++;
        if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
    }
}

exit((new DirectSubmissionWorkflowTest())->run());
