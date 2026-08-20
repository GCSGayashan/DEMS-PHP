<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database,ScopeService};
use App\Services\{ArpaAppointmentCandidateService,ScopedDashboardService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class ScopedOfficerDirectoryTest
{
    private PDO $pdo;private int $assertions=0;private string $userId;private string $ascId;
    public function run():int
    {
        $this->pdo=Database::pdo();$s=$this->pdo->query("SELECT su.id user_id,uar.id role_assignment_id,uas.id scope_assignment_id,uas.location_id FROM system_user su JOIN user_account_role uar ON uar.user_id=su.id JOIN application_role r ON r.id=uar.role_id AND r.role_code='ASC_SUBJECT_OFFICER' JOIN user_account_scope uas ON uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id JOIN location l ON l.id=uas.location_id AND l.dad_number='70004-0000389' WHERE su.username='asctest' AND uar.active=1 AND uar.approval_status='APPROVED' AND uas.scope_type='ASC' AND uas.scope_mode='EXACT' AND uas.active=1 AND uas.approval_status='APPROVED'");$r=$s->fetch();if(!$r)throw new RuntimeException('The asctest ASC Subject Officer context fixture is unavailable.');$this->userId=$r['user_id'];$this->ascId=$r['location_id'];$_SESSION=['user_id'=>$this->userId,'authenticated_at'=>time(),'last_activity_at'=>time()];(new UserContextService($this->pdo))->select($this->userId,(string)$r['role_assignment_id'],(string)$r['scope_assignment_id']);Auth::forgetRequestCache();
        $this->testNavigationAndChart();$this->testPermissions();$this->testOperationalScope();echo "ScopedOfficerDirectoryTest: {$this->assertions} assertions passed.\n";return 0;
    }
    private function testNavigationAndChart():void
    {
        $layout=file_get_contents(BASE_PATH.'/app/Views/layouts/admin.php');$view=file_get_contents(BASE_PATH.'/app/Views/dashboard/index.php');$js=file_get_contents(BASE_PATH.'/public/assets/js/dems-charts.js');
        $this->contains("organizationScope['level']!=='ASC'",$layout,'ASC condition hides Location Hierarchy');$this->contains('My Agrarian Service Center',$layout,'ASC Organization menu retained');$this->contains("Auth::can('officer.view')",$layout,'Officers menu is permission-controlled');$this->contains('ARPA Officer Appointments',$layout,'ARPA menu retained');
        $this->contains('data-chart-type="horizontalBar"',$view,'coverage is horizontal');foreach(['Total ARPA Divisions','With Current Officer','Without Current Officer'] as $label)$this->contains($label,file_get_contents(BASE_PATH.'/app/Services/ScopedDashboardService.php'),"full {$label} label");$this->contains('measureText',$js,'left margin measures complete labels');$this->contains('ResizeObserver',$js,'chart is responsive');$this->same(false,str_contains($js,'ellipsis'),'labels are not truncated with ellipsis');$this->same(false,str_contains($js,'.rotate('),'labels are never rotated');
    }
    private function testPermissions():void
    {
        $roles=['ASC_SUBJECT_OFFICER','ASC_ADMIN','ASC_VIEWER'];$s=$this->pdo->prepare("SELECT COUNT(DISTINCT r.role_code) FROM application_role r JOIN application_role_permission rp ON rp.role_id=r.id JOIN application_permission p ON p.id=rp.permission_id WHERE r.role_code IN (?,?,?) AND p.permission_key='officer.view'");$s->execute($roles);$this->same(3,(int)$s->fetchColumn(),'all ASC roles receive read-only Officer access');
        $mutation=$this->pdo->prepare("SELECT COUNT(*) FROM application_role r JOIN application_role_permission rp ON rp.role_id=r.id JOIN application_permission p ON p.id=rp.permission_id WHERE r.role_code IN ('ASC_ADMIN','ASC_VIEWER') AND p.permission_key IN ('officer.create','officer.edit','officer.submit','officer.approve')");$mutation->execute();$this->same(0,(int)$mutation->fetchColumn(),'Officer read access grants no HR mutation permissions');
    }
    private function testOperationalScope():void
    {
        $definition=DataTableRegistry::definition('officers');$baseline=(new DataTableQuery($this->pdo,$definition,new DataTableRequest(['length'=>25])))->response();$this->same(7,$baseline['recordsTotal'],'Kurunegala directory contains the seven qualifying current ARPA Officers');
        $dashboard=(new ScopedDashboardService($this->pdo))->dashboard($this->userId,false);$expected=(int)$this->pdo->query("SELECT COUNT(*) FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.asc_location_id='{$this->ascId}' AND a.legacy_history_only=0 AND a.effective_from<=CURRENT_DATE() AND (c.effective_to IS NULL OR c.effective_to>=CURRENT_DATE())")->fetchColumn();$this->same(7,$dashboard['counts']['officers_assigned'],'dashboard counts seven Officers assigned to the ASC Office');$this->same(7,$dashboard['counts']['arpa_officers'],'dashboard counts seven scoped ARPA Officers');$this->same($expected,$dashboard['counts']['current_appointments'],'dashboard counts only valid migrated operational appointments');$this->same($dashboard['counts']['arpa_divisions'],$dashboard['counts']['covered_divisions']+$dashboard['counts']['uncovered_divisions'],'coverage reconciles to total divisions');
        $this->pdo->beginTransaction();try{
            $office=(string)$this->pdo->query("SELECT o.id FROM office o JOIN office_type ot ON ot.id=o.office_type_id WHERE ot.system_key='ASC_OFFICE' AND o.linked_location_id='{$this->ascId}'")->fetchColumn();$visible=$this->pdo->query("SELECT o.id,o.dad_number FROM officer o JOIN officer_office_assignment oa ON oa.officer_id=o.id WHERE oa.office_id='{$office}' AND oa.active=1 AND oa.approval_status='APPROVED' AND oa.effective_from<=CURRENT_DATE() AND (oa.effective_to IS NULL OR oa.effective_to>=CURRENT_DATE()) ORDER BY o.dad_number LIMIT 1")->fetch();$unrelated=$this->pdo->query("SELECT o.id,o.dad_number FROM officer o JOIN designation d ON d.id=o.primary_designation_id AND d.system_key='ARPA_OFFICER' WHERE o.approval_status='APPROVED' AND NOT EXISTS(SELECT 1 FROM officer_office_assignment oa WHERE oa.officer_id=o.id AND oa.office_id='{$office}' AND oa.active=1 AND oa.approval_status='APPROVED' AND oa.effective_from<=CURRENT_DATE() AND (oa.effective_to IS NULL OR oa.effective_to>=CURRENT_DATE())) ORDER BY o.dad_number LIMIT 1")->fetch();
            $this->same(true,ScopeService::canAccessOfficer($this->userId,$visible['id']),'current ASC-related officer detail is accessible');$this->same(false,ScopeService::canAccessOfficer($this->userId,$unrelated['id']),'unrelated officer detail is rejected');
            $search=(new DataTableQuery($this->pdo,$definition,new DataTableRequest(['length'=>25,'search'=>['value'=>$unrelated['dad_number']]])))->response();$this->same(0,$search['recordsFiltered'],'search cannot reveal unrelated Officer');$export=iterator_to_array((new DataTableQuery($this->pdo,$definition,new DataTableRequest(['length'=>25])))->exportRows());$this->same(7,count($export),'CSV contains only the seven scoped Officers');
            $candidates=(new ArpaAppointmentCandidateService($this->pdo))->options();$this->same(true,count($candidates)>1,'new appointment candidate discovery remains separate from current directory');$this->same(true,in_array($unrelated['id'],array_column($candidates,'id'),true),'an unrelated current Officer may remain an eligible incoming candidate');
            $definition=DataTableRegistry::definition('officers');$this->same('No officers currently have an approved operational assignment within this Agrarian Service Center.',$definition['emptyMessage'],'scoped empty state is explicit');
            $controller=file_get_contents(BASE_PATH.'/app/Controllers/OfficerController.php');$this->contains("currentOfficerAccess(\$userId,'o.id')",$controller,'Officer autocomplete reuses authoritative Office-assignment scope');
        }finally{$this->pdo->rollBack();}
    }
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function same(mixed $e,mixed $a,string $m):void{$this->assertions++;if($e!==$a)throw new RuntimeException("{$m}: expected ".var_export($e,true).', got '.var_export($a,true));}
    private function contains(string $n,string $h,string $m):void{$this->assertions++;if(!str_contains($h,$n))throw new RuntimeException("{$m}: missing {$n}");}
}
exit((new ScopedOfficerDirectoryTest())->run());
