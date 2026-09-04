<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentCanonicalStartDateTest
{
    private int $assertions=0;

    public function run(): int
    {
        $view=file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/division_form.php');
        $formJs=file_get_contents(BASE_PATH.'/public/assets/js/arpa-division-form.js');
        $controller=file_get_contents(BASE_PATH.'/app/Controllers/ArpaAppointmentController.php');
        $service=file_get_contents(BASE_PATH.'/app/Services/ArpaAppointmentService.php');
        $optionsService=file_get_contents(BASE_PATH.'/app/Services/ArpaAppointmentFormOptionsService.php');
        $routes=file_get_contents(BASE_PATH.'/routes/web.php');
        $tabs=file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/tabs.php');
        $layout=file_get_contents(BASE_PATH.'/app/Views/layouts/admin.php');
        $dashboard=file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/dashboard.php');
        $registry=file_get_contents(BASE_PATH.'/app/Core/DataTableRegistry.php');

        $ascDerivedFromContext=true;
        $activeAsc=['location_name'=>'Kurunegala','location_dad_number'=>'70004-0000389'];
        $selectedAsc='asc-kurunegala';
        $effectiveDate='2026-08-22';
        $ascs=[['id'=>'forged-asc','dad_number'=>'70004-9999999','name_en'=>'Other ASC']];
        $officers=[['id'=>'officer-1','dad_number'=>'80000-0000001','name_with_initials'=>'A. Officer','arpa_service_permanency'=>'PERMANENT_IN_SERVICE','allowed_appointment_types'=>['PERMANENT']]];
        $arpaDivisions=[['id'=>'division-1','dad_number'=>'70007-0007026','name_en'=>'Wewagedara']];
        $reasons=[];
        $selectedOfficer='officer-1';$selectedDivision='division-1';$selectedAppointmentType='PERMANENT';$selectionMessages=[];$displayDate='22 Aug 2026';
        $_SERVER['REQUEST_URI']='/DEMS-PHP/public/hr/arpa-appointments/new';
        ob_start();require BASE_PATH.'/app/Views/arpa_appointments/division_form.php';$rendered=(string)ob_get_clean();

        $this->same(1,substr_count($view,'name="effective_from"'),'form has one canonical Appointment Start Date');
        $this->same(1,substr_count($view,'<form'),'the assignment uses one form');
        $this->contains('<h2 class="h5">Appointment Request</h2>',$view,'the form has one Appointment Request section');
        $this->same(false,str_contains($view,'Select Appointment Details'),'the separate appointment-details section is removed');
        $this->same(false,str_contains($view,'Load Eligible Officers and Vacancies'),'the manual candidate-loading button is removed');
        $this->contains('Appointment Start Date *',$view,'canonical date has the approved label');
        $this->contains('method="post"',$view,'Submit posts the canonical form');
        $this->contains('Csrf::field()',$view,'Submit retains CSRF protection');
        $this->contains('>Submit</button>',$view,'the normal action submits without a draft step');
        $this->contains('<?php if(!$ascDerivedFromContext): ?>',$view,'the ASC selector is excluded from an ASC-derived form');
        $this->contains('<strong>Agrarian Service Center:</strong>',$view,'the active ASC is shown as read-only information');
        $this->same(false,str_contains($formJs,"officer.value=''"),'changing the date no longer clears the selected Officer before refresh');
        $this->same(false,str_contains($formJs,"division.value=''"),'changing the date no longer clears the selected Division before refresh');
        $this->contains("target.searchParams.set('effective_from',date.value)",$formJs,'changing the date reloads candidates for that business date');
        $this->contains("target.searchParams.set('officer_id',previous.officer)",$formJs,'date refresh sends the previous Officer for server-side reconciliation');
        $this->contains("target.searchParams.set('arpa_division_location_id',previous.division)",$formJs,'date refresh sends the previous Division for server-side reconciliation');
        $this->contains("fetch(target.toString()",$formJs,'date-dependent options refresh without destroying the form');
        $this->contains("division.addEventListener('change',refresh)",$formJs,'Division changes recheck continuity and Data Issues server-side');
        $this->contains('$ascContext=$this->activeAscCreationContext()',$controller,'the controller resolves the active ASC context');
        $this->contains("(string)\$ascContext['location_id']",$controller,'the active context supplies the assignment ASC');
        $this->contains("\$submittedAsc!==''&&\$submittedAsc!==\$ascId",$controller,'a forged ASC is rejected for ASC-context creation');
        $this->contains("\$data['asc_location_id']=\$ascId",$controller,'the trusted ASC is injected server-side before submission');
        $this->contains('createAndSubmitDivisionAppointmentRequest($data,$actor)',$controller,'the controller creates and submits in one operational action');
        $this->contains("'/hr/arpa-appointments/submitted'",$controller,'successful submission redirects to Submitted');
        $this->contains("get('/hr/arpa-appointments/new', [ArpaAppointmentController::class, 'createDivision'])",$routes,'New Assignments route opens the create form directly');
        $this->contains("['New Assignments','hr/arpa-appointments/new','/hr/arpa-appointments/new']",$tabs,'top ARPA navigation uses the canonical create route');
        $this->contains("url('hr/arpa-appointments/new')",$layout,'sidebar New Assignments uses the canonical create route');
        $this->contains("url('hr/arpa-appointments/new')",$dashboard,'dashboard New Assignment button uses the canonical create route');
        $this->contains("url('hr/arpa-appointments/new?asc_location_id=",$registry,'vacancy action uses the canonical create route');
        $this->same(false,str_contains($controller,'Draft assignments and records returned for correction.'),'outdated Draft-list wording is removed');
        $this->contains("redirect('/hr/arpa-appointments/submitted/asc/'.\$id)",$controller,'old ASC New list URL leads to Submitted');
        $this->contains("redirect('/hr/arpa-appointments/submitted/district/'.\$id)",$controller,'old District New list URL leads to Submitted');
        $this->contains("data-options-url=\"<?= e(url('hr/arpa-appointments/new/options')) ?>\"",$view,'date refresh uses the secured dependent-options endpoint');
        $this->contains("assets/js/arpa-division-form.js",$view,'the form loads the local dependent-data behavior');
        $this->contains("get('/hr/arpa-appointments/new/options', [ArpaAppointmentController::class, 'divisionOptions'])",$routes,'dependent options use a dedicated GET route');
        $this->contains("href=\"<?= e(url('hr/arpa-appointments/submitted')) ?>\">Cancel",$view,'Cancel leaves the form for Submitted');
        $this->contains('divisionFormOptions($selectedAsc,$effectiveDate,$systemContext,$this->requestedDivisionSelection())',$controller,'candidate loading receives the canonical ASC, date, and retained selections');
        $this->contains('assertEligibleOfficer($officerId,$ascId,$effectiveFrom)',$service,'Officer eligibility uses the canonical date');
        $this->contains('assertDivisionPeriodAvailable($ascId,$divisionId,$effectiveFrom,$effectiveTo,true)',$service,'Division period validation uses the canonical business range');
        $this->contains('ArpaDivisionContinuityService',$optionsService,'form options include the canonical Division continuity calculation');
        $this->contains('Required Start Date',$view,'form displays the required next valid Division start date');
        $this->contains('assertCanFillPeriod($divisionId,$effectiveFrom,$effectiveTo',$service,'submission enforces complete-period Division continuity server-side');
        $this->same(false,str_contains($rendered,'name="asc_location_id"'),'ASC-context form renders no editable ASC field');
        $this->same(1,substr_count($rendered,'name="effective_from"'),'ASC-context form renders one Appointment Start Date');
        $this->contains('Agrarian Service Center:</strong> Kurunegala',$rendered,'ASC-context form displays its server-provided ASC');
        $this->contains('80000-0000001 - A. Officer',$rendered,'eligible Officer options render without another request');
        $this->contains('70007-0007026 - Wewagedara',$rendered,'vacant Division options render without another request');
        $this->contains('value="officer-1" data-allowed-types="PERMANENT" selected',$rendered,'a still-eligible Officer remains selected');
        $this->contains('value="division-1" data-required-next-start="" data-last-covered-through="" data-continuity-relation="" data-gap-end=""',$rendered,'a Division remains selected with full timeline metadata');
        $this->contains('ARPA Division / Period to Fill *',$view,'form no longer describes the timeline selector as current vacancy only');
        $this->contains('Maximum End Date',$view,'bounded historical gaps expose their maximum end date');

        echo "ArpaAppointmentCanonicalStartDateTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function contains(string $needle,string $haystack,string $message): void
    {
        $this->same(true,str_contains($haystack,$needle),$message);
    }

    private function same(mixed $expected,mixed $actual,string $message): void
    {
        $this->assertions++;
        if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
    }
}

exit((new ArpaAppointmentCanonicalStartDateTest())->run());
