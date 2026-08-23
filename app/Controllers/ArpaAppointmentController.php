<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Controller, Csrf, Database, DataTableRegistry, ScopeService};
use App\Services\ArpaAppointmentService;
use App\Services\ArpaAppointmentCandidateService;
use App\Services\ArpaAppointmentReadService;
use App\Services\ArpaAppointmentDataIssueCorrectionService;
use App\Services\ArpaWorkflowQueuePolicy;
use DomainException;
use Throwable;

final class ArpaAppointmentController extends Controller
{
    public function dashboard(): void
    {
        Auth::requirePermission('arpa.appointment.view');
        $stats=(new \App\Services\ScopedDashboardService(Database::pdo()))->arpaModuleCounts((string)Auth::user()['id']);
        $this->render('arpa_appointments/dashboard',compact('stats'));
    }

    public function divisions(): void
    {
        redirect('/hr/arpa-appointments/open');
    }

    public function newAppointments():void
    {
        $this->createDivision();
    }

    public function newAppointmentsAsc(string $id):void
    {
        redirect('/hr/arpa-appointments/submitted/asc/'.$id);
    }

    public function submittedAppointments():void
    {
        Auth::requirePermission('arpa.appointment.view');

        if(!$this->workflowQueuePolicy()->canUseWorkflowQueues((string)Auth::user()['id'])){
            $this->workflowQueueForbidden();
            return;
        }

        if($this->appointmentHierarchySummary(
            'Submitted',
            'arpa-submitted-appointments-summary',
            null,
            null,
            'Assignments waiting for your review or approval.'
        ))return;

        $this->appointmentList(
            'Submitted',
            'arpa-submitted-appointments',
            null,
            null,
            'Assignments waiting for your review or approval.'
        );
    }

    public function submittedAppointmentsAsc(string $id):void
    {
        Auth::requirePermission('arpa.appointment.view');

        if(!$this->workflowQueuePolicy()->canUseWorkflowQueues((string)Auth::user()['id'])){
            $this->workflowQueueForbidden();
            return;
        }

        $this->appointmentAscList(
            'Submitted',
            'arpa-submitted-appointments',
            'hr/arpa-appointments/submitted',
            $id,
            'Assignments waiting for action at the selected Agrarian Service Center.'
        );
    }

    public function approvalVerification():void
    {
        Auth::requirePermission('arpa.appointment.view');

        if(!$this->workflowQueuePolicy()->canUseWorkflowQueues((string)Auth::user()['id'])){
            $this->workflowQueueForbidden();
            return;
        }

        if($this->appointmentHierarchySummary(
            'Review & Approve',
            'arpa-approval-verification-summary',
            null,
            null,
            'Assignments you reviewed or approved during the current review cycle.'
        ))return;

        $this->appointmentList(
            'Review & Approve',
            'arpa-approval-verification',
            null,
            null,
            'Assignments you reviewed or approved during the current review cycle.'
        );
    }

    public function approvalVerificationAsc(string $id):void
    {
        Auth::requirePermission('arpa.appointment.view');

        if(!$this->workflowQueuePolicy()->canUseWorkflowQueues((string)Auth::user()['id'])){
            $this->workflowQueueForbidden();
            return;
        }

        $this->appointmentAscList(
            'Review & Approve',
            'arpa-approval-verification',
            'hr/arpa-appointments/approval',
            $id,
            'Completed reviews and approvals for the selected Agrarian Service Center.'
        );
    }

    public function openAppointments():void
    {
        Auth::requirePermission('arpa.appointment.view');

        if($this->appointmentHierarchySummary(
            'Current Assignments',
            'arpa-open-appointments-summary',
            'hr/arpa-appointments/new',
            'New Assignment',
            'Current and scheduled ARPA Division assignments.'
        ))return;

        $this->appointmentList(
            'Current Assignments',
            'arpa-open-appointments',
            'hr/arpa-appointments/new',
            'New Assignment',
            'Current and scheduled ARPA Division assignments.'
        );
    }

    public function openAppointmentsAsc(string $id):void
    {
        Auth::requirePermission('arpa.appointment.view');
        $this->appointmentAscList(
            'Current Assignments',
            'arpa-open-appointments',
            'hr/arpa-appointments/open',
            $id,
            'Current and scheduled assignments for the selected Agrarian Service Center.'
        );
    }
    public function vacantDivisions():void
    {
        Auth::requirePermission('arpa.appointment.view');
        $this->appointmentList('Vacant ARPA Divisions','arpa-vacant-divisions',null,null,'Approved active ARPA Divisions with no open or scheduled operational appointment.');
    }

    public function dataIssues():void
    {
        Auth::requirePermission('arpa.appointment.view');
        $category=strtoupper(trim((string)($_GET['category']??'CURRENT_ACTION_REQUIRED')));
        $categories=['CURRENT_ACTION_REQUIRED','HISTORICAL_EXCEPTIONS','LEGACY_DATA_WARNINGS','RESOLVED_REVIEWED'];
        if(!in_array($category,$categories,true))$category='CURRENT_ACTION_REQUIRED';
        $key=$category==='RESOLVED_REVIEWED'?'arpa-appointment-corrections':'arpa-appointment-issues';
        $initial=$category==='RESOLVED_REVIEWED'?[]:['category'=>$category];
        $dataTable=DataTableRegistry::viewModel($key,[],$this->filterOptions(),$initial);
        $this->render('arpa_appointments/issues/index',compact('category','categories','dataTable'));
    }

    public function dataIssueDetail(string $key):void
    {
        Auth::requirePermission('arpa.appointment.view');
        try{
            $service=$this->dataIssueCorrectionService();
            $detail=$service->detail(rawurldecode($key),(string)Auth::user()['id']);
            $this->render('arpa_appointments/issues/detail',$detail);
        }catch(DomainException $e){http_response_code(404);$this->flash('danger',$e->getMessage());redirect('/hr/arpa-appointments/issues');}
    }

    public function correctDataIssue(string $key):void
    {
        Auth::requirePermission(ArpaAppointmentDataIssueCorrectionService::PERMISSION);
        $service=$this->dataIssueCorrectionService();$rowKey=rawurldecode($key);$issue=$service->issue($rowKey);$actor=(string)Auth::user()['id'];
        if(!$issue){http_response_code(404);$this->flash('danger','The issue is no longer active.');redirect('/hr/arpa-appointments/issues');}
        if(!$service->canCorrect($actor,(string)$issue['asc_location_id'])){http_response_code(403);$this->render('partials/forbidden',['permission'=>'an active ASC Subject Officer role and approved ASC EXACT scope']);return;}
        Csrf::validate();
        try{$result=$service->correct($rowKey,$_POST,$actor);$this->flash('success',$result['issue_remaining']?'Correction saved. This record still needs checking.':'Correction saved. This issue is now resolved.');redirect('/hr/arpa-appointments/issues/corrections/'.$result['correction_id']);}
        catch(DomainException $e){$this->flash('danger',$e->getMessage());redirect('/hr/arpa-appointments/issues/'.rawurlencode($rowKey));}
        catch(Throwable $e){error_log('ARPA data-issue correction failed: '.$e->getMessage());$this->flash('danger','The correction could not be saved. No information was changed.');redirect('/hr/arpa-appointments/issues/'.rawurlencode($rowKey));}
    }

    public function dataIssueCorrectionDetail(string $id):void
    {
        Auth::requirePermission('arpa.appointment.view');
        try{$correction=$this->dataIssueCorrectionService()->correction($id,(string)Auth::user()['id']);$this->render('arpa_appointments/issues/correction_detail',compact('correction'));}
        catch(DomainException $e){http_response_code(404);$this->flash('danger',$e->getMessage());redirect('/hr/arpa-appointments/issues?category=RESOLVED_REVIEWED');}
    }

    public function history(): void
    {
        Auth::requirePermission('arpa.appointment.view');

        if($this->appointmentHierarchySummary(
            'Assignment History',
            'arpa-historical-appointments-summary',
            null,
            null,
            'Previous ARPA Division assignments in date order.'
        ))return;

        $this->appointmentList(
            'Assignment History',
            'arpa-historical-appointments',
            null,
            null,
            'Previous ARPA Division assignments in date order.'
        );
    }

    public function historyAsc(string $id):void
    {
        Auth::requirePermission('arpa.appointment.view');
        $this->appointmentAscList(
            'Assignment History',
            'arpa-historical-appointments',
            'hr/arpa-appointments/history',
            $id,
            'Previous assignments for the selected Agrarian Service Center.'
        );
    }

    public function newAppointmentsDistrict(string $id):void
    {
        redirect('/hr/arpa-appointments/submitted/district/'.$id);
    }

    public function newAppointmentsDistrictAsc(
        string $districtId,
        string $ascId
    ):void
    {
        redirect('/hr/arpa-appointments/submitted/district/'.$districtId.'/asc/'.$ascId);
    }

    public function submittedAppointmentsDistrict(string $id):void
    {
        Auth::requirePermission('arpa.appointment.view');

        if(!$this->workflowQueuePolicy()->canUseWorkflowQueues((string)Auth::user()['id'])){
            $this->workflowQueueForbidden();
            return;
        }

        $this->appointmentDistrictSummary(
            'Submitted',
            'arpa-submitted-appointments-summary',
            'hr/arpa-appointments/submitted',
            $id,
            'Assignments waiting for action, grouped by Agrarian Service Center in the selected District.'
        );
    }

    public function submittedAppointmentsDistrictAsc(
        string $districtId,
        string $ascId
    ):void
    {
        Auth::requirePermission('arpa.appointment.view');

        if(!$this->workflowQueuePolicy()->canUseWorkflowQueues((string)Auth::user()['id'])){
            $this->workflowQueueForbidden();
            return;
        }

        $this->appointmentAscList(
            'Submitted',
            'arpa-submitted-appointments',
            'hr/arpa-appointments/submitted',
            $ascId,
            'Assignments waiting for action at the selected Agrarian Service Center.',
            $districtId
        );
    }

    public function approvalVerificationDistrict(string $id):void
    {
        Auth::requirePermission('arpa.appointment.view');

        if(!$this->workflowQueuePolicy()->canUseWorkflowQueues((string)Auth::user()['id'])){
            $this->workflowQueueForbidden();
            return;
        }

        $this->appointmentDistrictSummary(
            'Review & Approve',
            'arpa-approval-verification-summary',
            'hr/arpa-appointments/approval',
            $id,
            'Completed verification and approval actions grouped by ASC within the selected District.'
        );
    }

    public function approvalVerificationDistrictAsc(
        string $districtId,
        string $ascId
    ):void
    {
        Auth::requirePermission('arpa.appointment.view');

        if(!$this->workflowQueuePolicy()->canUseWorkflowQueues((string)Auth::user()['id'])){
            $this->workflowQueueForbidden();
            return;
        }

        $this->appointmentAscList(
            'Review & Approve',
            'arpa-approval-verification',
            'hr/arpa-appointments/approval',
            $ascId,
            'Completed verification and approval actions for the selected Agrarian Service Center.',
            $districtId
        );
    }

    public function openAppointmentsDistrict(string $id):void
    {
        Auth::requirePermission('arpa.appointment.view');

        $this->appointmentDistrictSummary(
            'Current Assignments',
            'arpa-open-appointments-summary',
            'hr/arpa-appointments/open',
            $id,
            'Current and scheduled assignments grouped by Agrarian Service Center in the selected District.',
            'hr/arpa-appointments/new',
            'New Assignment'
        );
    }

    public function openAppointmentsDistrictAsc(
        string $districtId,
        string $ascId
    ):void
    {
        Auth::requirePermission('arpa.appointment.view');

        $this->appointmentAscList(
            'Current Assignments',
            'arpa-open-appointments',
            'hr/arpa-appointments/open',
            $ascId,
            'Current and scheduled assignments for the selected Agrarian Service Center.',
            $districtId
        );
    }

    public function historyDistrict(string $id):void
    {
        Auth::requirePermission('arpa.appointment.view');

        $this->appointmentDistrictSummary(
            'Assignment History',
            'arpa-historical-appointments-summary',
            'hr/arpa-appointments/history',
            $id,
            'Previous assignments grouped by Agrarian Service Center in the selected District.'
        );
    }

    public function historyDistrictAsc(
        string $districtId,
        string $ascId
    ):void
    {
        Auth::requirePermission('arpa.appointment.view');

        $this->appointmentAscList(
            'Assignment History',
            'arpa-historical-appointments',
            'hr/arpa-appointments/history',
            $ascId,
            'Previous assignments for the selected Agrarian Service Center.',
            $districtId
        );
    }
    public function createDivision(): void
    {
        Auth::requirePermission('arpa.appointment.create');
        $ascContext=$this->activeAscCreationContext();
        $systemContext=$this->isSystemCreationContext();
        if($ascContext===null&&!$systemContext){
            http_response_code(403);
            $this->render('partials/forbidden',['permission'=>'an active Agrarian Service Center working context']);
            return;
        }
        $selectedAsc=$ascContext===null?trim((string)($_GET['asc_location_id']??'')):(string)$ascContext['location_id'];
        $effectiveDate=trim((string)($_GET['effective_from']??date('Y-m-d')));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$effectiveDate))$effectiveDate=date('Y-m-d');
        if($selectedAsc!=='')$this->assertArpaStageScope('ASC',$selectedAsc,$effectiveDate);
        $this->render('arpa_appointments/division_form',$this->divisionFormOptions($selectedAsc,$effectiveDate,$systemContext)+[
            'selectedAsc'=>$selectedAsc,
            'effectiveDate'=>$effectiveDate,
            'ascDerivedFromContext'=>$ascContext!==null,
            'activeAsc'=>$ascContext,
        ]);
    }

    public function storeDivision(): void
    {
        Auth::requirePermission('arpa.appointment.create'); Csrf::validate();
        $this->perform(function(ArpaAppointmentService $service,string $actor):void {
            $data=$_POST;$ascContext=$this->activeAscCreationContext();$submittedAsc=trim((string)($data['asc_location_id']??''));
            if($ascContext!==null){
                $ascId=(string)$ascContext['location_id'];
                if($submittedAsc!==''&&$submittedAsc!==$ascId)throw new DomainException('The submitted Agrarian Service Center does not match your current working context.');
                $data['asc_location_id']=$ascId;
            }else{
                if(!$this->isSystemCreationContext())throw new DomainException('Select an Agrarian Service Center working context before creating an assignment.');
                $ascId=$submittedAsc;
            }
            $this->assertArpaStageScope('ASC',$ascId,(string)($data['effective_from']??''));
            $service->createAndSubmitDivisionAppointmentRequest($data,$actor);
            $this->flash('success','Assignment submitted successfully.');
        },'/hr/arpa-appointments/new','/hr/arpa-appointments/submitted');
    }

    public function subjects(): void
    {
        Auth::requirePermission('arpa.appointment.view');
        $dataTable=DataTableRegistry::viewModel('arpa-subject-assignments',[],$this->filterOptions(),['status'=>'ACTIVE']);
        $this->render('arpa_appointments/list',['title'=>'ARPA Subject Assignments','dataTable'=>$dataTable,'createUrl'=>'hr/arpa-appointments/subjects/create','createPermission'=>'arpa.subject.create']);
    }

    public function divisionDetail(string $id):void
    {
        Auth::requirePermission('arpa.appointment.view');$record=$this->divisionRecord($id);$this->assertLocationScope((string)$record['asc_location_id'],(string)$record['effective_from']);
        $pdo=Database::pdo();$stmt=$pdo->prepare('SELECT c.*,er.name_en end_reason FROM arpa_division_appointment_closure c LEFT JOIN arpa_appointment_end_reason er ON er.id=c.end_reason_id WHERE c.appointment_id=?');$stmt->execute([$id]);$closure=$stmt->fetch()?:null;
        $stmt=$pdo->prepare('SELECT r.*,creator.username creator_name,finalizer.username finalizer_name FROM arpa_division_appointment_request r LEFT JOIN system_user creator ON creator.id=r.created_by LEFT JOIN system_user finalizer ON finalizer.id=r.finalized_by WHERE r.id=?');$stmt->execute([$record['request_id']]);$request=$stmt->fetch()?:[];
        $corrections=$this->dataIssueCorrectionService()->correctionsForAppointment($id);
        $this->render('arpa_appointments/appointment_detail',compact('record','closure','request','corrections'));
    }

    public function createSubjectAssignment(): void
    {
        Auth::requirePermission('arpa.subject.create');
        $data=$this->formOptions();
        $data['subjects']=Database::pdo()->query("SELECT * FROM subject_master WHERE active=1 AND approval_status='APPROVED' AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE()) ORDER BY name_en")->fetchAll();
        $this->render('arpa_appointments/subject_form',$data);
    }

    public function storeSubjectAssignment(): void
    {
        Auth::requirePermission('arpa.subject.create'); Csrf::validate();
        $this->perform(function(ArpaAppointmentService $service,string $actor):void {
            $asc=(string)($_POST['asc_location_id']??'');
            $this->assertArpaStageScope('ASC',$asc,(string)($_POST['effective_from']??''));
            $service->createAndSubmitSubjectAssignmentRequest($_POST,$actor);
            $this->flash('success','Assignment submitted successfully.');
        },'/hr/arpa-appointments/subjects/create','/hr/arpa-appointments/submitted');
    }

    public function pending(): void
    {
        Auth::requirePermission('arpa.appointment.view');redirect('/hr/arpa-appointments/approval');
    }

    public function editRequest(string $entity,string $id):void
    {
        Auth::requirePermission($entity==='subject'?'arpa.subject.create':'arpa.appointment.edit');
        $table=$entity==='subject'?'arpa_subject_assignment_request':'arpa_division_appointment_request';$s=Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=?");$s->execute([$id]);$request=$s->fetch();
        $actor=(string)Auth::user()['id'];$editable=$request&&in_array($request['workflow_status'],['CREATED','RETURNED'],true)&&(
            ($request['workflow_status']==='CREATED'&&(string)$request['created_by']===$actor)
            ||($request['workflow_status']==='RETURNED'&&(new ArpaWorkflowQueuePolicy(Database::pdo()))->canCorrectReturnedRequest($actor,(string)$request['asc_location_id'],(string)($request['requested_effective_from']?:date('Y-m-d'))))
        );
        if(!$editable){http_response_code(403);$this->render('partials/forbidden',['permission'=>'ASC correction ownership and scope']);return;}
        $this->assertArpaStageScope('ASC',(string)$request['asc_location_id'],(string)($request['requested_effective_from']?:date('Y-m-d')));
        $data=$this->formOptions()+['request'=>$request,'entity'=>$entity,'reasons'=>$this->endReasons()];
        $data['subjects']=Database::pdo()->query("SELECT * FROM subject_master WHERE active=1 AND approval_status='APPROVED' ORDER BY name_en")->fetchAll();
        $this->render('arpa_appointments/request_edit',$data);
    }

    public function requestDetail(string $entity,string $id):void
    {
        Auth::requirePermission('arpa.appointment.view');$division=$entity==='division';$table=$division?'arpa_division_appointment_request':'arpa_subject_assignment_request';$history=$division?'arpa_appointment_workflow_action':'arpa_subject_workflow_action';
        $s=Database::pdo()->prepare("SELECT r.*,o.dad_number officer_number,o.name_with_initials officer_name FROM {$table} r JOIN officer o ON o.id=r.officer_id WHERE r.id=?");$s->execute([$id]);$request=$s->fetch();if(!$request){http_response_code(404);$this->flash('danger','Request was not found.');redirect('/hr/arpa-appointments/pending');}
        $location=$this->requestLocation($entity,$id);if($location)$this->assertLocationScope($location,date('Y-m-d'));
        $s=Database::pdo()->prepare("SELECT w.*,COALESCE(NULLIF(u.display_name,''),u.username) performed_by,u.username FROM {$history} w JOIN system_user u ON u.id=w.user_id WHERE w.request_id=? ORDER BY w.id");$s->execute([$id]);$workflowHistory=$s->fetchAll();
        $s=Database::pdo()->prepare('SELECT sr.*,u.username updated_by_name FROM arpa_appointment_stage_review sr JOIN system_user u ON u.id=sr.updated_by WHERE sr.entity_type=? AND sr.request_id=? ORDER BY FIELD(sr.review_stage,\'DISTRICT\',\'NATIONAL\')');$s->execute([strtoupper($entity),$id]);$stageReviews=$s->fetchAll();
        $impact=json_decode((string)($request['impact_snapshot_json']??'[]'),true);if(!is_array($impact))$impact=[];
        $this->render('arpa_appointments/request_detail',compact('entity','request','workflowHistory','stageReviews','impact'));
    }

    public function updateRequest(string $entity,string $id):void
    {
        Auth::requirePermission($entity==='subject'?'arpa.subject.create':'arpa.appointment.edit');Csrf::validate();
        $this->perform(function(ArpaAppointmentService $service,string $actor)use($entity,$id):void{$asc=(string)($_POST['asc_location_id']??'');$date=(string)($_POST['effective_from']??$_POST['new_effective_from']??$_POST['effective_to']??date('Y-m-d'));if($asc===''){$request=$this->workflowRequest($entity,$id);$asc=(string)$request['asc_location_id'];}$this->assertArpaStageScope('ASC',$asc,$date);$service->updateAndResubmitRequest($entity,$id,$_POST,$actor);$this->flash('success','Assignment corrected and resubmitted successfully.');},'/hr/arpa-appointments/requests/'.$entity.'/'.$id.'/edit','/hr/arpa-appointments/submitted');
    }

    public function editStageReview(string $entity,string $id,string $stage):void
    {
        $stage=strtoupper($stage);$permission=$this->stageReviewEditPermission($stage);Auth::requirePermission($permission);
        $request=$this->workflowRequest($entity,$id);$this->assertStageReviewStatus($stage,(string)$request['workflow_status']);
        $this->assertArpaStageScope($stage,(string)$request['asc_location_id'],(string)($request['requested_effective_from']?:date('Y-m-d')));
        $s=Database::pdo()->prepare('SELECT * FROM arpa_appointment_stage_review WHERE entity_type=? AND request_id=? AND review_stage=?');$s->execute([strtoupper($entity),$id,$stage]);$review=$s->fetch()?:null;
        $this->render('arpa_appointments/stage_review_form',compact('entity','request','review','stage'));
    }

    public function updateStageReview(string $entity,string $id,string $stage):void
    {
        $stage=strtoupper($stage);Auth::requirePermission($this->stageReviewEditPermission($stage));Csrf::validate();
        $this->perform(function(ArpaAppointmentService $service,string $actor)use($entity,$id,$stage):void{
            $request=$this->workflowRequest($entity,$id);$this->assertStageReviewStatus($stage,(string)$request['workflow_status']);
            $this->assertArpaStageScope($stage,(string)$request['asc_location_id'],(string)($request['requested_effective_from']?:date('Y-m-d')));
            $service->saveStageReview($entity,$id,$stage,$_POST['review_information']??null,$_POST['remarks']??null,$actor);
            $this->flash('success',ucfirst(strtolower($stage)).' review information saved and audited.');
        },'/hr/arpa-appointments/requests/'.$entity.'/'.$id.'/review/'.strtolower($stage),'/hr/arpa-appointments/requests/'.$entity.'/'.$id);
    }

    public function workflow(string $entity,string $id,string $action): void
    {
        $stage=strtoupper(trim((string)($_GET['stage']??$_POST['stage']??'')));
        $permission=$this->workflowPermission(strtoupper($action),$stage);
        Auth::requirePermission($permission); Csrf::validate();
        $request=$this->workflowRequest($entity,$id);$status=(string)$request['workflow_status'];
        $requiredReviewer=$this->reviewActionPermission(strtoupper($action),$status);
        if($requiredReviewer!==null&&!Auth::can($requiredReviewer)){
            http_response_code(403);$this->render('partials/forbidden',['permission'=>'ARPA '.$stage.' reviewer']);return;
        }
        $this->perform(function(ArpaAppointmentService $service,string $actor) use($entity,$id,$action,$stage):void {
            $request=$this->workflowRequest($entity,$id);$location=(string)($request['asc_location_id']??'');
            if($location) $this->assertArpaStageScope($stage==='CREATOR'?'ASC':$stage,$location,(string)($request['requested_effective_from']?:date('Y-m-d')));
            $new=$service->workflow($entity,$id,strtoupper($action),$stage,$_POST['comments']??null,$actor);
            $this->flash('success','Workflow action completed. New status: '.$new.'.');
        },'/hr/arpa-appointments/submitted',strtoupper($action)==='SUBMIT'?'/hr/arpa-appointments/submitted':'/hr/arpa-appointments/approval');
    }

    public function endDivision(string $id): void
    {
        Auth::requirePermission('arpa.appointment.end');
        $appointment=$this->divisionRecord($id); $this->assertArpaStageScope('ASC',(string)$appointment['asc_location_id'],date('Y-m-d'));
        $this->render('arpa_appointments/end_form',['record'=>$appointment,'entity'=>'division','reasons'=>$this->endReasons(),'dependentCount'=>$this->dependentCount($appointment)]);
    }

    public function storeDivisionEnd(string $id): void
    {
        Auth::requirePermission('arpa.appointment.end'); Csrf::validate();
        $this->perform(function(ArpaAppointmentService $service,string $actor) use($id):void {
            $record=$this->divisionRecord($id);$this->assertArpaStageScope('ASC',(string)$record['asc_location_id'],(string)($_POST['effective_to']??''));
            $service->createAndSubmitEndRequest($id,(string)($_POST['effective_to']??''),(string)($_POST['end_reason_id']??''),$_POST['remarks']??null,$actor);
            $this->flash('success','Assignment ending submitted. Dependent appointments will be closed as separate historical events after final approval.');
        },'/hr/arpa-appointments/divisions/'.$id.'/end','/hr/arpa-appointments/submitted');
    }

    public function transfer(string $id): void
    {
        Auth::requirePermission('arpa.appointment.transfer');
        $record=$this->divisionRecord($id);$this->assertArpaStageScope('ASC',(string)$record['asc_location_id'],date('Y-m-d'));
        if($record['appointment_type']!=='PERMANENT') throw new DomainException('Only a Permanent appointment can be transferred.');
        $data=$this->formOptions()+['record'=>$record,'reasons'=>$this->endReasons(),'dependentCount'=>$this->dependentCount($record)];
        $this->render('arpa_appointments/transfer_form',$data);
    }

    public function storeTransfer(string $id): void
    {
        Auth::requirePermission('arpa.appointment.transfer'); Csrf::validate();
        $this->perform(function(ArpaAppointmentService $service,string $actor) use($id):void {
            $this->assertArpaStageScope('ASC',(string)($_POST['asc_location_id']??''),(string)($_POST['new_effective_from']??''));
            $service->createAndSubmitTransferRequest($id,(string)($_POST['asc_location_id']??''),(string)($_POST['arpa_division_location_id']??''),(string)($_POST['old_effective_to']??''),(string)($_POST['new_effective_from']??''),(string)($_POST['end_reason_id']??''),$_POST['remarks']??null,$actor);
            $this->flash('success','Transfer submitted successfully.');
        },'/hr/arpa-appointments/divisions/'.$id.'/transfer','/hr/arpa-appointments/submitted');
    }

    public function endSubject(string $id): void
    {
        Auth::requirePermission('arpa.subject.end');
        $record=$this->subjectRecord($id);$this->assertArpaStageScope('ASC',(string)$record['asc_location_id'],date('Y-m-d'));
        $this->render('arpa_appointments/end_form',['record'=>$record,'entity'=>'subject','reasons'=>$this->endReasons(),'dependentCount'=>0]);
    }

    public function storeSubjectEnd(string $id): void
    {
        Auth::requirePermission('arpa.subject.end'); Csrf::validate();
        $this->perform(function(ArpaAppointmentService $service,string $actor) use($id):void {
            $record=$this->subjectRecord($id);$this->assertArpaStageScope('ASC',(string)$record['asc_location_id'],(string)($_POST['effective_to']??''));
            $service->createAndSubmitSubjectEndRequest($id,(string)($_POST['effective_to']??''),(string)($_POST['end_reason_id']??''),$_POST['remarks']??null,$actor);
            $this->flash('success','Subject assignment ending submitted successfully.');
        },'/hr/arpa-appointments/subjects/'.$id.'/end','/hr/arpa-appointments/submitted');
    }

    public function officerProfile(string $id): void
    {
        Auth::requirePermission('arpa.appointment.view');$this->assertOfficerScope($id);$pdo=Database::pdo();
        $s=$pdo->prepare("SELECT o.*,d.name_en AS designation_name,c.name_en AS class_name FROM officer o JOIN designation d ON d.id=o.primary_designation_id AND d.system_key='ARPA_OFFICER' LEFT JOIN officer_class c ON c.id=o.class_id WHERE o.id=?");$s->execute([$id]);$officer=$s->fetch();
        if(!$officer){http_response_code(404);$this->flash('danger','ARPA officer was not found.');redirect('/hr/arpa-appointments');}
        $userId=(string)Auth::user()['id'];$scoped=ScopeService::requiresGeographicRestriction($userId);$with=$scoped?ScopeService::visibleLocationsCte($userId):'';$join=$scoped?' JOIN visible_locations vl ON vl.id=a.asc_location_id':'';$params=$scoped?array_merge(ScopeService::visibleLocationParams($userId),[$id]):[$id];
        $s=$pdo->prepare($with." SELECT a.*,c.effective_to,'ACTIVE' current_status FROM arpa_division_appointment a{$join} LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.officer_id=? AND a.effective_from<=CURRENT_DATE() AND (c.effective_to IS NULL OR c.effective_to>=CURRENT_DATE()) ORDER BY a.effective_from DESC");$s->execute($params);$appointments=$s->fetchAll();
        $s=$pdo->prepare($with." SELECT a.*,c.effective_to,'ACTIVE' current_status FROM arpa_subject_assignment a{$join} LEFT JOIN arpa_subject_assignment_closure c ON c.assignment_id=a.id WHERE a.officer_id=? AND a.effective_from<=CURRENT_DATE() AND (c.effective_to IS NULL OR c.effective_to>=CURRENT_DATE()) ORDER BY a.effective_from DESC");$s->execute($params);$subjects=$s->fetchAll();
        $divisionHistoryTable=DataTableRegistry::viewModel('arpa-officer-division-history',['officer_id'=>$id]);$subjectHistoryTable=DataTableRegistry::viewModel('arpa-officer-subject-history',['officer_id'=>$id]);$permanencyHistoryTable=DataTableRegistry::viewModel('arpa-service-permanency-history',['officer_id'=>$id]);$workflowHistoryTable=DataTableRegistry::viewModel('arpa-officer-workflow-history',['officer_id'=>$id]);
        $this->render('arpa_appointments/officer_profile',compact('officer','appointments','subjects','divisionHistoryTable','subjectHistoryTable','permanencyHistoryTable','workflowHistoryTable','scoped'));
    }

    public function setPermanency(string $id): void
    {
        Auth::requirePermission('arpa.appointment.manage-service'); Csrf::validate();$this->assertOfficerScope($id);
        $this->perform(function(ArpaAppointmentService $service,string $actor) use($id):void {
            $service->setServicePermanency($id,(string)($_POST['service_permanency']??''),(string)($_POST['effective_from']??''),$_POST['reason']??null,$actor);
            $this->flash('success','Service permanency updated and audited.');
        },'/hr/arpa-appointments/officers/'.$id,'/hr/arpa-appointments/officers/'.$id);
    }

    private function formOptions(): array
    {
        $pdo=Database::pdo();
        return ['officers'=>$this->appointmentCandidateOptions(),'ascs'=>$this->locations('ASC'),'arpaDivisions'=>$this->locations('ARPA_DIVISION')];
    }

    private function divisionFormOptions(string $ascId,string $effectiveDate,bool $includeAscOptions=false):array
    {
        $read=new ArpaAppointmentReadService(Database::pdo());$userId=(string)Auth::user()['id'];
        return ['officers'=>$ascId===''?[]:(new ArpaAppointmentCandidateService(Database::pdo()))->optionsForAsc($userId,$ascId,$effectiveDate),'ascs'=>$includeAscOptions?$this->locations('ASC'):[],'arpaDivisions'=>$ascId===''?[]:$read->vacantDivisionsForAsc($userId,$ascId,$effectiveDate)];
    }

    private function activeAscCreationContext():?array
    {
        $context=Auth::activeContext();
        return $context!==null
            &&(string)$context['role_level']==='ASC'
            &&(string)($context['scope_type']??'')==='ASC'
            &&(string)($context['scope_mode']??'')==='EXACT'
            &&trim((string)($context['location_id']??''))!==''
            ?$context
            :null;
    }

    private function isSystemCreationContext():bool
    {
        return (string)(Auth::activeContext()['role_level']??'')==='SYSTEM';
    }

    private function appointmentList(
        string $title,
        string $key,
        ?string $createUrl,
        ?string $createLabel,
        string $description
    ):void
    {
        $this->render('arpa_appointments/list',[
            'title'=>$title,
            'description'=>$description,
            'dataTable'=>DataTableRegistry::viewModel($key,[],$this->filterOptions()),
            'createUrl'=>$createUrl,
            'createPermission'=>$createUrl?'arpa.appointment.create':null,
            'createLabel'=>$createLabel,
        ]);
    }

    private function appointmentHierarchySummary(
        string $title,
        string $summaryKey,
        ?string $createUrl,
        ?string $createLabel,
        string $description
    ):bool
    {
        $userId=(string)Auth::user()['id'];
        $profile=ScopeService::scopeProfile($userId);
        $level=(string)($profile['level']??'');

        if($level==='NATIONAL'){
            $districtSummaryKey=str_replace(
                '-summary',
                '-district-summary',
                $summaryKey
            );

            $this->render('arpa_appointments/district_summary',[
                'title'=>$title,
                'description'=>$description,
                'dataTable'=>DataTableRegistry::viewModel($districtSummaryKey),
                'createUrl'=>$createUrl,
                'createPermission'=>$createUrl?'arpa.appointment.create':null,
                'createLabel'=>$createLabel,
            ]);

            return true;
        }

        if($level==='DISTRICT'){
            $this->render('arpa_appointments/asc_summary',[
                'title'=>$title,
                'description'=>$description,
                'dataTable'=>DataTableRegistry::viewModel($summaryKey),
                'createUrl'=>$createUrl,
                'createPermission'=>$createUrl?'arpa.appointment.create':null,
                'createLabel'=>$createLabel,
            ]);

            return true;
        }

        return false;
    }

    private function appointmentDistrictSummary(
        string $title,
        string $summaryKey,
        string $summaryUrl,
        string $districtId,
        string $description,
        ?string $createUrl=null,
        ?string $createLabel=null
    ):void
    {
        $userId=(string)Auth::user()['id'];

        if((ScopeService::scopeProfile($userId)['level']??'')!=='NATIONAL'){
            http_response_code(403);
            $this->render('partials/forbidden',[
                'permission'=>'National geographic scope'
            ]);
            return;
        }

        $district=$this->scopedLocationById(
            $userId,
            'DISTRICT',
            $districtId
        );

        if($district===null){
            http_response_code(403);
            $this->render('partials/forbidden',[
                'permission'=>'access to the selected District'
            ]);
            return;
        }

        $this->render('arpa_appointments/asc_summary',[
            'title'=>$title,
            'description'=>$description,
            'district'=>$district,
            'summaryUrl'=>$summaryUrl,
            'dataTable'=>DataTableRegistry::viewModel(
                $summaryKey,
                ['district_id'=>$districtId]
            ),
            'createUrl'=>$createUrl,
            'createPermission'=>$createUrl?'arpa.appointment.create':null,
            'createLabel'=>$createLabel,
        ]);
    }

    private function appointmentAscList(
        string $title,
        string $key,
        string $summaryUrl,
        string $ascId,
        string $description,
        ?string $districtId=null
    ):void
    {
        $userId=(string)Auth::user()['id'];
        $profile=ScopeService::scopeProfile($userId);
        $level=(string)($profile['level']??'');

        $district=null;

        if($districtId===null){
            if($level!=='DISTRICT'){
                http_response_code(403);
                $this->render('partials/forbidden',[
                    'permission'=>'District geographic scope'
                ]);
                return;
            }
        }else{
            if($level!=='NATIONAL'){
                http_response_code(403);
                $this->render('partials/forbidden',[
                    'permission'=>'National geographic scope'
                ]);
                return;
            }

            $district=$this->scopedLocationById(
                $userId,
                'DISTRICT',
                $districtId
            );

            if(
                $district===null
                || !$this->districtContainsAsc($districtId,$ascId)
            ){
                http_response_code(403);
                $this->render('partials/forbidden',[
                    'permission'=>'access to the selected ASC within the selected District'
                ]);
                return;
            }

            $summaryUrl.='/district/'.$districtId;
        }

        $asc=$this->scopedLocationById(
            $userId,
            'ASC',
            $ascId
        );

        if($asc===null){
            http_response_code(403);
            $this->render('partials/forbidden',[
                'permission'=>'access to the selected Agrarian Service Center'
            ]);
            return;
        }

        $dataTable=DataTableRegistry::viewModel(
            $key,
            ['asc_id'=>$ascId],
            $this->filterOptions()
        );

        $this->render('arpa_appointments/asc_records',[
            'title'=>$title,
            'description'=>$description,
            'district'=>$district,
            'asc'=>$asc,
            'summaryUrl'=>$summaryUrl,
            'dataTable'=>$dataTable,
        ]);
    }

    private function scopedLocationById(
        string $userId,
        string $type,
        string $id
    ):?array
    {
        foreach(ScopeService::scopedLocations($userId,$type) as $location){
            if((string)$location['id']===$id)return $location;
        }

        return null;
    }

    private function districtContainsAsc(
        string $districtId,
        string $ascId
    ):bool
    {
        $stmt=Database::pdo()->prepare(
            "SELECT COUNT(*)
             FROM location_relationship lr
             JOIN location asc_l
               ON asc_l.id=lr.child_location_id
             JOIN location_type asc_t
               ON asc_t.id=asc_l.location_type_id
              AND asc_t.system_key='ASC'
             WHERE lr.parent_location_id=?
               AND lr.child_location_id=?
               AND lr.active=1
               AND lr.approval_status='APPROVED'
               AND lr.effective_from<=CURRENT_DATE()
               AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())
               AND asc_l.approval_status='APPROVED'
               AND asc_l.operational_status='ACTIVE'"
        );

        $stmt->execute([$districtId,$ascId]);

        return (int)$stmt->fetchColumn()>0;
    }
    private function workflowQueuePolicy():ArpaWorkflowQueuePolicy{return new ArpaWorkflowQueuePolicy(Database::pdo());}
    private function dataIssueCorrectionService():ArpaAppointmentDataIssueCorrectionService{return new ArpaAppointmentDataIssueCorrectionService(Database::pdo());}
    private function workflowQueueForbidden():void{http_response_code(403);$this->render('partials/forbidden',['permission'=>'an active ARPA workflow role and matching geographic scope']);}

    private function filterOptions(): array
    {
        $options=[];foreach(['PROVINCE'=>'province','DISTRICT'=>'district','ASC'=>'asc','ARPA_DIVISION'=>'arpa_division'] as $type=>$key)$options[$key]=array_column($this->locations($type),'name_en','id');
        $rows=$this->appointmentCandidateOptions();foreach($rows as &$row)$row['name']=$row['dad_number'].' - '.($row['name_with_initials']??'');unset($row);$options['officer']=array_column($rows,'name','id');return $options;
    }

    private function locations(string $type): array
    {
        return ScopeService::scopedLocations((string)Auth::user()['id'],$type);
    }
    private function appointmentCandidateOptions():array{return (new ArpaAppointmentCandidateService(Database::pdo()))->options();}
    private function endReasons(): array{return Database::pdo()->query('SELECT id,name_en,service_terminating FROM arpa_appointment_end_reason WHERE active=1 ORDER BY display_order,name_en')->fetchAll();}
    private function divisionRecord(string $id): array{$s=Database::pdo()->prepare('SELECT a.*,o.dad_number officer_number,o.name_with_initials officer_name FROM arpa_division_appointment a JOIN officer o ON o.id=a.officer_id WHERE a.id=?');$s->execute([$id]);$r=$s->fetch();if(!$r)throw new DomainException('Appointment was not found.');return $r;}
    private function subjectRecord(string $id): array{$s=Database::pdo()->prepare('SELECT a.*,o.dad_number officer_number,o.name_with_initials officer_name FROM arpa_subject_assignment a JOIN officer o ON o.id=a.officer_id WHERE a.id=?');$s->execute([$id]);$r=$s->fetch();if(!$r)throw new DomainException('Subject assignment was not found.');return $r;}
    private function dependentCount(array $record):int{if(($record['appointment_type']??'')!=='PERMANENT')return 0;$s=Database::pdo()->prepare("SELECT COUNT(*) FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.officer_id=? AND a.appointment_type<>'PERMANENT' AND c.id IS NULL");$s->execute([$record['officer_id']]);return (int)$s->fetchColumn();}
    private function requestLocation(string $entity,string $id):?string{if($entity==='division'){$s=Database::pdo()->prepare('SELECT asc_location_id FROM arpa_division_appointment_request WHERE id=?');}else{$s=Database::pdo()->prepare('SELECT asc_location_id FROM arpa_subject_assignment_request WHERE id=?');}$s->execute([$id]);return $s->fetchColumn()?:null;}
    private function workflowPermission(string $action,string $stage):string{return match([$action,$stage]){['SUBMIT','CREATOR']=>'arpa.appointment.submit',['VERIFY','ASC']=>'arpa.appointment.asc-verify',['APPROVE','ASC']=>'arpa.appointment.asc-approve',['VERIFY','DISTRICT']=>'arpa.appointment.district-verify',['APPROVE','DISTRICT']=>'arpa.appointment.district-approve',['VERIFY','NATIONAL']=>'arpa.appointment.national-verify',['APPROVE','NATIONAL']=>'arpa.appointment.national-approve',['RETURN_FOR_CORRECTION','ASC'],['RETURN_FOR_CORRECTION','DISTRICT'],['RETURN_FOR_CORRECTION','NATIONAL']=>'arpa.appointment.return',['REJECT','ASC'],['REJECT','DISTRICT'],['REJECT','NATIONAL']=>'arpa.appointment.reject',default=>throw new DomainException('Invalid workflow action or stage.')};}
    private function stageReviewEditPermission(string $stage):string{return match($stage){'DISTRICT'=>'arpa.appointment.district-review-edit','NATIONAL'=>'arpa.appointment.national-review-edit',default=>throw new DomainException('Only District and National review information is editable here.')};}
    private function assertStageReviewStatus(string $stage,string $status):void{$required=$stage==='DISTRICT'?'ASC_APPROVED':'DISTRICT_APPROVED';if($status!==$required)throw new DomainException("{$stage} review information cannot be edited from {$status}.");}
    private function reviewActionPermission(string $action,string $status):?string{return match([$action,$status]){['RETURN_FOR_CORRECTION','SUBMITTED'],['REJECT','SUBMITTED']=>'arpa.appointment.asc-verify',['RETURN_FOR_CORRECTION','ASC_VERIFIED'],['REJECT','ASC_VERIFIED']=>'arpa.appointment.asc-approve',['RETURN_FOR_CORRECTION','ASC_APPROVED'],['REJECT','ASC_APPROVED']=>'arpa.appointment.district-verify',['RETURN_FOR_CORRECTION','DISTRICT_VERIFIED'],['REJECT','DISTRICT_VERIFIED']=>'arpa.appointment.district-approve',['RETURN_FOR_CORRECTION','DISTRICT_APPROVED'],['REJECT','DISTRICT_APPROVED']=>'arpa.appointment.national-verify',['RETURN_FOR_CORRECTION','NATIONAL_VERIFIED'],['REJECT','NATIONAL_VERIFIED']=>'arpa.appointment.national-approve',default=>in_array($action,['RETURN_FOR_CORRECTION','REJECT'],true)?'__invalid_stage__':null};}
    private function workflowRequest(string $entity,string $id):array{if(!in_array($entity,['division','subject'],true))throw new DomainException('Unsupported workflow entity.');$table=$entity==='division'?'arpa_division_appointment_request':'arpa_subject_assignment_request';$s=Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=?");$s->execute([$id]);$request=$s->fetch();if(!$request)throw new DomainException('Workflow request was not found.');return $request;}
    private function assertOfficerScope(string $officerId):void{$u=Auth::user();if(!$u)throw new DomainException('Authentication required.');if(!ScopeService::canAccessOfficer((string)$u['id'],$officerId))throw new DomainException('This ARPA officer is outside your current authorized geographic scope.');}
    private function assertLocationScope(string $locationId,string $date):void{$u=Auth::user();if(!$u)throw new DomainException('Authentication required.');if(!ScopeService::requiresGeographicRestriction((string)$u['id']))return;$scopeDate=preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)?$date:date('Y-m-d');if(!ScopeService::canAccessLocation((string)$u['id'],$locationId,$scopeDate))throw new DomainException('The selected location is outside your authorized geographic scope.');}
    private function assertArpaStageScope(string $stage,string $ascLocationId,string $date):void{$u=Auth::user();if(!$u)throw new DomainException('Authentication required.');$scopeDate=preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)?$date:date('Y-m-d');if(!ScopeService::canAccessArpaStage((string)$u['id'],$stage,$ascLocationId,$scopeDate))throw new DomainException("The request is outside your authorized {$stage} scope.");}
    private function perform(callable $callback,string $failure,string $success):never{try{$callback(new ArpaAppointmentService(Database::pdo()),(string)Auth::user()['id']);redirect($success);}catch(Throwable $e){error_log('ARPA appointment operation failed: '.$e->getMessage());$this->flash('danger',$e instanceof DomainException?$e->getMessage():'Unable to complete the operation.');redirect($failure);}}
}
