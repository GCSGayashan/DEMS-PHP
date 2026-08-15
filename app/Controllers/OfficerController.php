<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Auth,Controller,Database,Csrf,NumberService,Audit,WorkflowService,DataTableRegistry,NicNormalizer,ScopeService};
use App\Services\{OfficerOfficeAssignmentService,OfficerProfileService};

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
        $this->render('officers/index',compact('dataTable','scoped'));
    }

    public function show(string $id):void
    {
        Auth::requirePermission('officer.view');$userId=(string)Auth::user()['id'];
        if(!ScopeService::canAccessOfficer($userId,$id)){http_response_code(404);$this->render('partials/not-found');return;}
        $restricted=ScopeService::requiresGeographicRestriction($userId);$offices=ScopeService::scopedOffices($userId);$ascIds=$restricted?array_column(ScopeService::scopedLocations($userId,'ASC'),'id'):null;
        $profile=(new OfficerProfileService(Database::pdo()))->profile($id,$restricted?array_column($offices,'id'):[],$ascIds);$this->render('officers/show',$profile+compact('offices'));
    }

    public function search():void
    {
        Auth::requirePermission('officer.view');$query='';$message=null;$results=[];$this->render('officers/search',compact('query','message','results'));
    }
    public function searchSubmit():void
    {
        Auth::requirePermission('officer.view');Csrf::validate();$query=trim((string)($_POST['nic']??''));$message=null;$results=[];$normalized=NicNormalizer::normalize($query);
        if(!NicNormalizer::isValid($normalized))$message='Enter a valid Sri Lankan NIC.';else{
            $userId=(string)Auth::user()['id'];$access=ScopeService::currentOfficerAccess($userId,'o.id');$params=$access['params'];$where=$access['where'];$where[]='(o.nic_normalized=? OR o.nic_match_key=?)';$params[]=$normalized;$params[]=NicNormalizer::matchKey($normalized);
            $sql=$access['with']." SELECT o.id,o.dad_number,o.name_with_initials,o.nic,d.name_en designation_name,c.name_en class_name,ofc.name_en primary_office_name FROM officer o LEFT JOIN designation d ON d.id=o.primary_designation_id LEFT JOIN officer_class c ON c.id=o.class_id LEFT JOIN office ofc ON ofc.id=o.primary_office_id WHERE ".implode(' AND ',$where).' ORDER BY o.dad_number LIMIT 25';
            $s=Database::pdo()->prepare($sql);$s->execute($params);$results=$s->fetchAll();if(count($results)===1)redirect('/hr/officers/'.$results[0]['id']);$message=$results===[]?'Officer not found.':'More than one Officer matches this NIC. Select the correct scoped Officer below.';
        }
        $this->render('officers/search',compact('query','message','results'));
    }

    public function options():void
    {
        Auth::requirePermission('officer.view');$userId=(string)Auth::user()['id'];$access=ScopeService::currentOfficerAccess($userId,'o.id');$term=trim((string)($_GET['q']??''));
        $where=$access['where'];$params=$access['params'];if($term!==''){$where[]="CONCAT_WS(' ',o.dad_number,o.name_with_initials,o.nic) LIKE ?";$params[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$term).'%';}
        $sql=$access['with']." SELECT o.id,o.dad_number,o.name_with_initials FROM officer o WHERE ".($where?implode(' AND ',$where):'1=1')." ORDER BY o.name_with_initials LIMIT 25";$s=Database::pdo()->prepare($sql);$s->execute($params);
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['results'=>$s->fetchAll()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;
    }

    public function create(): void
    {
        Auth::requirePermission('officer.create'); $pdo=Database::pdo();
        $data=[
            'titles'=>$pdo->query("SELECT * FROM hr_title WHERE active=1 ORDER BY display_order")->fetchAll(),
            'appointmentNatures'=>$pdo->query("SELECT * FROM appointment_nature WHERE active=1 ORDER BY display_order")->fetchAll(),
            'designations'=>$pdo->query("SELECT * FROM designation WHERE active=1 ORDER BY designation_level,name_en")->fetchAll(),
            'classes'=>$pdo->query("SELECT * FROM officer_class WHERE active=1 ORDER BY display_order")->fetchAll(),
            'statuses'=>$pdo->query("SELECT * FROM officer_status WHERE active=1 ORDER BY display_order")->fetchAll(),
            'civilStatuses'=>$pdo->query("SELECT * FROM civil_status WHERE active=1 ORDER BY display_order")->fetchAll(),
        ];
        $this->render('officers/form',$data);
    }

    public function store(): void
    {
        Auth::requirePermission('officer.create'); Csrf::validate();
        $nic=NicNormalizer::normalize((string)($_POST['nic']??'')); $name=trim((string)($_POST['name_with_initials']??''));
        if($nic===null||$name===''){ $this->flash('danger','NIC and Name with Initials are required.'); redirect('/hr/officers/create'); }
        if(!NicNormalizer::isValid($nic)){ $this->flash('danger','NIC format is invalid.'); redirect('/hr/officers/create'); }
        $pdo=Database::pdo();
        $nicMatchKey=NicNormalizer::matchKey($nic);
        $chk=$pdo->prepare('SELECT COUNT(*) FROM officer WHERE nic_normalized=? OR (nic_match_key IS NOT NULL AND nic_match_key=?)');$chk->execute([$nic,$nicMatchKey]);if((int)$chk->fetchColumn()>0){$this->flash('danger','NIC already exists.');redirect('/hr/officers/create');}
        $employee=trim((string)($_POST['employee_number']??''))?:null;
        if($employee){$chk=$pdo->prepare('SELECT COUNT(*) FROM officer WHERE employee_number=?');$chk->execute([$employee]);if((int)$chk->fetchColumn()>0){$this->flash('danger','Employee number already exists.');redirect('/hr/officers/create');}}
        $primaryMobile=trim((string)($_POST['primary_mobile']??''));$alternativeMobile=trim((string)($_POST['alternative_mobile']??''));
        if(!preg_match('/^\+94\d{9}$/',$primaryMobile)||!preg_match('/^\+94\d{9}$/',$alternativeMobile)){$this->flash('danger','Both mobile numbers must use +94XXXXXXXXX format.');redirect('/hr/officers/create');}
        $personalEmail=strtolower(trim((string)($_POST['personal_email']??'')))?:null;$officialEmail=strtolower(trim((string)($_POST['official_email']??'')))?:null;
        foreach(array_filter([$personalEmail,$officialEmail]) as $mail){$chk=$pdo->prepare('SELECT COUNT(*) FROM officer WHERE LOWER(personal_email)=? OR LOWER(official_email)=?');$chk->execute([$mail,$mail]);if((int)$chk->fetchColumn()>0){$this->flash('danger','Email address already belongs to another officer.');redirect('/hr/officers/create');}}
        if($personalEmail && $officialEmail && $personalEmail===$officialEmail){$this->flash('danger','Personal and official email must be different when both are provided.');redirect('/hr/officers/create');}
        $natureId=(string)($_POST['appointment_nature_id']??'');$classId=($_POST['class_id']??'')?:null;
        $n=$pdo->prepare('SELECT class_required FROM appointment_nature WHERE id=? AND active=1');$n->execute([$natureId]);$classRequired=(bool)$n->fetchColumn();
        if($classRequired && !$classId){$this->flash('danger','Class is required for the selected Appointment Nature.');redirect('/hr/officers/create');}
        if($classId){$cnt=$pdo->prepare('SELECT COUNT(*) FROM designation_allowed_class WHERE designation_id=? AND active=1');$cnt->execute([$_POST['primary_designation_id']]);if((int)$cnt->fetchColumn()>0){$ok=$pdo->prepare("SELECT COUNT(*) FROM designation_allowed_class WHERE designation_id=? AND class_id=? AND active=1 AND approval_status='APPROVED' AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE())");$ok->execute([$_POST['primary_designation_id'],$classId]);if((int)$ok->fetchColumn()===0){$this->flash('danger','Selected Class is not permitted for this Designation.');redirect('/hr/officers/create');}}}
        $photo=$_FILES['photograph']??null;if(!$photo||($photo['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){$this->flash('danger','Officer photograph is required.');redirect('/hr/officers/create');}
        if((int)$photo['size']>5*1024*1024){$this->flash('danger','Photograph must be 5 MB or smaller.');redirect('/hr/officers/create');}
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($photo['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png'][$mime]??null;if(!$ext){$this->flash('danger','Photograph must be JPG/JPEG or PNG.');redirect('/hr/officers/create');}
        $photoName=bin2hex(random_bytes(18)).'.'.$ext;$photoDir=BASE_PATH.'/storage/officer_photos';if(!is_dir($photoDir))mkdir($photoDir,0770,true);if(!move_uploaded_file($photo['tmp_name'],$photoDir.'/'.$photoName))throw new \RuntimeException('Could not store photograph.');
        $dob=(string)($_POST['date_of_birth']??''); $ret=$dob?(new \DateTimeImmutable($dob))->modify('+60 years')->format('Y-m-d'):null;
        $dad=NumberService::next('OFFICER');
        $sql="INSERT INTO officer (id,dad_number,nic,nic_normalized,nic_match_key,employee_number,title_id,name_with_initials,full_name_en,full_name_si,full_name_ta,date_of_birth,expected_retirement_date,gender,civil_status_id,permanent_address,temporary_address,primary_mobile,alternative_mobile,personal_email,official_email,photograph_path,initial_appointment_date,appointment_nature_id,primary_designation_id,class_id,officer_status_id,primary_office_id,effective_from,operational_status,approval_status,created_by,created_at) VALUES(UUID(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'INACTIVE','DRAFT',?,NOW())";
        $vals=[$dad,$nic,$nic,$nicMatchKey,$employee,$_POST['title_id']?:null,$name,trim((string)($_POST['full_name_en']??'')),trim((string)($_POST['full_name_si']??'')),trim((string)($_POST['full_name_ta']??'')),$dob?:null,$ret,$_POST['gender']??null,($_POST['civil_status_id']??'')?:null,trim((string)($_POST['permanent_address']??'')),trim((string)($_POST['temporary_address']??'')),$primaryMobile,$alternativeMobile,$personalEmail,$officialEmail,$photoName,$_POST['initial_appointment_date']?:null,$natureId,$_POST['primary_designation_id']?:null,$classId,$_POST['officer_status_id']?:null,null,$_POST['effective_from']?:date('Y-m-d'),Auth::user()['id']];
        $pdo->prepare($sql)->execute($vals);
        Audit::record('officer.create','OFFICER',null,['dad_number'=>$dad,'nic'=>$nic]);
        $this->flash('success','Officer draft created: '.$dad); redirect('/hr/officers');
    }

    public function photo(string $id): void
    {
        Auth::requirePermission('officer.view-photo');
        if(!ScopeService::canAccessOfficer((string)Auth::user()['id'],$id)){http_response_code(404);exit;}
        $stmt=Database::pdo()->prepare('SELECT photograph_path FROM officer WHERE id=?');$stmt->execute([$id]);$file=$stmt->fetchColumn();
        if(!$file){http_response_code(404);exit;}$path=BASE_PATH.'/storage/officer_photos/'.basename((string)$file);if(!is_file($path)){http_response_code(404);exit;}
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=300');readfile($path);exit;
    }

    public function submit(string $id): void { Auth::requirePermission('officer.submit'); Csrf::validate(); WorkflowService::submit('officer',$id); $this->flash('success','Officer submitted.'); redirect('/hr/officers'); }
    public function approve(string $id): void { Auth::requirePermission('officer.approve'); Csrf::validate(); try{WorkflowService::approve('officer',$id);$this->flash('success','Officer approved.');}catch(\Throwable $e){$this->flash('danger',$e->getMessage());} redirect('/hr/officers'); }

    public function assignOffice(string $id):void
    {
        Auth::requirePermission('officer.office-assignment.create');$userId=(string)Auth::user()['id'];
        $s=Database::pdo()->prepare('SELECT id,dad_number,name_with_initials FROM officer WHERE id=?');$s->execute([$id]);$officer=$s->fetch();if(!$officer){http_response_code(404);$this->render('partials/not-found');return;}$offices=ScopeService::scopedOffices($userId);$this->render('officers/office_assignment_form',compact('officer','offices'));
    }
    public function storeOfficeAssignment(string $id):void{Auth::requirePermission('officer.office-assignment.create');Csrf::validate();try{$_POST['officer_id']=$id;(new OfficerOfficeAssignmentService(Database::pdo()))->create($_POST,(string)Auth::user()['id']);$this->flash('success','Office assignment draft created. Submit it for approval.');}catch(\Throwable $e){$this->flash('danger',$e->getMessage());redirect('/hr/officers/'.$id.'/offices/assign');}redirect('/hr/officers/'.$id);}
    public function submitOfficeAssignment(string $id,string $assignmentId):void{Auth::requirePermission('officer.office-assignment.submit');Csrf::validate();$this->assignmentAction(fn($s,$u)=>$s->submit($assignmentId,$u),$id,'Office assignment submitted.');}
    public function approveOfficeAssignment(string $id,string $assignmentId):void{Auth::requirePermission('officer.office-assignment.approve');Csrf::validate();$this->assignmentAction(fn($s,$u)=>$s->approve($assignmentId,$u),$id,'Office assignment approved.');}
    public function endOfficeAssignment(string $id,string $assignmentId):void{Auth::requirePermission('officer.office-assignment.end');Csrf::validate();$this->assignmentAction(fn($s,$u)=>$s->end($assignmentId,(string)($_POST['effective_to']??''),(string)($_POST['reason']??''),$u),$id,'Office assignment ended.');}
    public function setPrimaryOffice(string $id,string $assignmentId):void{Auth::requirePermission('officer.office-assignment.set-primary');Csrf::validate();$this->assignmentAction(fn($s,$u)=>$s->setPrimary($assignmentId,$u),$id,'Primary Office updated.');}
    private function assignmentAction(callable $callback,string $officerId,string $message):never{try{$callback(new OfficerOfficeAssignmentService(Database::pdo()),(string)Auth::user()['id']);$this->flash('success',$message);}catch(\Throwable $e){$this->flash('danger',$e->getMessage());}redirect('/hr/officers/'.$officerId);}
}
