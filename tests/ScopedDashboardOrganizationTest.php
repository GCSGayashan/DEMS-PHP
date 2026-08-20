<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database,ScopeService};
use App\Services\{ScopedDashboardService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class ScopedDashboardOrganizationTest
{
    private PDO $pdo;private int $assertions=0;private string $userId;private string $ascId;
    public function run():int
    {
        $this->pdo=Database::pdo();$s=$this->pdo->query("SELECT su.id user_id,uar.id role_assignment_id,uas.id scope_assignment_id,uas.location_id FROM system_user su JOIN user_account_role uar ON uar.user_id=su.id JOIN application_role r ON r.id=uar.role_id AND r.role_code='ASC_SUBJECT_OFFICER' JOIN user_account_scope uas ON uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id JOIN location l ON l.id=uas.location_id AND l.dad_number='70004-0000389' WHERE su.username='asctest' AND uar.active=1 AND uar.approval_status='APPROVED' AND uas.active=1 AND uas.approval_status='APPROVED' AND uas.scope_type='ASC' AND uas.scope_mode='EXACT'");$fixture=$s->fetch();if(!$fixture)throw new RuntimeException('The asctest ASC Subject Officer context fixture is unavailable.');$this->userId=$fixture['user_id'];$this->ascId=$fixture['location_id'];$_SESSION=['user_id'=>$this->userId,'authenticated_at'=>time(),'last_activity_at'=>time()];(new UserContextService($this->pdo))->select($this->userId,(string)$fixture['role_assignment_id'],(string)$fixture['scope_assignment_id']);Auth::forgetRequestCache();
        $this->scopeProjection();$this->dashboard();$this->organizationDataTable();$this->roleBoundaries();$this->uiContract();
        echo "ScopedDashboardOrganizationTest: {$this->assertions} assertions passed.\n";return 0;
    }
    private function scopeProjection():void
    {
        $profile=ScopeService::scopeProfile($this->userId);$this->same('ASC',$profile['level'],'scope profile level');$this->same(false,$profile['enterprise'],'ASC is not enterprise-wide');$this->same('70004-0000389',$profile['primary']['dad_number'],'scope is read from persisted account scope');
        $this->same(true,ScopeService::canAccessLocation($this->userId,$this->ascId),'own ASC accessible');
        $related=ScopeService::scopedLocations($this->userId,'ARPA_DIVISION');$this->same(16,count($related),'related ARPA Divisions visible');
        $unrelated=$this->pdo->prepare("SELECT l.id FROM location l JOIN location_type t ON t.id=l.location_type_id WHERE t.system_key='ASC' AND l.id<>? LIMIT 1");$unrelated->execute([$this->ascId]);$this->same(false,ScopeService::canAccessLocation($this->userId,(string)$unrelated->fetchColumn()),'unrelated ASC denied');
        foreach(['PROVINCE','DISTRICT'] as $type)$this->same(1,count(ScopeService::scopedLocations($this->userId,$type)),"only contextual {$type} visible");
    }
    private function dashboard():void
    {
        $data=(new ScopedDashboardService($this->pdo))->dashboard($this->userId,false);$expected=(int)$this->pdo->query("SELECT COUNT(*) FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.asc_location_id='{$this->ascId}' AND a.legacy_history_only=0 AND a.effective_from<=CURRENT_DATE() AND (c.effective_to IS NULL OR c.effective_to>=CURRENT_DATE())")->fetchColumn();$this->same('SCOPED',$data['mode'],'scoped dashboard selected');$this->same(16,$data['counts']['arpa_divisions'],'dashboard ARPA Division count');$this->same($expected,$data['counts']['current_appointments'],'dashboard counts only valid scoped operational appointments');$this->same(0,$data['counts']['subject_assignments'],'no fabricated subject assignments');$this->same($expected,array_sum($data['charts']['appointment_mix']),'appointment chart reconciles to scoped operational appointments');$this->same(false,array_key_exists('historical_users',$data['counts']),'system identity totals excluded');$this->same(false,array_key_exists('locations',$data['counts']),'enterprise location total excluded');
    }
    private function organizationDataTable():void
    {
        $config=DataTableRegistry::definition('locations');$query=new DataTableQuery($this->pdo,$config,new DataTableRequest(['length'=>100]));$response=$query->response();$this->same(true,$response['recordsTotal']<18181,'national location total is not returned');
        $export=iterator_to_array($query->exportRows());$numbers=array_column($export,'dad_number');$this->same(true,in_array('70004-0000389',$numbers,true),'own ASC included in CSV query');
        $unrelated=$this->pdo->query("SELECT dad_number FROM location WHERE dad_number<>'70004-0000389' AND location_type_id=(SELECT id FROM location_type WHERE system_key='ASC') LIMIT 1")->fetchColumn();$this->same(false,in_array($unrelated,$numbers,true),'unrelated ASC excluded from CSV query');
        $arpaConfig=DataTableRegistry::definition('locations',['scope_type'=>'ARPA_DIVISION']);$arpa=(new DataTableQuery($this->pdo,$arpaConfig,new DataTableRequest(['length'=>100])))->response();$this->same(16,$arpa['recordsTotal'],'ARPA DataTable is scoped');
        $hierarchy=DataTableRegistry::definition('location-hierarchy');$hier=(new DataTableQuery($this->pdo,$hierarchy,new DataTableRequest(['length'=>100])))->response();$this->same(true,$hier['recordsTotal']>0,'scoped hierarchy has contextual relationships');
    }
    private function roleBoundaries():void
    {
        $roles=['ASC_SUBJECT_OFFICER','ASC_ADMIN','ASC_VIEWER','DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN','DISTRICT_VIEWER','NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN','NATIONAL_VIEWER'];$placeholders=implode(',',array_fill(0,count($roles),'?'));$s=$this->pdo->prepare("SELECT COUNT(DISTINCT r.role_code) FROM application_role r JOIN application_role_permission rp ON rp.role_id=r.id JOIN application_permission p ON p.id=rp.permission_id WHERE r.role_code IN ({$placeholders}) AND p.permission_key='location.view'");$s->execute($roles);$this->same(9,(int)$s->fetchColumn(),'all established scoped roles have Organization read permission');
        $role=$this->pdo->query("SELECT COUNT(*) FROM system_user su JOIN user_account_role uar ON uar.user_id=su.id JOIN application_role r ON r.id=uar.role_id WHERE su.username='asctest' AND uar.active=1 AND r.role_code='ASC_SUBJECT_OFFICER'")->fetchColumn();$this->same(1,(int)$role,'asctest ASC Subject Officer assignment remains available');
    }
    private function uiContract():void
    {
        $view=file_get_contents(BASE_PATH.'/app/Views/dashboard/index.php');$layout=file_get_contents(BASE_PATH.'/app/Views/layouts/admin.php');$chart=file_get_contents(BASE_PATH.'/public/assets/js/dems-charts.js');$this->contains('No operational appointment data is available',$view,'zero-data message');$this->contains('My Agrarian Service Center',$layout,'scoped menu');$this->contains('server-scoped',$chart,'local chart consumes server-scoped data');
    }
    private function same(mixed $e,mixed $a,string $m):void{$this->assertions++;if($e!==$a)throw new RuntimeException("{$m}: expected ".var_export($e,true).', got '.var_export($a,true));}
    private function contains(string $n,string $h,string $m):void{$this->assertions++;if(!str_contains($h,$n))throw new RuntimeException("{$m}: missing {$n}");}
}
exit((new ScopedDashboardOrganizationTest())->run());
