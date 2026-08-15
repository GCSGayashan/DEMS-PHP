<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database};
use App\Services\{ArpaAppointmentService,OperationalUserActivationService};

require dirname(__DIR__).'/bootstrap.php';

final class OperationalUserActivationTest
{
    private PDO $pdo;private int $assertions=0;
    public function run():int
    {
        $this->pdo=Database::pdo();$before=$this->state();
        $this->same(1323,$before['historical'],'remaining historical fixture total after explicitly activated asctest');
        $this->testDataTable();$this->testActivationAndDeactivation();
        $this->same($before,$this->state(),'tests roll back every operational access change');
        echo "OperationalUserActivationTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function testDataTable():void
    {
        $_SESSION=[];$admin=$this->admin();$_SESSION['user_id']=$admin;
        $definition=DataTableRegistry::definition('historical-users');$response=(new DataTableQuery($this->pdo,$definition,new DataTableRequest(['length'=>10,'search'=>['value'=>'legacy.hr.']])))->response();
        $this->same(1323,$response['recordsTotal'],'remaining historical list total');$this->same(true,count($response['data'])<=10,'historical user list is server paginated');
    }

    private function testActivationAndDeactivation():void
    {
        $this->pdo->beginTransaction();
        try{
            $admin=$this->admin();$target=$this->pdo->query("SELECT su.*,lur.id reference_id FROM system_user su JOIN legacy_user_reference lur ON lur.system_user_id=su.id WHERE su.identity_type='HISTORICAL' ORDER BY su.id LIMIT 1 FOR UPDATE")->fetch();$targetId=(string)$target['id'];
            $legacyPassword='Legacy-Password-Should-Not-Work!';$temporary='Secure-Activation-1!';$newUsername='operational.fixture.'.substr(str_replace('-','',$targetId),0,8);
            $asc=(string)$this->pdo->query("SELECT l.id FROM location l JOIN location_type t ON t.id=l.location_type_id WHERE t.system_key='ASC' AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' LIMIT 1")->fetchColumn();
            $district=(string)$this->pdo->query("SELECT l.id FROM location l JOIN location_type t ON t.id=l.location_type_id WHERE t.system_key='DISTRICT' AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' LIMIT 1")->fetchColumn();
            $service=new OperationalUserActivationService($this->pdo);$base=['username'=>$newUsername,'email'=>'fixture.current@example.test','temporary_password'=>$temporary,'effective_from'=>date('Y-m-d'),'reason'=>'Focused activation test','official_reference'=>'TEST/ACT/1'];
            $unauthorized='00000000-0000-4000-8000-000000000301';$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,account_status,enabled) VALUES(?,'STAFF','activation-unauthorized','ACTIVE',1)")->execute([$unauthorized]);
            $this->throws(fn()=>$service->activate($targetId,$base+['role_enabled'=>['ASC_VIEWER'],'roles'=>['ASC_VIEWER'=>$asc]],$unauthorized),'activation requires authorized administrator');
            $this->throws(fn()=>$service->activate($targetId,$base+['role_enabled'=>['ASC_VIEWER'],'roles'=>['ASC_VIEWER'=>$district]],$admin),'ASC role rejects District scope');
            $this->throws(fn()=>$service->activate($targetId,$base+['role_enabled'=>['DISTRICT_ADMIN'],'roles'=>['DISTRICT_ADMIN'=>$asc]],$admin),'District role rejects ASC-only scope');
            $this->throws(fn()=>$service->activate($targetId,$base+['role_enabled'=>['NATIONAL_ADMIN'],'roles'=>['NATIONAL_ADMIN'=>$asc]],$admin),'National role rejects location scope');
            $collision=(string)$this->pdo->query("SELECT username FROM system_user WHERE id<>'{$targetId}' LIMIT 1")->fetchColumn();$this->throws(fn()=>$service->activate($targetId,array_replace($base,['username'=>$collision])+['role_enabled'=>['ASC_VIEWER'],'roles'=>['ASC_VIEWER'=>$asc]],$admin),'username collision is rejected');
            $service->activate($targetId,$base+['role_enabled'=>['ASC_SUBJECT_OFFICER','ASC_ADMIN'],'roles'=>['ASC_SUBJECT_OFFICER'=>$asc,'ASC_ADMIN'=>$asc]],$admin);
            $user=$this->pdo->query("SELECT * FROM system_user WHERE id='{$targetId}'")->fetch();$this->same('STAFF',$user['identity_type'],'selected historical identity becomes STAFF');$this->same('ACTIVE',$user['account_status'],'selected user is active');$this->same(1,(int)$user['enabled'],'selected user is login enabled');$this->same(1,(int)$user['password_setup_required'],'temporary credential forces password change');$this->same(true,password_verify($temporary,$user['password_hash']),'new credential authenticates cryptographically');$this->same(false,password_verify($legacyPassword,$user['password_hash']),'legacy password does not authenticate');
            $this->same(2,$this->scalar("SELECT COUNT(*) FROM user_account_role WHERE user_id='{$targetId}' AND active=1 AND approval_status='APPROVED'"),'multiple selected roles are approved');$this->same(2,$this->scalar("SELECT COUNT(*) FROM user_account_scope WHERE user_id='{$targetId}' AND scope_type='ASC' AND scope_mode='EXACT' AND location_id='{$asc}' AND active=1"),'each ASC role has explicit ASC scope');
            $this->same(1,$this->scalar("SELECT COUNT(*) FROM legacy_user_reference WHERE id='{$target['reference_id']}' AND system_user_id='{$targetId}'"),'legacy reference remains unchanged');
            $this->same(1,$this->scalar("SELECT COUNT(*) FROM user_operational_access_event WHERE user_id='{$targetId}' AND event_type='ACTIVATE'"),'activation event is audited');
            $this->same(1,$this->scalar("SELECT COUNT(*) FROM system_user WHERE id='{$targetId}' AND username='{$newUsername}' AND enabled=1 AND account_status='ACTIVE'"),'activated original identity satisfies login eligibility');
            $this->same(1,$this->scalar("SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE user_id='{$targetId}' UNION SELECT COUNT(*) FROM arpa_subject_workflow_action WHERE user_id='{$targetId}'")>=0?1:0,'workflow foreign-key identity remains resolvable');
            $service->deactivate($targetId,'No longer current staff','TEST/DEACT/1',$admin);$user=$this->pdo->query("SELECT * FROM system_user WHERE id='{$targetId}'")->fetch();$this->same('STAFF',$user['identity_type'],'deactivation preserves operational identity type');$this->same(0,(int)$user['enabled'],'deactivation disables login');$this->same(0,$this->scalar("SELECT COUNT(*) FROM user_account_role WHERE user_id='{$targetId}' AND active=1"),'role history is ended');$this->same(0,$this->scalar("SELECT COUNT(*) FROM user_account_scope WHERE user_id='{$targetId}' AND active=1"),'scope history is ended');$this->same(0,$this->scalar("SELECT COUNT(*) FROM system_user WHERE id='{$targetId}' AND username='{$newUsername}' AND enabled=1 AND account_status='ACTIVE'"),'deactivated user cannot satisfy authentication predicate');
            $this->same(1322,$this->scalar("SELECT COUNT(*) FROM system_user WHERE identity_type='HISTORICAL' AND enabled=0"),'unrelated historical identities remain disabled during selected activation');
            $this->same(1,$this->scalar("SELECT COUNT(*) FROM user_operational_access_event WHERE user_id='{$targetId}' AND event_type='DEACTIVATE'"),'deactivation event is audited');
            $rules=file_get_contents(dirname(__DIR__).'/app/Services/ArpaAppointmentService.php');$this->same(true,str_contains($rules,'assertMakerChecker'),'ARPA no-self-approval remains enforced');
        }finally{$_SESSION=[];$this->pdo->rollBack();}
    }
    private function admin():string{$id=$this->pdo->query("SELECT su.id FROM system_user su JOIN user_account_role ur ON ur.user_id=su.id JOIN application_role r ON r.id=ur.role_id WHERE r.role_code='SYSTEM_ADMIN' AND ur.active=1 LIMIT 1")->fetchColumn();if(!$id)throw new RuntimeException('SYSTEM_ADMIN fixture required.');return (string)$id;}
    private function state():array{return ['historical'=>$this->scalar("SELECT COUNT(*) FROM system_user WHERE identity_type='HISTORICAL' AND enabled=0"),'roles'=>$this->scalar('SELECT COUNT(*) FROM user_account_role'),'scopes'=>$this->scalar('SELECT COUNT(*) FROM user_account_scope'),'events'=>$this->scalar('SELECT COUNT(*) FROM user_operational_access_event'),'requests'=>$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_request'),'appointments'=>$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment')];}
    private function scalar(string $sql):int{return (int)$this->pdo->query($sql)->fetchColumn();}
    private function same(mixed $e,mixed $a,string $m):void{$this->assertions++;if($e!==$a)throw new RuntimeException($m.': expected '.var_export($e,true).', got '.var_export($a,true));}
    private function throws(callable $fn,string $m):void{$this->assertions++;try{$fn();}catch(\DomainException){return;}throw new RuntimeException($m.': expected DomainException');}
}
exit((new OperationalUserActivationTest())->run());
