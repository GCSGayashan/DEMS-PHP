<?php
declare(strict_types=1);

use App\Core\{DataTableQuery,DataTableRegistry,DataTableRequest,Database};
use App\Services\ArpaAppointmentRules;
use App\Services\ArpaAppointmentService;

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentManagementTest
{
    private PDO $pdo;
    private int $assertions=0;

    public function run(): int
    {
        $this->pdo=Database::pdo();
        $this->testAppointmentRules();
        $this->testSubjectRules();
        $this->testWorkflow();
        $this->testEffectiveStatus();
        $this->testSchemaAndZeroLegacyImport();
        $this->testDataTables();
        $this->testTransactionalLifecycle();
        echo "ArpaAppointmentManagementTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function testAppointmentRules(): void
    {
        ArpaAppointmentRules::assertAppointmentTypeAllowed('PERMANENT_IN_SERVICE','PERMANENT',false);$this->ok(true,'first Permanent is allowed');
        ArpaAppointmentRules::assertAppointmentTypeAllowed('PERMANENT_IN_SERVICE','ACTING',true);$this->ok(true,'Permanent-in-Service Acting is allowed with Permanent');
        ArpaAppointmentRules::assertAppointmentTypeAllowed('PERMANENT_IN_SERVICE','DUTY_COVERING',true);$this->ok(true,'multiple-capable Duty Covering type is allowed');
        ArpaAppointmentRules::assertAppointmentTypeAllowed('NOT_PERMANENT_IN_SERVICE','ATTEND_TO_DUTY',true);$this->ok(true,'Not-Permanent Attend to Duty is allowed with Permanent');
        $this->throws(fn()=>ArpaAppointmentRules::assertAppointmentTypeAllowed('PERMANENT_IN_SERVICE','ACTING',false),'dependent appointment requires Permanent');
        $this->throws(fn()=>ArpaAppointmentRules::assertAppointmentTypeAllowed('NOT_PERMANENT_IN_SERVICE','ACTING',true),'Acting prohibited for Not-Permanent');
        $this->throws(fn()=>ArpaAppointmentRules::assertAppointmentTypeAllowed('PERMANENT_IN_SERVICE','ATTEND_TO_DUTY',true),'Attend prohibited for Permanent-in-Service');
        $this->throws(fn()=>ArpaAppointmentRules::assertAppointmentTypeAllowed('','PERMANENT',false),'service permanency is mandatory');
        $this->same(true,ArpaAppointmentRules::intervalsOverlap('2026-01-01',null,'2027-01-01',null),'open periods overlap');
        $this->same(false,ArpaAppointmentRules::intervalsOverlap('2026-01-01','2026-01-31','2026-02-01',null),'sequential periods do not overlap');
        $this->same(true,ArpaAppointmentRules::intervalsOverlap('2026-01-01','2026-01-31','2026-01-31','2026-02-02'),'inclusive effective boundary overlaps');
    }

    private function testSubjectRules(): void
    {
        $this->same(false,ArpaAppointmentRules::subjectIsExclusive('NORMAL'),'normal subjects coexist');
        foreach(['AGRARIAN_BANK','SALES_SHOP','SITHAMU'] as $kind)$this->same(true,ArpaAppointmentRules::subjectIsExclusive($kind),"{$kind} is officer-exclusive");
    }

    private function testWorkflow(): void
    {
        $steps=[
            ['CREATED','SUBMIT','CREATOR','SUBMITTED'],['RETURNED','SUBMIT','CREATOR','SUBMITTED'],
            ['SUBMITTED','VERIFY','ASC','ASC_VERIFIED'],['ASC_VERIFIED','APPROVE','ASC','ASC_APPROVED'],
            ['ASC_APPROVED','VERIFY','DISTRICT','DISTRICT_VERIFIED'],['DISTRICT_VERIFIED','APPROVE','DISTRICT','DISTRICT_APPROVED'],
            ['DISTRICT_APPROVED','VERIFY','NATIONAL','NATIONAL_VERIFIED'],['NATIONAL_VERIFIED','APPROVE','NATIONAL','NATIONAL_APPROVED'],
        ];
        foreach($steps as [$from,$action,$stage,$expected])$this->same($expected,ArpaAppointmentRules::transition($from,$action,$stage)['status'],"{$from} {$action} transition");
        $this->same('RETURNED',ArpaAppointmentRules::transition('ASC_VERIFIED','RETURN_FOR_CORRECTION','ASC')['status'],'ASC Administrator returns to ASC creator correction');
        $this->same('RETURNED',ArpaAppointmentRules::transition('ASC_APPROVED','RETURN_FOR_CORRECTION','DISTRICT')['status'],'District Subject Officer returns to ASC correction');
        $this->same('RETURNED',ArpaAppointmentRules::transition('DISTRICT_VERIFIED','RETURN_FOR_CORRECTION','DISTRICT')['status'],'District Administrator returns to ASC correction');
        $this->same('RETURNED',ArpaAppointmentRules::transition('DISTRICT_APPROVED','RETURN_FOR_CORRECTION','NATIONAL')['status'],'National Subject Officer returns to ASC correction');
        $this->same('RETURNED',ArpaAppointmentRules::transition('NATIONAL_VERIFIED','RETURN_FOR_CORRECTION','NATIONAL')['status'],'National Administrator returns to ASC correction');
        $this->same('RETURNED',ArpaAppointmentRules::transition('NATIONAL_VERIFIED','REJECT','NATIONAL')['status'],'rejection returns to ASC correction');
        $this->same('RETURNED',ArpaAppointmentRules::transition('SUBMITTED','REJECT','ASC')['status'],'ASC rejection returns to ASC correction');
        $this->same('RETURNED',ArpaAppointmentRules::transition('ASC_VERIFIED','REJECT','ASC')['status'],'ASC Administrator rejection returns to ASC correction');
        $this->same('RETURNED',ArpaAppointmentRules::transition('ASC_APPROVED','REJECT','DISTRICT')['status'],'District Subject Officer rejection returns to ASC correction');
        $this->same('RETURNED',ArpaAppointmentRules::transition('DISTRICT_VERIFIED','REJECT','DISTRICT')['status'],'District Administrator rejection returns to ASC correction');
        $this->same('RETURNED',ArpaAppointmentRules::transition('DISTRICT_APPROVED','REJECT','NATIONAL')['status'],'National Subject Officer rejection returns to ASC correction');
        $this->throws(fn()=>ArpaAppointmentRules::transition('ASC_VERIFIED','APPROVE','DISTRICT'),'cross-level approval is rejected');
        $this->throws(fn()=>ArpaAppointmentRules::transition('CREATED','APPROVE','NATIONAL'),'draft cannot be approved');
        $this->throws(fn()=>ArpaAppointmentRules::transition('NATIONAL_APPROVED','RETURN_FOR_CORRECTION','NATIONAL'),'final record cannot be destructively returned');
    }

    private function testEffectiveStatus(): void
    {
        $this->same('SCHEDULED',ArpaAppointmentRules::operationalStatus('2030-01-01',null,'2026-01-01'),'future approved appointment is scheduled');
        $this->same('ACTIVE',ArpaAppointmentRules::operationalStatus('2020-01-01',null,'2026-01-01'),'current approved appointment is active');
        $this->same('ENDED',ArpaAppointmentRules::operationalStatus('2020-01-01','2025-12-31','2026-01-01'),'expired appointment is ended');
        $this->same('ACTIVE',ArpaAppointmentRules::operationalStatus('2020-01-01','2026-01-01','2026-01-01'),'effective-to is inclusive');
    }

    private function testSchemaAndZeroLegacyImport(): void
    {
        $tables=['arpa_appointment_end_reason','arpa_service_permanency_history','arpa_division_appointment_request','arpa_division_appointment','arpa_division_appointment_closure','arpa_appointment_workflow_action','subject_master','arpa_subject_assignment_request','arpa_subject_assignment','arpa_subject_assignment_closure','arpa_subject_workflow_action','arpa_officer_sub_designation_period','arpa_officer_sub_designation_closure'];
        foreach($tables as $table)$this->same(1,$this->scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='{$table}'"),"{$table} exists");
        $this->same(17,$this->scalar('SELECT COUNT(*) FROM arpa_appointment_end_reason'),'normalized end-reason master includes End of Appointment Period');
        $this->same(1,$this->scalar("SELECT COUNT(*) FROM arpa_appointment_end_reason WHERE system_key='END_OF_APPOINTMENT_PERIOD'"),'End of Appointment Period reason exists exactly once');
        $this->same(1,$this->scalar("SELECT active FROM arpa_appointment_end_reason WHERE system_key='END_OF_APPOINTMENT_PERIOD'"),'End of Appointment Period reason is active');
        $this->same(0,$this->scalar("SELECT service_terminating FROM arpa_appointment_end_reason WHERE system_key='END_OF_APPOINTMENT_PERIOD'"),'End of Appointment Period does not terminate officer service');
        $this->same(24,$this->scalar("SELECT COUNT(*) FROM application_permission WHERE module_code='ARPA_APPOINTMENT'"),'module permissions include workflow, legacy review, and direct scoped data-correction access');
        $this->same(2,$this->scalar("SELECT COUNT(*) FROM application_permission WHERE permission_key IN('arpa.legacy-reconciliation.view','arpa.legacy-reconciliation.decide')"),'legacy reconciliation permissions are explicit');
        $this->same(3,$this->scalar("SELECT COUNT(*) FROM application_permission WHERE module_code='SUBJECT_MANAGEMENT'"),'Head Office Subject Master permissions seeded');
        $this->same(3,$this->scalar("SELECT COUNT(*) FROM subject_master WHERE system_key IN('AGRARIAN_BANK','SALES_SHOP','SITHAMU')"),'three central system subjects exist');
        $this->same(1,$this->scalar("SELECT COUNT(*) FROM number_category WHERE category_key='SUBJECT' AND category_code='72007' AND active=1"),'SUBJECT enterprise-number category exists');
        $this->same(4,$this->scalar("SELECT next_value FROM number_category WHERE category_key='SUBJECT'"),'SUBJECT category advances after three seeded allocations');
        $this->same(3,$this->scalar("SELECT COUNT(*) FROM subject_master WHERE system_key IN('AGRARIAN_BANK','SALES_SHOP','SITHAMU') AND dad_number REGEXP '^72007-[0-9]{7}$'"),'seeded subjects have SUBJECT DAD numbers');
        $this->same(3,$this->scalar("SELECT COUNT(DISTINCT dad_number) FROM subject_master WHERE system_key IN('AGRARIAN_BANK','SALES_SHOP','SITHAMU')"),'seeded Subject DAD numbers are unique');
        $this->same(3,$this->scalar("SELECT COUNT(*) FROM number_allocation a JOIN number_category c ON c.id=a.category_id JOIN subject_master s ON s.dad_number=a.allocated_number WHERE c.category_key='SUBJECT' AND s.system_key IN('AGRARIAN_BANK','SALES_SHOP','SITHAMU')"),'seeded allocations are auditable in the enterprise ledger');
        $this->same('NO',(string)$this->value("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='subject_master' AND column_name='dad_number'"),'normal Subject Masters require an enterprise number');
        $this->same(0,$this->scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='subject_master' AND column_name='asc_location_id'"),'central Subject Master has no ASC ownership');
        $this->same('YES',(string)$this->value("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_subject_assignment_request' AND column_name='asc_location_id'"),'legacy history may truthfully retain unresolved ASC context');
        $this->same(1,$this->scalar("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='arpa_subject_assignment_request' AND constraint_name='chk_arpa_subject_request_native_asc'"),'native subject requests still require assignment ASC context');
        $this->same('NO',(string)$this->value("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_subject_assignment_request' AND column_name='subject_id'"),'subject request requires a central subject');
        $this->same(1,$this->scalar("SELECT COUNT(*) FROM designation d JOIN designation p ON p.id=d.parent_designation_id WHERE d.system_key='SITHAMU' AND d.designation_level='SUB' AND p.system_key='ARPA_OFFICER'"),'Sithamu is an ARPA sub-designation');
        $this->same(0,$this->scalar("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='arpa_subject_assignment' AND non_unique=0 AND column_name IN('asc_location_id','subject_id')"),'no ASC-subject one-officer uniqueness exists');
        $this->same('YES',(string)$this->value("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='officer' AND column_name='arpa_service_permanency'"),'service permanency supports an explicit not-yet-recorded state');
        foreach(['letter_reference_id','letter_type','letter_date','letter_generation_status'] as $column)$this->same(1,$this->scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_division_appointment' AND column_name='{$column}'"),"letter hook {$column} reserved");
        foreach(['target_appointment_request_id'=>'arpa_division_appointment_request','target_appointment_id'=>'arpa_division_appointment','target_subject_request_id'=>'arpa_subject_assignment_request','target_subject_assignment_id'=>'arpa_subject_assignment'] as $reference=>$table)$this->same(0,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_appointment_source_reference sr JOIN {$table} target ON target.id=sr.{$reference} WHERE target.record_origin<>'LEGACY_IMPORT'"),"legacy source references never point to native rows in {$table}");
        $migration=file_get_contents(BASE_PATH.'/database/migrations/013_arpa_officer_appointment_management.sql');$correction=file_get_contents(BASE_PATH.'/database/migrations/014_global_subject_master_and_sithamu_history.sql');$numbering=file_get_contents(BASE_PATH.'/database/migrations/016_subject_enterprise_numbering.sql');
        $this->same(false,str_contains($migration,'tbl_officer_apoint'),'legacy appointment source is not referenced');
        $this->same(false,str_contains($migration,'INSERT INTO arpa_division_appointment('),'schema migration does not insert operational appointments');
        $this->same(false,str_contains($correction,'dems_legacy_hr.tbl_subject'),'legacy tbl_subject is not migrated');
        $this->same(false,str_contains($correction,'tbl_user_has_subject'),'legacy user access subjects are not migrated');
        $this->same(false,str_contains($numbering,'dems_legacy_hr'),'numbering migration does not read the legacy database');
        $form=file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/subject_form.php');$this->same(false,str_contains($form,'Create Subject Master'),'assignment form cannot create Subject Masters');$this->same(true,str_contains($form,'name="asc_location_id"'),'assignment form captures ASC separately');
    }

    private function testDataTables(): void
    {
        $_SESSION=[];
        $admin=$this->pdo->query("SELECT su.id FROM system_user su JOIN user_account_role uar ON uar.user_id=su.id JOIN application_role r ON r.id=uar.role_id WHERE r.role_code='SYSTEM_ADMIN' LIMIT 1")->fetchColumn();
        if(!$admin)throw new RuntimeException('Seeded SYSTEM_ADMIN is required.');$_SESSION['user_id']=$admin;
        foreach(['arpa-division-appointments','arpa-subject-assignments','arpa-pending-actions','arpa-new-appointments','arpa-submitted-appointments','arpa-approval-verification','arpa-open-appointments','arpa-historical-appointments','arpa-vacant-divisions','arpa-appointment-issues','subjects'] as $key){$config=DataTableRegistry::definition($key);$response=(new DataTableQuery($this->pdo,$config,new DataTableRequest(['length'=>10])))->response();$this->same(true,count($response['data'])<=10,"{$key} uses server pagination");$this->same(true,$response['recordsTotal']>=$response['recordsFiltered'],"{$key} count contract");}
        $officer=(string)$this->pdo->query("SELECT o.id FROM officer o JOIN designation d ON d.id=o.primary_designation_id WHERE d.system_key='ARPA_OFFICER' LIMIT 1")->fetchColumn();
        foreach(['arpa-officer-division-history','arpa-officer-subject-history','arpa-service-permanency-history','arpa-officer-workflow-history'] as $key){$config=DataTableRegistry::definition($key,['officer_id'=>$officer]);$response=(new DataTableQuery($this->pdo,$config,new DataTableRequest(['length'=>10])))->response();$this->same(true,count($response['data'])<=10,"{$key} uses server pagination");}
    }

    private function testTransactionalLifecycle(): void
    {
        $before=$this->state();$this->pdo->beginTransaction();
        try {
            $creator=(string)$this->pdo->query('SELECT id FROM system_user ORDER BY id LIMIT 1')->fetchColumn();
            $verify='00000000-0000-4000-8000-000000000101';$approve='00000000-0000-4000-8000-000000000102';
            $this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,account_status,enabled) VALUES(?,'STAFF',?,'ACTIVE',1),(?,'STAFF',?,'ACTIVE',1)")->execute([$verify,'arpa-test-verifier',$approve,'arpa-test-approver']);
            $officers=$this->pdo->query("SELECT o.id FROM officer o JOIN designation d ON d.id=o.primary_designation_id WHERE d.system_key='ARPA_OFFICER' AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.officer_id=o.id) AND NOT EXISTS(SELECT 1 FROM arpa_subject_assignment s WHERE s.officer_id=o.id) ORDER BY o.id LIMIT 2 FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);$officer=(string)$officers[0];$secondOfficer=(string)$officers[1];
            $location=$this->pdo->query("SELECT lr.parent_location_id asc_id,MIN(lr.child_location_id) arpa_id FROM location_relationship lr JOIN location l ON l.id=lr.child_location_id WHERE lr.relationship_type='ASC_ARPA_DIVISION' AND lr.active=1 AND lr.approval_status='APPROVED' AND l.approval_status='APPROVED' AND l.operational_status='ACTIVE' AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.arpa_division_location_id=l.id AND c.id IS NULL) GROUP BY lr.parent_location_id HAVING COUNT(*)>=3 ORDER BY lr.parent_location_id LIMIT 1")->fetch();
            $service=new ArpaAppointmentService($this->pdo);$today=date('Y-m-d');$tomorrow=date('Y-m-d',strtotime('+1 day'));
            $normalSubject=$service->createSubject('Transactional Test Subject','NORMAL','TRANSACTIONAL_TEST_SUBJECT',$creator);
            $normalDad=(string)$this->value("SELECT dad_number FROM subject_master WHERE id='{$normalSubject}'");
            $this->same(1,preg_match('/^72007-[0-9]{7}$/',$normalDad),'new Head Office subject uses the SUBJECT allocator');
            $this->same(1,$this->scalar("SELECT COUNT(*) FROM number_allocation WHERE allocated_number='{$normalDad}'"),'new Subject allocation is recorded in the enterprise ledger');
            $service->setServicePermanency($officer,'PERMANENT_IN_SERVICE',$today,'Test lifecycle',$creator);
            $this->throws(fn()=>$service->createDivisionAppointmentRequest(['officer_id'=>$officer,'appointment_type'=>'PERMANENT','asc_location_id'=>$location['asc_id'],'arpa_division_location_id'=>$location['arpa_id'],'effective_from'=>$today],$creator),'ARPA request creation requires a matching ASC Office assignment');
            $ascOffice=(string)$this->value("SELECT o.id FROM office o JOIN office_type ot ON ot.id=o.office_type_id WHERE ot.system_key='ASC_OFFICE' AND o.linked_location_id='{$location['asc_id']}'");
            foreach([$officer,$secondOfficer] as $assignedOfficer)$this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,approval_status,active,reason,created_by,submitted_by,submitted_at,approved_by,approved_at) VALUES(UUID(),?,?,?,'APPROVED',1,'ARPA lifecycle fixture',?,?,NOW(),?,NOW())")->execute([$assignedOfficer,$ascOffice,$today,$creator,$creator,$approve]);
            $request=$service->createAndSubmitDivisionAppointmentRequest(['officer_id'=>$officer,'appointment_type'=>'PERMANENT','asc_location_id'=>$location['asc_id'],'arpa_division_location_id'=>$location['arpa_id'],'effective_from'=>$today],$creator);
            $this->same($today,(string)$this->value("SELECT requested_effective_from FROM arpa_division_appointment_request WHERE id='{$request}'"),'submission preserves the canonical Appointment Start Date');
            $this->same('SUBMITTED',(string)$this->value("SELECT workflow_status FROM arpa_division_appointment_request WHERE id='{$request}'"),'new ARPA assignment is submitted directly');
            $this->same(1,$this->scalar("SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE request_id='{$request}' AND action='SUBMIT'"),'direct submission records its workflow event');
            $this->same(0,$this->scalar("SELECT COUNT(*) FROM arpa_division_appointment WHERE request_id='{$request}'"),'submitted assignment is not current before approval');
            $service->workflow('division',$request,'VERIFY','ASC',null,$creator);
            $this->throws(fn()=>$service->workflow('division',$request,'APPROVE','ASC',null,$creator),'same Subject Officer may create and verify but cannot administrator-approve');
            $service->workflow('division',$request,'APPROVE','ASC',null,$approve);
            $this->throws(fn()=>$service->workflow('division',$request,'VERIFY','DISTRICT',null,$verify),'District verification requires District review information');
            $service->saveStageReview('division',$request,'DISTRICT','District lifecycle review',null,$verify);
            $service->saveStageReview('division',$request,'DISTRICT','District lifecycle review revised','Reviewed evidence',$verify);
            $this->same(2,$this->scalar("SELECT COUNT(*) FROM arpa_appointment_stage_review_audit WHERE request_id='{$request}' AND review_stage='DISTRICT'"),'changing District review retains immutable audit history');
            $service->workflow('division',$request,'VERIFY','DISTRICT',null,$verify);
            $this->throws(fn()=>$service->workflow('division',$request,'APPROVE','DISTRICT',null,$verify),'District review maker/verifier cannot District approve');
            $service->workflow('division',$request,'APPROVE','DISTRICT',null,$approve);
            $service->saveStageReview('division',$request,'NATIONAL','National lifecycle review',null,$verify);
            $service->workflow('division',$request,'VERIFY','NATIONAL',null,$verify);
            $this->throws(fn()=>$service->workflow('division',$request,'APPROVE','NATIONAL',null,$verify),'National review maker/verifier cannot final approve');
            $service->workflow('division',$request,'APPROVE','NATIONAL',null,$approve);
            $appointment=(string)$this->value("SELECT id FROM arpa_division_appointment WHERE request_id='{$request}'");
            $this->same(1,$this->scalar("SELECT COUNT(*) FROM arpa_division_appointment WHERE id='{$appointment}'"),'final approval creates one operational appointment');
            $actingRequest=$service->createDivisionAppointmentRequest(['officer_id'=>$officer,'appointment_type'=>'ACTING','asc_location_id'=>$location['asc_id'],'arpa_division_location_id'=>$this->anotherDivision((string)$location['asc_id'],(string)$location['arpa_id']),'effective_from'=>$today],$creator);
            $this->approveRequest($service,'division',$actingRequest,$creator,$verify,$approve);
            $reason=(string)$this->value("SELECT id FROM arpa_appointment_end_reason WHERE system_key='TRANSFER'");
            $endRequest=$service->createEndRequest($appointment,$today,$reason,'Lifecycle end',$creator);
            $this->approveRequest($service,'division',$endRequest,$creator,$verify,$approve);
            $this->same(2,$this->scalar("SELECT COUNT(*) FROM arpa_division_appointment_closure WHERE request_id='{$endRequest}'"),'ending Permanent creates independent source and dependent closure events');
            $this->same(2,$this->scalar("SELECT COUNT(*) FROM arpa_division_appointment_closure WHERE request_id='{$endRequest}' AND letter_date='{$today}'"),'backdated/removal letter date hook follows effective-to');

            $subject=(string)$this->value("SELECT id FROM subject_master WHERE system_key='AGRARIAN_BANK'");
            $subjectRequest=$service->createSubjectAssignmentRequest(['officer_id'=>$officer,'asc_location_id'=>$location['asc_id'],'subject_id'=>$subject,'effective_from'=>$tomorrow],$creator);
            $this->approveRequest($service,'subject',$subjectRequest,$creator,$verify,$approve);
            $this->same(1,$this->scalar("SELECT COUNT(*) FROM arpa_subject_assignment WHERE request_id='{$subjectRequest}' AND officer_exclusive_snapshot=1"),'Bank assignment is operational and officer-exclusive after final approval');
            $secondBankRequest=$service->createSubjectAssignmentRequest(['officer_id'=>$secondOfficer,'asc_location_id'=>$location['asc_id'],'subject_id'=>$subject,'effective_from'=>$tomorrow],$creator);$this->approveRequest($service,'subject',$secondBankRequest,$creator,$verify,$approve);
            $this->same(2,$this->scalar("SELECT COUNT(*) FROM arpa_subject_assignment WHERE subject_id='{$subject}' AND asc_location_id='{$location['asc_id']}'"),'multiple officers share one global Bank subject at the same ASC');
            $newPermanent=$service->createDivisionAppointmentRequest(['officer_id'=>$officer,'appointment_type'=>'PERMANENT','asc_location_id'=>$location['asc_id'],'arpa_division_location_id'=>$this->anotherDivision((string)$location['asc_id'],(string)$location['arpa_id'],true),'effective_from'=>$tomorrow],$creator);
            $this->throws(fn()=>$this->approveRequest($service,'division',$newPermanent,$creator,$verify,$approve),'exclusive subject blocks concurrent Division approval');
            $bankAssignment=(string)$this->value("SELECT id FROM arpa_subject_assignment WHERE request_id='{$subjectRequest}'");$bankEnd=$service->createSubjectEndRequest($bankAssignment,$tomorrow,$reason,'End Bank',$creator);$this->approveRequest($service,'subject',$bankEnd,$creator,$verify,$approve);
            $sithamu=(string)$this->value("SELECT id FROM subject_master WHERE system_key='SITHAMU'");$sithamuFrom=date('Y-m-d',strtotime('+2 days'));$sithamuRequest=$service->createSubjectAssignmentRequest(['officer_id'=>$officer,'asc_location_id'=>$location['asc_id'],'subject_id'=>$sithamu,'effective_from'=>$sithamuFrom],$creator);$this->approveRequest($service,'subject',$sithamuRequest,$creator,$verify,$approve);
            $sithamuAssignment=(string)$this->value("SELECT id FROM arpa_subject_assignment WHERE request_id='{$sithamuRequest}'");$this->same(1,$this->scalar("SELECT COUNT(*) FROM arpa_officer_sub_designation_period WHERE source_subject_assignment_id='{$sithamuAssignment}' AND designation_key_snapshot='SITHAMU'"),'Sithamu assignment atomically opens sub-designation period');
            $sithamuEnd=$service->createSubjectEndRequest($sithamuAssignment,$sithamuFrom,$reason,'End Sithamu',$creator);$this->approveRequest($service,'subject',$sithamuEnd,$creator,$verify,$approve);$this->same(1,$this->scalar("SELECT COUNT(*) FROM arpa_officer_sub_designation_closure c JOIN arpa_subject_assignment_closure ac ON ac.id=c.source_subject_assignment_closure_id WHERE ac.assignment_id='{$sithamuAssignment}' AND c.effective_to='{$sithamuFrom}'"),'ending Sithamu atomically closes the synchronized period');
        } finally {
            if($this->pdo->inTransaction())$this->pdo->rollBack();
        }
        $this->same($before,$this->state(),'transactional lifecycle test leaves operational database unchanged');
    }

    private function approveRequest(ArpaAppointmentService $service,string $entity,string $id,string $creator,string $verify,string $approve):void
    {
        $service->workflow($entity,$id,'SUBMIT','CREATOR',null,$creator);
        $service->workflow($entity,$id,'VERIFY','ASC',null,$verify);
        $service->workflow($entity,$id,'APPROVE','ASC',null,$approve);
        $service->saveStageReview($entity,$id,'DISTRICT','District fixture review',null,$verify);
        $service->workflow($entity,$id,'VERIFY','DISTRICT',null,$verify);
        $service->workflow($entity,$id,'APPROVE','DISTRICT',null,$approve);
        $service->saveStageReview($entity,$id,'NATIONAL','National fixture review',null,$verify);
        $service->workflow($entity,$id,'VERIFY','NATIONAL',null,$verify);
        $service->workflow($entity,$id,'APPROVE','NATIONAL',null,$approve);
    }

    private function anotherDivision(string $ascId,string $exclude,bool $third=false):string
    {
        $offset=$third?1:0;$stmt=$this->pdo->prepare("SELECT l.id FROM location_relationship lr JOIN location l ON l.id=lr.child_location_id JOIN location_type t ON t.id=l.location_type_id WHERE lr.parent_location_id=? AND lr.relationship_type='ASC_ARPA_DIVISION' AND lr.active=1 AND lr.approval_status='APPROVED' AND t.system_key='ARPA_DIVISION' AND l.id<>? AND l.approval_status='APPROVED' AND l.operational_status='ACTIVE' AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.arpa_division_location_id=l.id AND c.id IS NULL) ORDER BY l.id LIMIT {$offset},1");$stmt->execute([$ascId,$exclude]);$id=$stmt->fetchColumn();if(!$id)throw new RuntimeException('Fixture ASC requires at least three vacant ARPA Divisions.');return (string)$id;
    }

    private function state():array{return ['users'=>$this->scalar('SELECT COUNT(*) FROM system_user'),'permanency'=>$this->scalar('SELECT COUNT(*) FROM arpa_service_permanency_history'),'division_requests'=>$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_request'),'division_appointments'=>$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment'),'division_closures'=>$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_closure'),'subjects'=>$this->scalar('SELECT COUNT(*) FROM subject_master'),'subject_number_next'=>$this->scalar("SELECT next_value FROM number_category WHERE category_key='SUBJECT'"),'subject_number_allocations'=>$this->scalar("SELECT COUNT(*) FROM number_allocation a JOIN number_category c ON c.id=a.category_id WHERE c.category_key='SUBJECT'"),'subject_requests'=>$this->scalar('SELECT COUNT(*) FROM arpa_subject_assignment_request'),'subject_assignments'=>$this->scalar('SELECT COUNT(*) FROM arpa_subject_assignment'),'subject_closures'=>$this->scalar('SELECT COUNT(*) FROM arpa_subject_assignment_closure'),'sub_designation_periods'=>$this->scalar('SELECT COUNT(*) FROM arpa_officer_sub_designation_period'),'sub_designation_closures'=>$this->scalar('SELECT COUNT(*) FROM arpa_officer_sub_designation_closure')];}

    private function scalar(string $sql):int{return (int)$this->pdo->query($sql)->fetchColumn();}
    private function value(string $sql):mixed{return $this->pdo->query($sql)->fetchColumn();}
    private function ok(bool $value,string $message):void{$this->same(true,$value,$message);}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
}

exit((new ArpaAppointmentManagementTest())->run());
