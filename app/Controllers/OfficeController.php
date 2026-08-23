<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Auth,Controller,Database,Csrf,NumberService,Audit,WorkflowService,DataTableRegistry,ScopeService};

final class OfficeController extends Controller
{
    public function index(): void
    {
        Auth::requirePermission('office.view');
        $types = Database::pdo()->query('SELECT id,name_en FROM office_type ORDER BY display_order,name_en')->fetchAll();
        $dataTable = DataTableRegistry::viewModel('offices', [], ['office_type' => array_column($types, 'name_en', 'id')]);
        $this->render('offices/index',compact('dataTable'));
    }

    public function create(): void
    {
        Auth::requirePermission('office.create');
        $pdo=Database::pdo();
        $types=$pdo->query("SELECT * FROM office_type WHERE active=1 ORDER BY display_order")->fetchAll();
        $locations=array_merge(array_map(fn($r)=>$r+['type_key'=>'DISTRICT'],ScopeService::scopedLocations((string)Auth::user()['id'],'DISTRICT')),array_map(fn($r)=>$r+['type_key'=>'ASC'],ScopeService::scopedLocations((string)Auth::user()['id'],'ASC')));
        $this->render('offices/form',compact('types','locations'));
    }

    public function show(string $id):void
    {
        Auth::requirePermission('office.view');$userId=(string)Auth::user()['id'];
        if(!ScopeService::canAccessOffice($userId,$id)){http_response_code(404);$this->render('partials/not-found');return;}
        $pdo=Database::pdo();$s=$pdo->prepare("SELECT o.*,ot.system_key office_type_key,ot.name_en office_type_name,l.dad_number location_dad,l.name_en location_name FROM office o JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id WHERE o.id=?");$s->execute([$id]);$office=$s->fetch();if(!$office){http_response_code(404);$this->render('partials/not-found');return;}
        $s=$pdo->prepare("SELECT a.*,f.dad_number officer_dad,f.name_with_initials officer_name FROM officer_office_assignment a JOIN officer f ON f.id=a.officer_id WHERE a.office_id=? AND a.approval_status='APPROVED' AND a.active=1 AND a.effective_from<=CURRENT_DATE() AND (a.effective_to IS NULL OR a.effective_to>=CURRENT_DATE()) ORDER BY a.is_primary DESC,f.name_with_initials");$s->execute([$id]);$currentAssignments=$s->fetchAll();
        $s=$pdo->prepare("SELECT a.*,f.dad_number officer_dad,f.name_with_initials officer_name FROM officer_office_assignment a JOIN officer f ON f.id=a.officer_id WHERE a.office_id=? AND (a.approval_status<>'APPROVED' OR a.active=0 OR a.effective_from>CURRENT_DATE() OR a.effective_to<CURRENT_DATE()) ORDER BY a.effective_from DESC");$s->execute([$id]);$historicalAssignments=$s->fetchAll();
        $this->render('offices/show',compact('office','currentAssignments','historicalAssignments'));
    }

    public function store(): void
    {
        Auth::requirePermission('office.create'); Csrf::validate();
        $pdo=Database::pdo();
        $type=(string)($_POST['office_type_id']??''); $name=trim((string)($_POST['name_en']??''));
        if($type===''||$name===''){ $this->flash('danger','Office type and English name are required.'); redirect('/offices/create');}
        $typeRow=$pdo->prepare('SELECT system_key FROM office_type WHERE id=? AND active=1');$typeRow->execute([$type]);$typeData=$typeRow->fetch();if(!$typeData){$this->flash('danger','Invalid Office Type.');redirect('/offices/create');}
        $location=($_POST['linked_location_id']??'')?:null;if($typeData['system_key']!=='HEAD_OFFICE'&&$location===null){$this->flash('danger','District and ASC Offices require their organizational Location.');redirect('/offices/create');}
        if($location!==null&&!ScopeService::canAccessLocation((string)Auth::user()['id'],$location)){$this->flash('danger','You cannot select this location.');redirect('/offices/create');}
        $pdo->beginTransaction();try{$dad=NumberService::nextUsing($pdo,'OFFICE');
        $stmt=$pdo->prepare("INSERT INTO office (id,dad_number,office_type_id,name_en,name_si,name_ta,short_name,linked_location_id,address,telephone,email,effective_from,requested_status,operational_status,approval_status,created_by,created_at,submitted_by,submitted_at) VALUES(UUID(),?,?,?,?,?,?,?,?,?,?,?,'ACTIVE','INACTIVE','SUBMITTED',?,NOW(),?,NOW())");
        $actor=(string)Auth::user()['id'];
        $stmt->execute([$dad,$type,$name,trim((string)($_POST['name_si']??''))?:null,trim((string)($_POST['name_ta']??''))?:null,trim((string)($_POST['short_name']??''))?:null,$location,trim((string)($_POST['address']??''))?:null,trim((string)($_POST['telephone']??''))?:null,trim((string)($_POST['email']??''))?:null,(string)($_POST['effective_from']??date('Y-m-d')),$actor,$actor]);$pdo->commit();}catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Office create failed: '.$e->getMessage());$this->flash('danger','Office could not be created. Please review the selected type and location.');redirect('/offices/create');}
        Audit::record('office.create','OFFICE',null,['dad_number'=>$dad]);
        Audit::record('workflow.submit','OFFICE',null,['dad_number'=>$dad]);
        $this->flash('success','Office submitted: '.$dad); redirect('/offices');
    }

    public function submit(string $id): void { Auth::requirePermission('office.submit'); Csrf::validate(); WorkflowService::submit('office',$id); $this->flash('success','Office submitted.'); redirect('/offices'); }
    public function approve(string $id): void { Auth::requirePermission('office.approve'); Csrf::validate(); try{WorkflowService::approve('office',$id);$this->flash('success','Office approved.');}catch(\Throwable $e){$this->flash('danger',$e->getMessage());} redirect('/offices'); }
}
