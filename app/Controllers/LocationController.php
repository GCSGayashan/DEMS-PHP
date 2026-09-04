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
use App\Services\LocationDirectEditPolicy;
use App\Services\LocationHierarchyDirectEditService;
use App\Services\LocationHierarchyLookupService;
use DomainException;
use Throwable;

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

    public function hierarchyOptions(): void
    {
        Auth::requirePermission('location.view');
        header('Content-Type: application/json; charset=utf-8');
        try{
            $user=Auth::user();
            if(!$user)throw new DomainException('Authentication is required.');
            $results=(new LocationHierarchyLookupService(Database::pdo()))->children(
                (string)$user['id'],
                trim((string)($_GET['parent_id']??'')),
                trim((string)($_GET['child_type']??'')),
                trim((string)($_GET['q']??'')),
                (int)($_GET['limit']??500)
            );
            echo json_encode(['results'=>$results],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        }catch(DomainException){
            http_response_code(403);
            echo json_encode(['error'=>'The selected Location hierarchy is unavailable.'],JSON_THROW_ON_ERROR);
        }
        exit;
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
        try{$gnIdentifiers=$this->validatedGnIdentifiers((string)$typeKey,$_POST);}
        catch(DomainException $e){$this->flash('danger',$e->getMessage());redirect('/locations/create');}
        $stmt = Database::pdo()->prepare("INSERT INTO location (id,dad_number,location_type_id,official_code,gn_code,gn_code_for_plr,name_en,name_si,name_ta,effective_from,operational_status,approval_status,created_by,created_at) VALUES (UUID(),?,?,?,?,?,?,?,?,?,'INACTIVE','SUBMITTED',?,NOW())");
        $stmt->execute([$dad,$typeId,trim((string)($_POST['official_code']??'')) ?: null,$gnIdentifiers['gn_code'],$gnIdentifiers['gn_code_for_plr'],$name,trim((string)($_POST['name_si']??'')) ?: null,trim((string)($_POST['name_ta']??'')) ?: null,$effective,Auth::user()['id']]);
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
        $gnDsHierarchy=null;
        if((string)$location['type_key']==='GN_DIVISION'){
            $stmt=$pdo->prepare("SELECT p.id,p.dad_number,p.name_en FROM location_relationship lr JOIN location p ON p.id=lr.parent_location_id JOIN location_type pt ON pt.id=p.location_type_id AND pt.system_key='DS_DIVISION' WHERE lr.child_location_id=? AND lr.relationship_type='DS_DIVISION_GN_DIVISION' AND lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=CURRENT_DATE() AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE()) ORDER BY p.name_en,p.dad_number,lr.id");
            $stmt->execute([$id]);$currentDs=$stmt->fetchAll();$count=count($currentDs);
            $gnDsHierarchy=['status'=>$count===0?'NOT_ASSIGNED':($count===1?'ASSIGNED':'DATA_ISSUE'),'location'=>$count===1?$currentDs[0]:null,'conflicts'=>$count>1&&LocationDirectEditPolicy::allowed()?$currentDs:[]];
        }
        $excludeGnDs=(string)$location['type_key']==='GN_DIVISION'?" AND lr.relationship_type<>'DS_DIVISION_GN_DIVISION'":'';
        $stmt=$pdo->prepare("SELECT lr.relationship_type,p.id parent_id,p.dad_number parent_number,p.name_en parent_name,c.id child_id,c.dad_number child_number,c.name_en child_name FROM location_relationship lr JOIN location p ON p.id=lr.parent_location_id JOIN location c ON c.id=lr.child_location_id WHERE (lr.parent_location_id=? OR lr.child_location_id=?) AND lr.active=1 AND lr.approval_status='APPROVED'{$excludeGnDs} ORDER BY lr.relationship_type,p.name_en,c.name_en");$stmt->execute([$id,$id]);$relationships=array_values(array_filter($stmt->fetchAll(),fn($row)=>ScopeService::canAccessLocation((string)$user['id'],(string)$row['parent_id'])&&ScopeService::canAccessLocation((string)$user['id'],(string)$row['child_id'])));
        $this->render('locations/show',compact('location','relationships','gnDsHierarchy'));
    }

    public function edit(string $id): void
    {
        $this->requireSystemAdminDirectEdit();
        $stmt=Database::pdo()->prepare('SELECT l.*,lt.name_en type_name,lt.system_key type_key FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE l.id=?');
        $stmt->execute([$id]);$location=$stmt->fetch();
        if(!$location){http_response_code(404);$this->render('partials/not-found');return;}
        $hierarchy=(new LocationHierarchyDirectEditService(Database::pdo()))->formData($location);
        $this->render('locations/form',compact('location','hierarchy'));
    }

    public function update(string $id): void
    {
        $this->requireSystemAdminDirectEdit();Csrf::validate();$pdo=Database::pdo();
        $stmt=$pdo->prepare('SELECT l.id,l.dad_number,l.location_type_id,l.official_code,l.gn_code,l.gn_code_for_plr,l.name_en,l.name_si,l.name_ta,l.effective_from,l.operational_status,l.approval_status,l.version,lt.system_key type_key FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE l.id=?');
        $stmt->execute([$id]);$before=$stmt->fetch();
        if(!$before){http_response_code(404);$this->render('partials/not-found');return;}

        try{
            $after=array_merge($this->validatedDirectEditInput($_POST),$this->validatedGnIdentifiers((string)$before['type_key'],$_POST));
            $pdo->beginTransaction();
            $stmt=$pdo->prepare('UPDATE location SET official_code=?,gn_code=?,gn_code_for_plr=?,name_en=?,name_si=?,name_ta=?,effective_from=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND version=?');
            $stmt->execute([$after['official_code'],$after['gn_code'],$after['gn_code_for_plr'],$after['name_en'],$after['name_si'],$after['name_ta'],$after['effective_from'],(string)Auth::user()['id'],$id,(int)$before['version']]);
            if($stmt->rowCount()!==1)throw new DomainException('This Location was changed by another user. Reload it and try again.');
            $hierarchy=(new LocationHierarchyDirectEditService($pdo))->replace($id,(string)$before['type_key'],$_POST,(string)Auth::user()['id']);
            $after=array_merge($before,$after,['version'=>(int)$before['version']+1]);
            Audit::record('location.direct-update','LOCATION',$id,['before'=>$before,'after'=>$after,'hierarchy'=>$hierarchy]);
            $pdo->commit();$this->flash('success','Location updated directly. No approval was required.');redirect('/locations/'.$id);
        }catch(DomainException $e){
            if($pdo->inTransaction())$pdo->rollBack();$this->flash('danger',$e->getMessage());redirect('/locations/'.$id.'/edit');
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();error_log('Location direct update failed: '.get_class($e).' '.$e->getMessage());$this->flash('danger','Unable to update the Location.');redirect('/locations/'.$id.'/edit');
        }
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

    private function requireSystemAdminDirectEdit(): void
    {
        Auth::requirePermission('location.edit');
        if(!LocationDirectEditPolicy::allowed()){
            http_response_code(403);$this->render('partials/forbidden',['permission'=>'SYSTEM_ADMIN location direct edit']);exit;
        }
    }

    private function validatedDirectEditInput(array $input): array
    {
        $name=trim((string)($input['name_en']??''));$officialCode=$this->nullable($input['official_code']??null);
        $nameSi=$this->nullable($input['name_si']??null);$nameTa=$this->nullable($input['name_ta']??null);
        $effectiveFrom=trim((string)($input['effective_from']??''));
        if($name==='')throw new DomainException('English name is required.');
        if(mb_strlen($name)>255||($nameSi!==null&&mb_strlen($nameSi)>255)||($nameTa!==null&&mb_strlen($nameTa)>255))throw new DomainException('Location names must not exceed 255 characters.');
        if($officialCode!==null&&mb_strlen($officialCode)>100)throw new DomainException('Official code must not exceed 100 characters.');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$effectiveFrom);
        if(!$date||$date->format('Y-m-d')!==$effectiveFrom)throw new DomainException('A valid Start Date is required.');
        return ['official_code'=>$officialCode,'name_en'=>$name,'name_si'=>$nameSi,'name_ta'=>$nameTa,'effective_from'=>$effectiveFrom];
    }

    private function nullable(mixed $value): ?string
    {
        $value=trim((string)$value);return $value===''?null:$value;
    }

    private function validatedGnIdentifiers(string $typeKey,array $input): array
    {
        if($typeKey!=='GN_DIVISION')return ['gn_code'=>null,'gn_code_for_plr'=>null];
        $gnCode=$this->nullable($input['gn_code']??null);$plrCode=$this->nullable($input['gn_code_for_plr']??null);
        if($gnCode!==null&&mb_strlen($gnCode)>20)throw new DomainException('GN Code must not exceed 20 characters.');
        if($plrCode!==null&&mb_strlen($plrCode)>11)throw new DomainException('GN Code for PLR must not exceed 11 characters.');
        return ['gn_code'=>$gnCode,'gn_code_for_plr'=>$plrCode];
    }
}
