<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Csrf;
use App\Core\NumberService;
use App\Core\Audit;
use App\Core\DataTableRegistry;
use App\Core\ScopeService;

final class LocationController extends Controller
{
    public function index(): void
    {
        Auth::requirePermission('location.view');
        $types = Database::pdo()->query('SELECT system_key,name_en FROM location_type WHERE active=1 ORDER BY display_order')->fetchAll();
        $typeOptions = array_column($types, 'name_en', 'system_key');
        $dataTable = DataTableRegistry::viewModel('locations', [], ['location_type' => $typeOptions]);
        $this->render('locations/index', compact('dataTable'));
    }

    public function types(): void
    {
        Auth::requirePermission('location.view');
        $dataTable = DataTableRegistry::viewModel('location-types');
        $this->render('locations/types', compact('dataTable'));
    }

    public function hierarchy(): void
    {
        Auth::requirePermission('location.view');
        $rows = Database::pdo()->query('SELECT DISTINCT relationship_type FROM location_relationship ORDER BY relationship_type')->fetchAll();
        $options = array_column($rows, 'relationship_type', 'relationship_type');
        $dataTable = DataTableRegistry::viewModel('location-hierarchy', [], ['relationship_type' => $options]);
        $this->render('locations/hierarchy', compact('dataTable'));
    }

    public function create(): void
    {
        Auth::requirePermission('location.create');
        $types = Database::pdo()->query("SELECT * FROM location_type WHERE active=1 ORDER BY display_order")->fetchAll();
        $this->render('locations/form', compact('types'));
    }

    public function store(): void
    {
        Auth::requirePermission('location.create'); Csrf::validate();
        $typeId = (string)($_POST['location_type_id'] ?? '');
        $name = trim((string)($_POST['name_en'] ?? ''));
        $effective = (string)($_POST['effective_from'] ?? date('Y-m-d'));
        if ($typeId==='' || $name==='') { $this->flash('danger','Location type and English name are required.'); redirect('/locations/create'); }
        $catStmt=$pdo=Database::pdo();
        $ts=$pdo->prepare('SELECT system_key FROM location_type WHERE id=?'); $ts->execute([$typeId]); $typeKey=$ts->fetchColumn();
        $dad = NumberService::next('LOCATION_'.(string)$typeKey);
        $stmt = Database::pdo()->prepare("INSERT INTO location (id,dad_number,location_type_id,official_code,name_en,name_si,name_ta,effective_from,operational_status,approval_status,created_by,created_at) VALUES (UUID(),?,?,?,?,?,?,?,'INACTIVE','SUBMITTED',?,NOW())");
        $stmt->execute([$dad,$typeId,trim((string)($_POST['official_code']??'')) ?: null,$name,trim((string)($_POST['name_si']??'')) ?: null,trim((string)($_POST['name_ta']??'')) ?: null,$effective,Auth::user()['id']]);
        Audit::record('location.create','LOCATION',null,['dad_number'=>$dad]);
        Audit::record('workflow.submit','LOCATION',null,['dad_number'=>$dad]);
        $this->flash('success','Location submitted: '.$dad); redirect('/locations');
    }

    public function submit(string $id): void
    {
        Auth::requirePermission('location.submit'); Csrf::validate();
        $actor=(string)Auth::user()['id'];$this->assertLocationScope($actor,$id);
        $stmt=Database::pdo()->prepare("UPDATE location SET approval_status='SUBMITTED',updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND approval_status='DRAFT'");
        $stmt->execute([$actor,$id]);
        if($stmt->rowCount()!==1){$this->flash('danger','Only a draft Location can be submitted.');redirect('/locations');}
        Audit::record('workflow.submit','LOCATION',$id);
        $this->flash('success','Location submitted.');redirect('/locations');
    }

    public function approve(string $id): void
    {
        Auth::requirePermission('location.approve'); Csrf::validate();
        $actor=(string)Auth::user()['id'];$this->assertLocationScope($actor,$id);$pdo=Database::pdo();
        $stmt=$pdo->prepare('SELECT created_by,approval_status,effective_from FROM location WHERE id=?');$stmt->execute([$id]);$row=$stmt->fetch();
        if(!$row){http_response_code(404);$this->render('partials/not-found');return;}
        if($row['approval_status']!=='SUBMITTED'){$this->flash('danger','Only a submitted Location can be approved.');redirect('/locations');}
        if((string)$row['created_by']===$actor){$this->flash('danger','You cannot approve a Location you created.');redirect('/locations');}
        $pdo->prepare("UPDATE location SET approval_status='APPROVED',operational_status=CASE WHEN effective_from<=CURRENT_DATE() THEN 'ACTIVE' ELSE 'INACTIVE' END,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND approval_status='SUBMITTED'")->execute([$actor,$id]);
        Audit::record('workflow.approve','LOCATION',$id);
        $this->flash('success','Location approved.');redirect('/locations');
    }

    public function show(string $id):void
    {
        Auth::requirePermission('location.view');$user=Auth::user();
        if(!$user||!ScopeService::canAccessLocation((string)$user['id'],$id)){http_response_code(404);$this->render('partials/not-found');return;}
        $pdo=Database::pdo();$stmt=$pdo->prepare('SELECT l.*,lt.name_en type_name,lt.system_key type_key FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE l.id=?');$stmt->execute([$id]);$location=$stmt->fetch();
        if(!$location){http_response_code(404);$this->render('partials/not-found');return;}
        $stmt=$pdo->prepare("SELECT lr.relationship_type,p.id parent_id,p.dad_number parent_number,p.name_en parent_name,c.id child_id,c.dad_number child_number,c.name_en child_name FROM location_relationship lr JOIN location p ON p.id=lr.parent_location_id JOIN location c ON c.id=lr.child_location_id WHERE (lr.parent_location_id=? OR lr.child_location_id=?) AND lr.active=1 AND lr.approval_status='APPROVED' ORDER BY lr.relationship_type,p.name_en,c.name_en");$stmt->execute([$id,$id]);$relationships=array_values(array_filter($stmt->fetchAll(),fn($row)=>ScopeService::canAccessLocation((string)$user['id'],(string)$row['parent_id'])&&ScopeService::canAccessLocation((string)$user['id'],(string)$row['child_id'])));
        $this->render('locations/show',compact('location','relationships'));
    }

    public function byType(string $systemKey): void
    {
        Auth::requirePermission('location.view');
        $pdo=Database::pdo();
        $stmt=$pdo->prepare("SELECT * FROM location_type WHERE system_key=? AND active=1");$stmt->execute([$systemKey]);$type=$stmt->fetch();
        if(!$type){http_response_code(404);exit('Location type not found.');}
        $dataTable=DataTableRegistry::viewModel('locations',['scope_type'=>$systemKey]);
        $this->render('locations/index',compact('dataTable','type'));
    }

    private function assertLocationScope(string $userId,string $locationId):void
    {
        if(!ScopeService::canAccessLocation($userId,$locationId)){http_response_code(404);$this->render('partials/not-found');exit;}
    }
}
