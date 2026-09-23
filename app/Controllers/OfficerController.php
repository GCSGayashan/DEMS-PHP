<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Auth,Controller,Database,Csrf,NumberService,Audit,DataTableRegistry,NicNormalizer,ScopeService};
use App\Services\{ArpaAdministrativePolicy,OfficerAdminDirectEditPolicy,OfficerAdminDirectEditService,OfficerEditRequestService,OfficerOfficeAssignmentService,OfficerPersonnelValidator,OfficerProfileService,OfficerWorkflowService};

final class OfficerController extends Controller
{
    public function index(): void
    {
        Auth::requirePermission('officer.view');
        $pdo=Database::pdo();
        $options=[];
        $queries=[
            'designation' => "SELECT id,name_en FROM designation ORDER BY name_en",
            'class' => "SELECT id,name_en FROM officer_class ORDER BY name_en",
            'officer_status' => "SELECT id,name_en FROM officer_status ORDER BY name_en",
        ];
        if(!ScopeService::requiresGeographicRestriction((string)Auth::user()['id']))$queries['office']="SELECT id,name_en FROM office ORDER BY name_en";
        foreach ($queries as $key=>$sql) {
            $rows=$pdo->query($sql)->fetchAll(); $options[$key]=array_column($rows,'name_en','id');
        }
        $scoped=ScopeService::requiresGeographicRestriction((string)Auth::user()['id']);$dataTable=DataTableRegistry::viewModel('officers',[],$options);
        $workflowDataTable=(Auth::can('officer.create')||Auth::can('officer.approve'))?DataTableRegistry::viewModel('officer-workflow'):null;
        $this->render('officers/index',compact('dataTable','workflowDataTable','scoped'));
    }

    public function show(string $id):void
    {
        Auth::requirePermission('officer.view');$userId=(string)Auth::user()['id'];$workflowService=new OfficerWorkflowService(Database::pdo());$demsAdmin=ArpaAdministrativePolicy::isCanonicalDemsAdmin();
        if(!$demsAdmin&&!$workflowService->canAccess($id,$userId)){http_response_code(404);$this->render('partials/not-found');return;}
        $restricted=!$demsAdmin&&ScopeService::requiresGeographicRestriction($userId);$offices=$demsAdmin?[]:ScopeService::scopedOffices($userId);$ascIds=$restricted?array_column(ScopeService::scopedLocations($userId,'ASC'),'id'):null;
        $profile=(new OfficerProfileService(Database::pdo()))->profile($id,$restricted?array_column($offices,'id'):[],$ascIds,$demsAdmin);$officerWorkflow=$workflowService->actions($id,$userId);$initialOfficeAssignment=(new OfficerOfficeAssignmentService(Database::pdo()))->initialForOfficer($id);$initialOfficeReconciliation=$initialOfficeAssignment===null?$workflowService->initialOfficeReconciliationCandidate($id,$userId):null;$canAdminDateCorrect=ArpaAdministrativePolicy::canCorrectDates();$this->render('officers/show',$profile+compact('offices','officerWorkflow','initialOfficeAssignment','initialOfficeReconciliation','demsAdmin','canAdminDateCorrect'));
    }

    public function search():void
    {
        Auth::requirePermission('officer.view');$query='';$message=null;$results=[];$this->render('officers/search',compact('query','message','results'));
    }
    public function searchSubmit():void
    {
        Auth::requirePermission('officer.view');Csrf::validate();$query=trim((string)($_POST['nic']??''));$message=null;$results=[];$normalized=NicNormalizer::normalize($query);
        if(!NicNormalizer::isValid($normalized))$message='Enter a valid Sri Lankan NIC.';else{
            $userId=(string)Auth::user()['id'];$access=ScopeService::currentOfficerAccess($userId,'o.id');$params=$access['params'];$where=$access['where'];$where[]="o.approval_status='APPROVED'";$where[]='(o.nic_normalized=? OR o.nic_match_key=?)';$params[]=$normalized;$params[]=NicNormalizer::matchKey($normalized);
            $sql=$access['with']." SELECT o.id,o.dad_number,o.name_with_initials,o.nic,d.name_en designation_name,c.name_en class_name,ofc.name_en primary_office_name FROM officer o LEFT JOIN designation d ON d.id=o.primary_designation_id LEFT JOIN officer_class c ON c.id=o.class_id LEFT JOIN office ofc ON ofc.id=o.primary_office_id WHERE ".implode(' AND ',$where).' ORDER BY o.dad_number LIMIT 25';
            $s=Database::pdo()->prepare($sql);$s->execute($params);$results=$s->fetchAll();if(count($results)===1)redirect('/hr/officers/'.$results[0]['id']);$message=$results===[]?'Officer not found.':'More than one Officer matches this NIC. Select the correct scoped Officer below.';
        }
        $this->render('officers/search',compact('query','message','results'));
    }

    public function options():void
    {
        Auth::requirePermission('officer.view');$userId=(string)Auth::user()['id'];$access=ScopeService::currentOfficerAccess($userId,'o.id');$term=trim((string)($_GET['q']??''));
        $where=$access['where'];$where[]="o.approval_status='APPROVED'";$params=$access['params'];if($term!==''){$where[]="CONCAT_WS(' ',o.dad_number,o.name_with_initials,o.nic) LIKE ?";$params[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$term).'%';}
        $sql=$access['with']." SELECT o.id,o.dad_number,o.name_with_initials FROM officer o WHERE ".($where?implode(' AND ',$where):'1=1')." ORDER BY o.name_with_initials LIMIT 25";$s=Database::pdo()->prepare($sql);$s->execute($params);
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['results'=>$s->fetchAll()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;
    }

    public function pendingOfficeAssignments():void
    {
        Auth::requirePermission('officer.office-assignment.approve');
        $dataTable=DataTableRegistry::viewModel('pending-officer-office-assignments');
        $this->render('officers/office_assignments/pending',compact('dataTable'));
    }

    public function reviewOfficeAssignment(string $assignmentId):void
    {
        Auth::requirePermission('officer.office-assignment.approve');
        try{$assignment=(new OfficerOfficeAssignmentService(Database::pdo()))->reviewForApproval($assignmentId,(string)Auth::user()['id']);}
        catch(\DomainException){http_response_code(403);$this->render('partials/forbidden',['permission'=>'approval of an Office assignment within the target Office scope']);return;}
        $this->render('officers/office_assignments/review',compact('assignment'));
    }

    public function approvePendingOfficeAssignment(string $assignmentId):void
    {
        Auth::requirePermission('officer.office-assignment.approve');Csrf::validate();
        try{(new OfficerOfficeAssignmentService(Database::pdo()))->approve($assignmentId,(string)Auth::user()['id']);$this->flash('success','Office assignment approved.');}
        catch(\DomainException $e){$this->flash('danger',$e->getMessage());}
        redirect('/hr/officers/office-assignments/pending');
    }

    public function edit(string $id): void
    {
        if(!Auth::can('officer.edit')&&!Auth::can('officer.edit-request')){http_response_code(403);$this->render('partials/forbidden',['permission'=>'officer profile editing']);return;}

        $userId=(string)Auth::user()['id'];

        $workflowService=new OfficerWorkflowService(Database::pdo());
        if(!$workflowService->canAccess($id,$userId)){
            http_response_code(404);
            $this->render('partials/not-found');
            return;
        }
        $pdo=Database::pdo();

        $stmt=$pdo->prepare('SELECT * FROM officer WHERE id=?');
        $stmt->execute([$id]);
        $officer=$stmt->fetch();

        if(!$officer){
            http_response_code(404);
            $this->render('partials/not-found');
            return;
        }

        $directAdminEdit=OfficerAdminDirectEditPolicy::supportsStatus((string)$officer['approval_status'])&&OfficerAdminDirectEditPolicy::allowed();
        $editRequestService=new OfficerEditRequestService($pdo);$returnedEditRequest=$editRequestService->returnedForMaker($id,$userId);
        $editRequestMode=!$directAdminEdit&&(string)$officer['approval_status']==='APPROVED'&&($returnedEditRequest!==null||$editRequestService->canInitiate($id,$userId));
        if(!$directAdminEdit&&!$editRequestMode){try{$workflowService->assertEditable($id,$userId);}catch(\DomainException){http_response_code(403);$this->render('partials/forbidden',['permission'=>'Officer editing within the active workflow context']);return;}}
        if($returnedEditRequest!==null&&is_array($returnedEditRequest['proposed']??null))$officer=array_replace($officer,$returnedEditRequest['proposed']);

        foreach(['primary_mobile','alternative_mobile'] as $mobileField){
            $normalized=OfficerPersonnelValidator::normalizeSriLankanMobile(
                (string)($officer[$mobileField]??'')
            );

            if($normalized!==null){
                $officer[$mobileField]=$normalized;
            }
        }
        $titles=$pdo->query(
            "SELECT * FROM hr_title WHERE active=1 ORDER BY display_order"
        )->fetchAll();

        $appointmentNatures=$pdo->query(
            "SELECT * FROM appointment_nature WHERE active=1 ORDER BY display_order"
        )->fetchAll();

        $designations=$pdo->query(
            "SELECT * FROM designation WHERE active=1 ORDER BY designation_level,name_en"
        )->fetchAll();

        $classes=$pdo->query(
            "SELECT * FROM officer_class WHERE active=1 ORDER BY display_order"
        )->fetchAll();

        $statuses=$pdo->query(
            "SELECT * FROM officer_status WHERE active=1 ORDER BY display_order"
        )->fetchAll();

        $civilStatuses=$pdo->query(
            "SELECT * FROM civil_status WHERE active=1 ORDER BY display_order"
        )->fetchAll();

        $availableOffices=ScopeService::scopedOffices($userId);
        $initialOfficeAssignment=(new OfficerOfficeAssignmentService($pdo))
            ->initialForOfficer($id);
        $this->render(
            'officers/edit',
            compact(
                'officer',
                'titles',
                'appointmentNatures',
                'designations',
                'classes',
                'statuses',
                'civilStatuses',
                'availableOffices',
                'initialOfficeAssignment',
                'directAdminEdit',
                'editRequestMode',
                'returnedEditRequest'
            )
        );
    }

    public function update(string $id): void
    {
        if(!Auth::can('officer.edit')&&!Auth::can('officer.edit-request')){http_response_code(403);$this->render('partials/forbidden',['permission'=>'officer profile editing']);return;}
        Csrf::validate();

        $userId=(string)Auth::user()['id'];

        $workflowService=new OfficerWorkflowService(Database::pdo());
        if(!$workflowService->canAccess($id,$userId)){
            http_response_code(404);
            $this->render('partials/not-found');
            return;
        }

        $pdo=Database::pdo();

        $stmt=$pdo->prepare('SELECT * FROM officer WHERE id=?');
        $stmt->execute([$id]);
        $current=$stmt->fetch();

        if(!$current){
            http_response_code(404);
            $this->render('partials/not-found');
            return;
        }

        $directAdminEdit=OfficerAdminDirectEditPolicy::supportsStatus((string)$current['approval_status'])&&OfficerAdminDirectEditPolicy::allowed();
        $editRequestService=new OfficerEditRequestService($pdo);$returnedRequestId=trim((string)($_POST['officer_edit_request_id']??''));
        $editRequestMode=!$directAdminEdit&&(string)$current['approval_status']==='APPROVED'&&$editRequestService->canInitiate($id,$userId);
        if(!$directAdminEdit&&!$editRequestMode){try{$workflowService->assertEditable($id,$userId);}catch(\DomainException $e){$this->flash('danger',$e->getMessage());redirect('/hr/officers/'.$id);}}

        $fail=function(string $message) use($id): void {
            $this->flash('danger',$message);
            redirect('/hr/officers/'.$id.'/edit');
        };

        $version=trim((string)($_POST['version']??''));
        if($version===''||!ctype_digit($version))$fail('The Officer edit form is stale or invalid. Reload it and try again.');
        $expectedVersion=(int)$version;

        $nic=NicNormalizer::normalize(
            (string)($_POST['nic']??'')
        );

        $name=trim(
            (string)($_POST['name_with_initials']??'')
        );

        if($nic===null || $name===''){
            $fail('NIC and Name with Initials are required.');
        }

        if(!NicNormalizer::isValid($nic)){
            $fail('NIC format is invalid.');
        }

        $required=[
            'Title'=>(string)($_POST['title_id']??''),
            'Gender'=>(string)($_POST['gender']??''),
            'Full Name (English)'=>(string)($_POST['full_name_en']??''),
            'Date of Birth'=>(string)($_POST['date_of_birth']??''),
            'Permanent Address'=>(string)($_POST['permanent_address']??''),
            'Initial Appointment Date'=>(string)($_POST['initial_appointment_date']??''),
            'Appointment Nature'=>(string)($_POST['appointment_nature_id']??''),
            'Primary Designation'=>(string)($_POST['primary_designation_id']??''),
            'Officer Status'=>(string)($_POST['officer_status_id']??''),
            'Effective From'=>(string)($_POST['effective_from']??''),
        ];

        foreach($required as $label=>$value){
            if(trim($value)===''){
                $fail($label.' is required.');
            }
        }

        $nicMatchKey=NicNormalizer::matchKey($nic);

        $chk=$pdo->prepare(
            'SELECT COUNT(*)
             FROM officer
             WHERE id<>?
               AND (
                    nic_normalized=?
                    OR (
                        nic_match_key IS NOT NULL
                        AND nic_match_key=?
                    )
               )'
        );

        $chk->execute([$id,$nic,$nicMatchKey]);

        if((int)$chk->fetchColumn()>0){
            $fail('NIC already exists.');
        }

        $employee=trim(
            (string)($_POST['employee_number']??'')
        ) ?: null;

        if($employee){
            $chk=$pdo->prepare(
                'SELECT COUNT(*)
                 FROM officer
                 WHERE id<>?
                   AND employee_number=?'
            );

            $chk->execute([$id,$employee]);

            if((int)$chk->fetchColumn()>0){
                $fail('Employee number already exists.');
            }
        }

        try{
            $contactNumbers=OfficerPersonnelValidator::contactNumbers(
                $_POST['primary_mobile']??null,
                $_POST['alternative_mobile']??null
            );
        }catch(\DomainException $e){
            $fail($e->getMessage());
        }
        $primaryMobile=$contactNumbers['primary_mobile'];
        $alternativeMobile=$contactNumbers['alternative_mobile'];

        $personalEmail=strtolower(
            trim((string)($_POST['personal_email']??''))
        ) ?: null;

        $officialEmail=strtolower(
            trim((string)($_POST['official_email']??''))
        ) ?: null;

        foreach(array_filter([$personalEmail,$officialEmail]) as $mail){
            if(!filter_var($mail,FILTER_VALIDATE_EMAIL)){
                $fail('Email address format is invalid.');
            }

            $chk=$pdo->prepare(
                'SELECT COUNT(*)
                 FROM officer
                 WHERE id<>?
                   AND (
                        LOWER(personal_email)=?
                        OR LOWER(official_email)=?
                   )'
            );

            $chk->execute([$id,$mail,$mail]);

            if((int)$chk->fetchColumn()>0){
                $fail(
                    'Email address already belongs to another officer.'
                );
            }
        }

        if(
            $personalEmail
            &&
            $officialEmail
            &&
            $personalEmail===$officialEmail
        ){
            $fail(
                'Personal and official email must be different.'
            );
        }

        $natureId=(string)($_POST['appointment_nature_id']??'');
        $classId=($_POST['class_id']??'') ?: null;
        try{
            $serviceFields=OfficerPersonnelValidator::servicePermanency(
                $_POST['arpa_service_permanency']??null,
                $_POST['service_permanented_date']??null
            );
        }catch(\DomainException $e){
            $fail($e->getMessage());
        }
        $servicePermanency=$serviceFields['arpa_service_permanency'];
        $permanentedDate=$serviceFields['service_permanented_date'];

        $nature=$pdo->prepare(
            'SELECT class_required
             FROM appointment_nature
             WHERE id=?
               AND active=1'
        );

        $nature->execute([$natureId]);

        $natureRow=$nature->fetch();

        if(!$natureRow){
            $fail('Appointment Nature is invalid.');
        }

        if((bool)$natureRow['class_required'] && !$classId){
            $fail(
                'Class is required for the selected Appointment Nature.'
            );
        }

        if($classId){
            $cnt=$pdo->prepare(
                'SELECT COUNT(*)
                 FROM designation_allowed_class
                 WHERE designation_id=?
                   AND active=1'
            );

            $cnt->execute([
                $_POST['primary_designation_id']
            ]);

            if((int)$cnt->fetchColumn()>0){
                $ok=$pdo->prepare(
                    "SELECT COUNT(*)
                     FROM designation_allowed_class
                     WHERE designation_id=?
                       AND class_id=?
                       AND active=1
                       AND approval_status='APPROVED'
                       AND effective_from<=CURRENT_DATE()
                       AND (
                            effective_to IS NULL
                            OR effective_to>=CURRENT_DATE()
                       )"
                );

                $ok->execute([
                    $_POST['primary_designation_id'],
                    $classId
                ]);

                if((int)$ok->fetchColumn()===0){
                    $fail(
                        'Selected Class is not permitted for this Designation.'
                    );
                }
            }
        }

        try{
            $dobObject=new \DateTimeImmutable(
                (string)$_POST['date_of_birth']
            );
        }catch(\Throwable $e){
            $fail('Date of Birth is invalid.');
        }

        $retirementDate=$dobObject
            ->modify('+60 years')
            ->format('Y-m-d');

        $newPhotoName=null;
        $photo=$_FILES['photograph']??null;

        if(
            $photo
            &&
            ($photo['error']??UPLOAD_ERR_NO_FILE)
                !==UPLOAD_ERR_NO_FILE
        ){
            if(
                ($photo['error']??UPLOAD_ERR_NO_FILE)
                    !==UPLOAD_ERR_OK
            ){
                $fail('Could not upload the photograph.');
            }

            if((int)$photo['size']>5*1024*1024){
                $fail(
                    'Photograph must be 5 MB or smaller.'
                );
            }

            $mime=(new \finfo(FILEINFO_MIME_TYPE))
                ->file($photo['tmp_name']);

            $ext=[
                'image/jpeg'=>'jpg',
                'image/png'=>'png'
            ][$mime]??null;

            if(!$ext){
                $fail(
                    'Photograph must be JPG/JPEG or PNG.'
                );
            }

            $newPhotoName=
                bin2hex(random_bytes(18)).'.'.$ext;

            $photoDir=
                BASE_PATH.'/storage/officer_photos';

            if(!is_dir($photoDir)){
                mkdir($photoDir,0770,true);
            }

            if(
                !move_uploaded_file(
                    $photo['tmp_name'],
                    $photoDir.'/'.$newPhotoName
                )
            ){
                throw new \RuntimeException(
                    'Could not store photograph.'
                );
            }
        }

        $data=[
            'nic'=>$nic,
            'nic_normalized'=>$nic,
            'nic_match_key'=>$nicMatchKey,
            'employee_number'=>$employee,
            'title_id'=>$_POST['title_id'],
            'name_with_initials'=>$name,
            'full_name_en'=>trim((string)$_POST['full_name_en']),
            'full_name_si'=>trim((string)$_POST['full_name_si']),
            'full_name_ta'=>trim((string)$_POST['full_name_ta']),
            'date_of_birth'=>(string)$_POST['date_of_birth'],
            'expected_retirement_date'=>$retirementDate,
            'gender'=>(string)$_POST['gender'],
            'civil_status_id'=>
                ($_POST['civil_status_id']??'') ?: null,
            'permanent_address'=>
                trim((string)$_POST['permanent_address']),
            'temporary_address'=>
                trim((string)$_POST['temporary_address']),
            'primary_mobile'=>$primaryMobile,
            'alternative_mobile'=>$alternativeMobile,
            'personal_email'=>$personalEmail,
            'official_email'=>$officialEmail,
            'initial_appointment_date'=>
                (string)$_POST['initial_appointment_date'],
            'appointment_nature_id'=>$natureId,
            'primary_designation_id'=>
                (string)$_POST['primary_designation_id'],
            'class_id'=>$classId,
            'arpa_service_permanency'=>$servicePermanency,
            'service_permanented_date'=>$permanentedDate,
            'officer_status_id'=>
                (string)$_POST['officer_status_id'],
            'effective_from'=>
                (string)$_POST['effective_from'],
        ];

        if($newPhotoName!==null){
            $data['photograph_path']=$newPhotoName;
        }

        $set=[];

        foreach(array_keys($data) as $column){
            $set[]=$column.'=?';
        }

        $params=array_values($data);

        $ownTransaction=!$pdo->inTransaction();$editRequestSubmitted=false;
        if($ownTransaction)$pdo->beginTransaction();
        try{
            if($directAdminEdit){
                (new OfficerAdminDirectEditService($pdo))->update($id,$data,$expectedVersion,$userId);
            }elseif($editRequestMode){
                $editRequestService->submit($id,$data,$expectedVersion,$userId,$returnedRequestId!==''?$returnedRequestId:null);$editRequestSubmitted=true;
            }else{
                $params[]=$userId;
                $params[]=$id;
                $params[]=$expectedVersion;
                $update=$pdo->prepare('UPDATE officer SET '.implode(',',$set).',updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND version=?');
                $update->execute($params);
                if($update->rowCount()!==1)throw new \DomainException('The Officer changed after this edit form was opened. Reload the profile and try again.');
                (new OfficerOfficeAssignmentService($pdo))->saveInitialForOfficer(
                    $id,
                    ($_POST['initial_office_id']??'') ?: null,
                    ($_POST['office_effective_from']??'') ?: (string)$_POST['effective_from'],
                    $userId
                );
                Audit::record(
                    'officer.edit',
                    'OFFICER',
                    $id,
                    [
                        'scope_checked'=>true,
                        'edited_fields'=>array_keys($data)
                    ]
                );
            }
            if($ownTransaction)$pdo->commit();
        }catch(\Throwable $e){
            if($ownTransaction&&$pdo->inTransaction())$pdo->rollBack();
            if($newPhotoName!==null){
                $newPath=
                    BASE_PATH.
                    '/storage/officer_photos/'.
                    $newPhotoName;

                if(is_file($newPath)){
                    @unlink($newPath);
                }
            }
            if($e instanceof \DomainException){$this->flash('danger',$e->getMessage());redirect('/hr/officers/'.$id.'/edit');}
            throw $e;
        }

        if(
            !$editRequestSubmitted
            &&
            $newPhotoName!==null
            &&
            !empty($current['photograph_path'])
        ){
            $oldPath=
                BASE_PATH.
                '/storage/officer_photos/'.
                basename(
                    (string)$current['photograph_path']
                );

            if(is_file($oldPath)){
                @unlink($oldPath);
            }
        }

        $this->flash('success',$directAdminEdit?'Officer updated successfully.':($editRequestSubmitted?'Officer edit request submitted for approval.':'Officer details updated successfully.'));

        redirect('/hr/officers/'.$id);
    }
    public function create(): void
    {
        Auth::requirePermission('officer.create'); $pdo=Database::pdo();
        $actor=(string)Auth::user()['id'];
        try{(new OfficerWorkflowService($pdo))->creationContext($actor);}catch(\DomainException $e){$this->flash('danger',$e->getMessage());redirect('/hr/officers');}
        $data=[
            'titles'=>$pdo->query("SELECT * FROM hr_title WHERE active=1 ORDER BY display_order")->fetchAll(),
            'appointmentNatures'=>$pdo->query("SELECT * FROM appointment_nature WHERE active=1 ORDER BY display_order")->fetchAll(),
            'designations'=>$pdo->query("SELECT * FROM designation WHERE active=1 ORDER BY designation_level,name_en")->fetchAll(),
            'classes'=>$pdo->query("SELECT * FROM officer_class WHERE active=1 ORDER BY display_order")->fetchAll(),
            'statuses'=>$pdo->query("SELECT * FROM officer_status WHERE active=1 ORDER BY display_order")->fetchAll(),
            'civilStatuses'=>$pdo->query("SELECT * FROM civil_status WHERE active=1 ORDER BY display_order")->fetchAll(),
            'availableOffices'=>ScopeService::scopedOffices($actor),
        ];
        $this->render('officers/form',$data);
    }

    public function store(): void
    {
        Auth::requirePermission('officer.create'); Csrf::validate();
        $actor=(string)Auth::user()['id'];$pdo=Database::pdo();
        try{$workflowContext=(new OfficerWorkflowService($pdo))->creationContext($actor);}catch(\DomainException $e){$this->flash('danger',$e->getMessage());redirect('/hr/officers/create');}
        $nic=NicNormalizer::normalize((string)($_POST['nic']??'')); $name=trim((string)($_POST['name_with_initials']??''));
        if($nic===null||$name===''){ $this->flash('danger','NIC and Name with Initials are required.'); redirect('/hr/officers/create'); }
        if(!NicNormalizer::isValid($nic)){ $this->flash('danger','NIC format is invalid.'); redirect('/hr/officers/create'); }
        $nicMatchKey=NicNormalizer::matchKey($nic);
        $chk=$pdo->prepare('SELECT COUNT(*) FROM officer WHERE nic_normalized=? OR (nic_match_key IS NOT NULL AND nic_match_key=?)');$chk->execute([$nic,$nicMatchKey]);if((int)$chk->fetchColumn()>0){$this->flash('danger','NIC already exists.');redirect('/hr/officers/create');}
        $employee=trim((string)($_POST['employee_number']??''))?:null;
        if($employee){$chk=$pdo->prepare('SELECT COUNT(*) FROM officer WHERE employee_number=?');$chk->execute([$employee]);if((int)$chk->fetchColumn()>0){$this->flash('danger','Employee number already exists.');redirect('/hr/officers/create');}}
        try{
            $contactNumbers=OfficerPersonnelValidator::contactNumbers(
                $_POST['primary_mobile']??null,
                $_POST['alternative_mobile']??null
            );
        }catch(\DomainException $e){
            $this->flash('danger',$e->getMessage());redirect('/hr/officers/create');
        }
        $primaryMobile=$contactNumbers['primary_mobile'];
        $alternativeMobile=$contactNumbers['alternative_mobile'];
        $personalEmail=strtolower(trim((string)($_POST['personal_email']??'')))?:null;$officialEmail=strtolower(trim((string)($_POST['official_email']??'')))?:null;
        foreach(array_filter([$personalEmail,$officialEmail]) as $mail){$chk=$pdo->prepare('SELECT COUNT(*) FROM officer WHERE LOWER(personal_email)=? OR LOWER(official_email)=?');$chk->execute([$mail,$mail]);if((int)$chk->fetchColumn()>0){$this->flash('danger','Email address already belongs to another officer.');redirect('/hr/officers/create');}}
        if($personalEmail && $officialEmail && $personalEmail===$officialEmail){$this->flash('danger','Personal and official email must be different when both are provided.');redirect('/hr/officers/create');}
        $natureId=(string)($_POST['appointment_nature_id']??'');$classId=($_POST['class_id']??'')?:null;
        try{
            $serviceFields=OfficerPersonnelValidator::servicePermanency(
                $_POST['arpa_service_permanency']??null,
                $_POST['service_permanented_date']??null
            );
        }catch(\DomainException $e){
            $this->flash('danger',$e->getMessage());redirect('/hr/officers/create');
        }
        $servicePermanency=$serviceFields['arpa_service_permanency'];
        $permanentedDate=$serviceFields['service_permanented_date'];
        $n=$pdo->prepare('SELECT class_required FROM appointment_nature WHERE id=? AND active=1');$n->execute([$natureId]);$classRequired=(bool)$n->fetchColumn();
        if($classRequired && !$classId){$this->flash('danger','Class is required for the selected Appointment Nature.');redirect('/hr/officers/create');}
        if($classId){$cnt=$pdo->prepare('SELECT COUNT(*) FROM designation_allowed_class WHERE designation_id=? AND active=1');$cnt->execute([$_POST['primary_designation_id']]);if((int)$cnt->fetchColumn()>0){$ok=$pdo->prepare("SELECT COUNT(*) FROM designation_allowed_class WHERE designation_id=? AND class_id=? AND active=1 AND approval_status='APPROVED' AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE())");$ok->execute([$_POST['primary_designation_id'],$classId]);if((int)$ok->fetchColumn()===0){$this->flash('danger','Selected Class is not permitted for this Designation.');redirect('/hr/officers/create');}}}
        $photo=$_FILES['photograph']??null;$photoName=null;
        if($photo&&($photo['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            if(($photo['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){$this->flash('danger','Could not upload the photograph.');redirect('/hr/officers/create');}
            if((int)$photo['size']>5*1024*1024){$this->flash('danger','Photograph must be 5 MB or smaller.');redirect('/hr/officers/create');}
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($photo['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png'][$mime]??null;if(!$ext){$this->flash('danger','Photograph must be JPG/JPEG or PNG.');redirect('/hr/officers/create');}
            $photoName=bin2hex(random_bytes(18)).'.'.$ext;$photoDir=BASE_PATH.'/storage/officer_photos';if(!is_dir($photoDir))mkdir($photoDir,0770,true);if(!move_uploaded_file($photo['tmp_name'],$photoDir.'/'.$photoName))throw new \RuntimeException('Could not store photograph.');
        }
        $dob=(string)($_POST['date_of_birth']??''); $ret=$dob?(new \DateTimeImmutable($dob))->modify('+60 years')->format('Y-m-d'):null;
        $ownTransaction=!$pdo->inTransaction();if($ownTransaction)$pdo->beginTransaction();
        try{
            $dad=NumberService::nextUsing($pdo,'OFFICER');
            $officerId=(string)$pdo->query('SELECT UUID()')->fetchColumn();
            $officerValues=implode(',',array_fill(0,31,'?'));
            $sql="INSERT INTO officer (id,dad_number,nic,nic_normalized,nic_match_key,employee_number,title_id,name_with_initials,full_name_en,full_name_si,full_name_ta,date_of_birth,expected_retirement_date,gender,civil_status_id,permanent_address,temporary_address,primary_mobile,alternative_mobile,personal_email,official_email,photograph_path,initial_appointment_date,appointment_nature_id,primary_designation_id,class_id,arpa_service_permanency,service_permanented_date,officer_status_id,primary_office_id,effective_from,operational_status,approval_status,created_by,created_at,submitted_by,submitted_at,workflow_origin_role_code,workflow_scope_location_id) VALUES({$officerValues},'INACTIVE','SUBMITTED',?,NOW(),?,NOW(),?,?)";
            $vals=[$officerId,$dad,$nic,$nic,$nicMatchKey,$employee,$_POST['title_id']?:null,$name,trim((string)($_POST['full_name_en']??'')),trim((string)($_POST['full_name_si']??'')),trim((string)($_POST['full_name_ta']??'')),$dob?:null,$ret,$_POST['gender']??null,($_POST['civil_status_id']??'')?:null,trim((string)($_POST['permanent_address']??'')),trim((string)($_POST['temporary_address']??'')),$primaryMobile,$alternativeMobile,$personalEmail,$officialEmail,$photoName,$_POST['initial_appointment_date']?:null,$natureId,$_POST['primary_designation_id']?:null,$classId,$servicePermanency,$permanentedDate,$_POST['officer_status_id']?:null,null,$_POST['effective_from']?:date('Y-m-d'),$actor,$actor,$workflowContext['role_code'],$workflowContext['scope_location_id']];
            $pdo->prepare($sql)->execute($vals);
            $initialOfficeAssignmentId=(new OfficerOfficeAssignmentService($pdo))->saveInitialForOfficer($officerId,($_POST['initial_office_id']??'')?:null,($_POST['office_effective_from']??'')?:($_POST['effective_from']??date('Y-m-d')),$actor);
            Audit::record('officer.create','OFFICER',$officerId,['dad_number'=>$dad,'working_context'=>$workflowContext,'initial_office_assignment_id'=>$initialOfficeAssignmentId]);
            Audit::record('workflow.submit','OFFICER',$officerId,['from_status'=>'CREATED','to_status'=>'SUBMITTED','working_context'=>$workflowContext]);
            (new OfficerWorkflowService($pdo))->notifyExistingSubmission($officerId,$actor);
            if($ownTransaction)$pdo->commit();
        }catch(\Throwable $e){
            if($ownTransaction&&$pdo->inTransaction())$pdo->rollBack();
            if($photoName!==null){$path=BASE_PATH.'/storage/officer_photos/'.$photoName;if(is_file($path))@unlink($path);}
            if($e instanceof \DomainException){$this->flash('danger',$e->getMessage());redirect('/hr/officers/create');}
            throw $e;
        }
        $this->flash('success','Officer submitted: '.$dad); redirect('/hr/officers');
    }

    public function photo(string $id): void
    {
        Auth::requirePermission('officer.view-photo');
        if(!(new OfficerWorkflowService(Database::pdo()))->canAccess($id,(string)Auth::user()['id'])){http_response_code(404);exit;}
        $stmt=Database::pdo()->prepare('SELECT photograph_path FROM officer WHERE id=?');$stmt->execute([$id]);$file=$stmt->fetchColumn();
        if(!$file){http_response_code(404);exit;}$path=BASE_PATH.'/storage/officer_photos/'.basename((string)$file);if(!is_file($path)){http_response_code(404);exit;}
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=300');readfile($path);exit;
    }

    public function submit(string $id): void { Auth::requirePermission('officer.submit'); Csrf::validate(); try{(new OfficerWorkflowService(Database::pdo()))->submit($id,(string)Auth::user()['id']);$this->flash('success','Officer submitted.');}catch(\Throwable $e){$this->flash('danger',$e->getMessage());} redirect('/hr/officers'); }
    public function approve(string $id): void { Auth::requirePermission('officer.approve'); Csrf::validate(); try{(new OfficerWorkflowService(Database::pdo()))->approve($id,(string)Auth::user()['id']);$this->flash('success','Officer approved.');}catch(\Throwable $e){$this->flash('danger',$e->getMessage());} redirect('/hr/officers'); }
    public function reconcileInitialOfficeAndApprove(string $id):void{Auth::requirePermission('officer.approve');Csrf::validate();try{(new OfficerWorkflowService(Database::pdo()))->reconcileInitialOfficeAndApprove($id,(string)Auth::user()['id']);$this->flash('success','Officer approved using the existing approved Primary Office assignment.');}catch(\Throwable $e){$this->flash('danger',$e->getMessage());}redirect('/hr/officers/'.$id);}
    public function returnForCorrection(string $id):void{Auth::requirePermission('officer.return');Csrf::validate();try{(new OfficerWorkflowService(Database::pdo()))->returnForCorrection($id,(string)($_POST['reason']??''),(string)Auth::user()['id']);$this->flash('success','Officer returned for correction.');}catch(\Throwable $e){$this->flash('danger',$e->getMessage());}redirect('/hr/officers/'.$id);}

    public function editRequests():void
    {
        if(!Auth::can('officer.edit-request')&&!Auth::can('officer.edit-approve')){http_response_code(403);$this->render('partials/forbidden',['permission'=>'Officer edit request access']);return;}
        try{$requests=(new OfficerEditRequestService(Database::pdo()))->listForActor((string)Auth::user()['id']);}
        catch(\DomainException $e){http_response_code(403);$this->render('partials/forbidden',['permission'=>$e->getMessage()]);return;}
        $this->render('officers/edit_requests/index',compact('requests'));
    }

    public function reviewEditRequest(string $requestId):void
    {
        Auth::requirePermission('officer.edit-approve');
        try{$request=(new OfficerEditRequestService(Database::pdo()))->review($requestId,(string)Auth::user()['id']);}
        catch(\DomainException $e){http_response_code(403);$this->render('partials/forbidden',['permission'=>$e->getMessage()]);return;}
        $this->render('officers/edit_requests/review',compact('request'));
    }

    public function approveEditRequest(string $requestId):void
    {
        Auth::requirePermission('officer.edit-approve');Csrf::validate();
        try{(new OfficerEditRequestService(Database::pdo()))->approve($requestId,(string)Auth::user()['id']);$this->flash('success','Officer profile changes approved and applied.');}
        catch(\DomainException $e){$this->flash('danger',$e->getMessage());}
        redirect('/hr/officer-edit-requests/'.$requestId);
    }

    public function returnEditRequest(string $requestId):void
    {
        Auth::requirePermission('officer.edit-approve');Csrf::validate();
        try{(new OfficerEditRequestService(Database::pdo()))->returnForCorrection($requestId,(string)($_POST['reason']??''),(string)Auth::user()['id']);$this->flash('success','Officer edit request returned for correction.');}
        catch(\DomainException $e){$this->flash('danger',$e->getMessage());}
        redirect('/hr/officer-edit-requests/'.$requestId);
    }

    public function rejectEditRequest(string $requestId):void
    {
        Auth::requirePermission('officer.edit-approve');Csrf::validate();
        try{(new OfficerEditRequestService(Database::pdo()))->reject($requestId,(string)($_POST['reason']??''),(string)Auth::user()['id']);$this->flash('success','Officer edit request rejected.');}
        catch(\DomainException $e){$this->flash('danger',$e->getMessage());}
        redirect('/hr/officer-edit-requests/'.$requestId);
    }

    public function assignOffice(string $id):void
    {
        Auth::requirePermission('officer.office-assignment.create');$userId=(string)Auth::user()['id'];
        $s=Database::pdo()->prepare('SELECT id,dad_number,name_with_initials FROM officer WHERE id=?');$s->execute([$id]);$officer=$s->fetch();if(!$officer){http_response_code(404);$this->render('partials/not-found');return;}$offices=ScopeService::scopedOffices($userId);$this->render('officers/office_assignment_form',compact('officer','offices'));
    }
    public function storeOfficeAssignment(string $id):void{Auth::requirePermission('officer.office-assignment.create');Csrf::validate();try{$_POST['officer_id']=$id;(new OfficerOfficeAssignmentService(Database::pdo()))->create($_POST,(string)Auth::user()['id']);$this->flash('success','Office assignment submitted.');}catch(\Throwable $e){$this->flash('danger',$e->getMessage());redirect('/hr/officers/'.$id.'/offices/assign');}redirect('/hr/officers/'.$id);}
    public function editOfficeAssignment(string $id,string $assignmentId):void
    {
        Auth::requirePermission('officer.office-assignment.view');$actor=(string)Auth::user()['id'];$service=new OfficerOfficeAssignmentService(Database::pdo());
        try{$assignment=$service->directEditRecord($assignmentId,$actor);if((string)$assignment['officer_id']!==$id)throw new \DomainException('Office assignment was not found.');}
        catch(\DomainException){http_response_code(403);$this->render('partials/forbidden',['permission'=>'Head Office direct assignment editing']);return;}
        $offices=ScopeService::scopedOffices($actor);$this->render('officers/office_assignments/edit',compact('assignment','offices'));
    }
    public function updateOfficeAssignment(string $id,string $assignmentId):void
    {
        Auth::requirePermission('officer.office-assignment.view');Csrf::validate();$actor=(string)Auth::user()['id'];$service=new OfficerOfficeAssignmentService(Database::pdo());
        try{$assignment=$service->directEditRecord($assignmentId,$actor);if((string)$assignment['officer_id']!==$id)throw new \DomainException('Office assignment was not found.');}
        catch(\DomainException){http_response_code(403);$this->render('partials/forbidden',['permission'=>'Head Office direct assignment editing']);return;}
        try{$service->directEdit($assignmentId,$_POST,$actor);$this->flash('success','Assignment updated successfully.');}
        catch(\DomainException $e){$this->flash('danger',$e->getMessage());redirect('/hr/officers/'.$id.'/offices/'.$assignmentId.'/edit');}
        redirect('/hr/officers/'.$id);
    }
    public function deleteOfficeAssignmentForm(string $id,string $assignmentId):void
    {
        Auth::requireLogin();$actor=(string)Auth::user()['id'];$service=new OfficerOfficeAssignmentService(Database::pdo());
        try{$assignment=$service->deleteRecord($assignmentId,$actor);if((string)$assignment['officer_id']!==$id)throw new \DomainException('Office assignment was not found.');}
        catch(\DomainException){http_response_code(403);$this->render('partials/forbidden',['permission'=>'DEMS operational administrator assignment deletion']);return;}
        $assignmentType='Officer Office Assignment';$subjectLabel=trim((string)$assignment['officer_dad'].' - '.(string)$assignment['officer_name']);
        $assignmentLabel=trim((string)$assignment['office_dad'].' - '.(string)$assignment['office_name']);
        $postUrl='hr/officers/'.$id.'/offices/'.$assignmentId.'/delete';$cancelUrl='hr/officers/'.$id;
        $this->render('assignments/delete_confirm',compact('assignment','assignmentType','subjectLabel','assignmentLabel','postUrl','cancelUrl'));
    }
    public function deleteOfficeAssignment(string $id,string $assignmentId):void
    {
        Auth::requireLogin();Csrf::validate();$actor=(string)Auth::user()['id'];$service=new OfficerOfficeAssignmentService(Database::pdo());
        try{
            $assignment=$service->deleteRecord($assignmentId,$actor);if((string)$assignment['officer_id']!==$id)throw new \DomainException('Office assignment was not found.');
            if((string)($_POST['confirm_delete']??'')!=='1')throw new \DomainException('Explicit deletion confirmation is required.');
            $service->adminDelete($assignmentId,(string)($_POST['delete_reason']??''),$actor);$this->flash('success','Assignment deleted successfully.');
        }catch(\DomainException $e){
            if(!\App\Services\AssignmentDeletePolicy::allowed()){http_response_code(403);$this->render('partials/forbidden',['permission'=>'DEMS operational administrator assignment deletion']);return;}
            $this->flash('danger',$e->getMessage());redirect('/hr/officers/'.$id.'/offices/'.$assignmentId.'/delete');
        }
        redirect('/hr/officers/'.$id);
    }
    public function submitOfficeAssignment(string $id,string $assignmentId):void{Auth::requirePermission('officer.office-assignment.submit');Csrf::validate();$this->assignmentAction(fn($s,$u)=>$s->submit($assignmentId,$u),$id,'Office assignment submitted.');}
    public function approveOfficeAssignment(string $id,string $assignmentId):void{Auth::requirePermission('officer.office-assignment.approve');Csrf::validate();$this->assignmentAction(fn($s,$u)=>$s->approve($assignmentId,$u),$id,'Office assignment approved.');}
    public function endOfficeAssignment(string $id,string $assignmentId):void{Auth::requirePermission('officer.office-assignment.end');Csrf::validate();$this->assignmentAction(fn($s,$u)=>$s->end($assignmentId,(string)($_POST['effective_to']??''),(string)($_POST['reason']??''),$u),$id,'Office assignment ended.');}
    public function setPrimaryOffice(string $id,string $assignmentId):void{Auth::requirePermission('officer.office-assignment.set-primary');Csrf::validate();$this->assignmentAction(fn($s,$u)=>$s->setPrimary($assignmentId,$u),$id,'Primary Office updated.');}
    private function assignmentAction(callable $callback,string $officerId,string $message):never{try{$callback(new OfficerOfficeAssignmentService(Database::pdo()),(string)Auth::user()['id']);$this->flash('success',$message);}catch(\Throwable $e){$this->flash('danger',$e->getMessage());}redirect('/hr/officers/'.$officerId);}
}
