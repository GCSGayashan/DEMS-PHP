<?php
declare(strict_types=1);

use App\Core\{Database,NumberService,ScopeService};
use App\Services\{OfficeStructureService,OfficerOfficeAssignmentService};

require dirname(__DIR__).'/bootstrap.php';

final class OfficerOfficeAssignmentTest
{
    private PDO $pdo;private int $assertions=0;
    public function run():int
    {
        $this->pdo=Database::pdo();$this->testStructure();$this->testAssignments();$this->testSafety();echo "OfficerOfficeAssignmentTest: {$this->assertions} assertions passed.\n";return 0;
    }
    private function testStructure():void
    {
        $r=(new OfficeStructureService($this->pdo))->inspect();$this->same(1,$r['head_offices'],'one Head Office');$this->same(25,$r['district_offices'],'one Office for each District');$this->same(566,$r['asc_offices'],'one Office for each ASC');$this->same(0,$r['would_create'],'structure rerun is idempotent');
        $this->same(592,(int)$this->pdo->query('SELECT COUNT(DISTINCT dad_number) FROM office')->fetchColumn(),'all Office DAD numbers are unique');$this->same(592,(int)$this->pdo->query("SELECT COUNT(*) FROM number_allocation a JOIN number_category c ON c.id=a.category_id JOIN office o ON o.dad_number=a.allocated_number WHERE c.category_key='OFFICE'")->fetchColumn(),'every Office number is auditable');
        $this->same(0,(int)$this->pdo->query("SELECT COUNT(*) FROM location l JOIN location_type lt ON lt.id=l.location_type_id LEFT JOIN office o ON o.linked_location_id=l.id WHERE lt.system_key IN('DISTRICT','ASC') AND o.id IS NULL")->fetchColumn(),'no operational location lacks its Office');
    }
    private function testAssignments():void
    {
        $this->pdo->beginTransaction();try{
            $actor=(string)$this->pdo->query("SELECT su.id FROM system_user su JOIN user_account_role ur ON ur.user_id=su.id JOIN application_role r ON r.id=ur.role_id WHERE r.role_code='SYSTEM_ADMIN' AND ur.active=1 LIMIT 1")->fetchColumn();
            $checker=$this->uuid();$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,account_status,enabled) VALUES(?,'STAFF',?,'ACTIVE',1)")->execute([$checker,'office-checker-test']);$systemRole=(string)$this->pdo->query("SELECT id FROM application_role WHERE role_code='SYSTEM_ADMIN'")->fetchColumn();$this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(UUID(),?,?,CURRENT_DATE(),'APPROVED',1,'Office assignment test checker')")->execute([$checker,$systemRole]);
            $officer=(string)$this->pdo->query("SELECT id FROM officer ORDER BY dad_number LIMIT 1")->fetchColumn();$existing=(int)$this->pdo->query("SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id='{$officer}' AND approval_status='APPROVED'")->fetchColumn();$offices=$this->pdo->query("SELECT o.id FROM office o JOIN office_type ot ON ot.id=o.office_type_id WHERE ot.system_key='ASC_OFFICE' ORDER BY o.dad_number LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);$service=new OfficerOfficeAssignmentService($this->pdo);$today=date('Y-m-d');
            $first=$service->create(['officer_id'=>$officer,'office_id'=>$offices[0],'effective_from'=>$today,'is_primary'=>1,'reason'=>'Test assignment'],$actor);$service->submit($first,$actor);$this->throws(fn()=>$service->approve($first,$actor),'maker cannot approve');$service->approve($first,$checker);
            $this->same(true,$service->hasCurrentAscOfficeAssignment($officer,(string)$this->value("SELECT linked_location_id FROM office WHERE id='{$offices[0]}'"),$today),'approved ASC assignment is current');
            $second=$service->create(['officer_id'=>$officer,'office_id'=>$offices[1],'effective_from'=>$today,'reason'=>'Concurrent Office'],$actor);$service->submit($second,$actor);$service->approve($second,$checker);$this->same($existing+2,(int)$this->pdo->query("SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id='{$officer}' AND approval_status='APPROVED'")->fetchColumn(),'one Officer may have multiple Offices');
            $service->setPrimary($second,$checker);$this->same(1,(int)$this->pdo->query("SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id='{$officer}' AND is_primary=1 AND approval_status='APPROVED'")->fetchColumn(),'only one current primary Office');
            $service->end($first,$today,'Test end',$checker);$this->same(1,(int)$this->pdo->query("SELECT COUNT(*) FROM officer_office_assignment WHERE id='{$first}' AND effective_to='{$today}'")->fetchColumn(),'ending retains assignment history');$this->same(true,(int)$this->pdo->query("SELECT COUNT(*) FROM officer_office_assignment_audit WHERE assignment_id='{$first}'")->fetchColumn()>=4,'assignment changes have append-only audit');
        }finally{$this->pdo->rollBack();}
    }
    private function testSafety():void{$this->same(5192,(int)$this->pdo->query("SELECT COUNT(*) FROM officer_office_assignment WHERE record_origin='LEGACY_CURRENT_STATE_BACKFILL'")->fetchColumn(),'approved current-state backfill assignments are retained');$this->same(0,(int)$this->pdo->query("SELECT COUNT(*) FROM arpa_division_appointment WHERE record_origin='NATIVE'")->fetchColumn(),'legacy import creates no native appointments');}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}private function value(string $sql):mixed{return $this->pdo->query($sql)->fetchColumn();}
    private function same(mixed $e,mixed $a,string $m):void{$this->assertions++;if($e!==$a)throw new RuntimeException("{$m}: expected ".var_export($e,true).', got '.var_export($a,true));}private function throws(callable $fn,string $m):void{$this->assertions++;try{$fn();}catch(Throwable){return;}throw new RuntimeException($m);}
}
exit((new OfficerOfficeAssignmentTest())->run());
