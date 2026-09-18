<?php
declare(strict_types=1);

use App\Core\{Auth,Database};
use App\Services\{OfficerOfficeAssignmentService,OfficerWorkflowService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class OfficerInitialOfficeReconciliationTest
{
    private PDO $pdo;private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->pdo->beginTransaction();
        try{$this->exercise();}finally{$_SESSION=[];Auth::forgetRequestCache();if($this->pdo->inTransaction())$this->pdo->rollBack();}
        echo "OfficerInitialOfficeReconciliationTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        $admin=(string)$this->value("SELECT id FROM system_user WHERE username='dems.admin' AND enabled=1 AND account_status='ACTIVE' AND approval_status='APPROVED'");
        if($admin==='')throw new RuntimeException('Canonical dems.admin fixture is required.');
        $maker=$this->actor('SYSTEM_ADMIN',null,'reconcile-maker');
        $otherSystem=$this->actor('SYSTEM_ADMIN',null,'reconcile-other-system');
        $national=$this->actor('NATIONAL_ADMIN',null,'reconcile-national');
        $offices=$this->pdo->query("SELECT id FROM office WHERE approval_status='APPROVED' AND operational_status='ACTIVE' ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if(count($offices)!==2)throw new RuntimeException('Two approved active Office fixtures are required.');
        [$officeA,$officeB]=array_map('strval',$offices);$service=new OfficerWorkflowService($this->pdo);

        $this->useContext($admin,'SYSTEM_ADMIN');
        $normal=$this->officer($maker,'SUBMITTED',null);$normalAssignment=$this->assignment($normal,$officeA,'SUBMITTED',true,OfficerOfficeAssignmentService::INITIAL_OFFICER_REASON,$maker);
        $service->approve($normal,$admin);
        $this->same('APPROVED',$this->value('SELECT approval_status FROM officer WHERE id=?',[$normal]),'normal submitted Officer remains approved through the normal path');
        $this->same('APPROVED',$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$normalAssignment]),'normal initial Office assignment is approved normally');

        $missing=$this->officer($maker,'SUBMITTED',null);
        $this->throws(fn()=>$service->approve($missing,$admin),'submitted Officer with no workflow-linked initial Office cannot use normal approval');
        $this->same('SUBMITTED',$this->value('SELECT approval_status FROM officer WHERE id=?',[$missing]),'normal approval failure rolls back Officer status');

        $target=$this->officer($maker,'SUBMITTED',$officeA);$existing=$this->assignment($target,$officeA,'APPROVED',true,'Re Assignment',$maker);
        $candidate=$service->initialOfficeReconciliationCandidate($target,$admin);
        $this->same($existing,$candidate['id']??null,'dems.admin review resolves the one matching approved Primary Office assignment');
        $before=$this->row('SELECT approval_status,active,is_primary,reason,approved_by,approved_at,effective_from,effective_to,version FROM officer_office_assignment WHERE id=?',[$existing]);
        $beforeCount=$this->count('SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id=?',[$target]);
        $service->reconcileInitialOfficeAndApprove($target,$admin);
        $after=$this->row('SELECT approval_status,active,is_primary,reason,approved_by,approved_at,effective_from,effective_to,version FROM officer_office_assignment WHERE id=?',[$existing]);
        $this->same($before,$after,'existing approved Office assignment remains completely unchanged');
        $this->same($beforeCount,$this->count('SELECT COUNT(*) FROM officer_office_assignment WHERE officer_id=?',[$target]),'reconciliation creates no duplicate Office assignment');
        $approved=$this->row('SELECT approval_status,operational_status,primary_office_id FROM officer WHERE id=?',[$target]);
        $this->same('APPROVED',$approved['approval_status'],'reconciled Officer becomes approved');
        $this->same('ACTIVE',$approved['operational_status'],'Officer operational status uses the normal effective-date rule');
        $this->same($officeA,$approved['primary_office_id'],'Officer Primary Office remains synchronized to the existing assignment');
        $audit=$this->row("SELECT actor_user_id,details_json FROM audit_event WHERE target_id=? AND action_key='officer.initial-office-reconciled' ORDER BY id DESC LIMIT 1",[$target]);$details=json_decode((string)$audit['details_json'],true);
        $this->same($admin,$audit['actor_user_id'],'reconciliation audit records the canonical actor');
        $this->same($existing,$details['existing_office_assignment_id']??null,'reconciliation audit records the existing assignment');
        $this->same($officeA,$details['office_id']??null,'reconciliation audit records the Office');
        $this->same('SYSTEM_ADMIN',$details['active_context']['role_code']??null,'reconciliation audit records the active System context');
        $this->same(1,$this->count("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='workflow.approve'",[$target]),'normal Officer approve audit is retained');

        $otherTarget=$this->officer($maker,'SUBMITTED',$officeA);$this->assignment($otherTarget,$officeA,'APPROVED',true,'Re Assignment',$maker);
        $this->useContext($otherSystem,'SYSTEM_ADMIN');$this->same(null,$service->initialOfficeReconciliationCandidate($otherTarget,$otherSystem),'another SYSTEM_ADMIN is not shown the reconciliation option');$this->throws(fn()=>$service->reconcileInitialOfficeAndApprove($otherTarget,$otherSystem),'another SYSTEM_ADMIN cannot use reconciliation');
        $this->useContext($national,'NATIONAL_ADMIN');$this->same(null,$service->initialOfficeReconciliationCandidate($otherTarget,$national),'National Admin is not shown the reconciliation option');$this->throws(fn()=>$service->reconcileInitialOfficeAndApprove($otherTarget,$national),'National Admin cannot use reconciliation');

        $this->useContext($admin,'SYSTEM_ADMIN');
        $zero=$this->officer($maker,'SUBMITTED',$officeA);$this->throws(fn()=>$service->reconcileInitialOfficeAndApprove($zero,$admin),'zero current approved Primary Office assignments is rejected');
        $mismatch=$this->officer($maker,'SUBMITTED',$officeA);$this->assignment($mismatch,$officeB,'APPROVED',true,'Re Assignment',$maker);$this->throws(fn()=>$service->reconcileInitialOfficeAndApprove($mismatch,$admin),'assignment Office mismatch is rejected');
        $multiple=$this->officer($maker,'SUBMITTED',$officeA);$this->assignment($multiple,$officeA,'APPROVED',true,'Re Assignment',$maker);$this->assignment($multiple,$officeB,'APPROVED',true,'Additional Assignment',$maker);$this->throws(fn()=>$service->reconcileInitialOfficeAndApprove($multiple,$admin),'multiple current Primary Office assignments are rejected');

        $withInitial=$this->officer($maker,'SUBMITTED',$officeA);$this->assignment($withInitial,$officeA,'APPROVED',true,'Re Assignment',$maker);$this->assignment($withInitial,$officeB,'SUBMITTED',false,OfficerOfficeAssignmentService::INITIAL_OFFICER_REASON,$maker);$this->throws(fn()=>$service->reconcileInitialOfficeAndApprove($withInitial,$admin),'reconciliation cannot bypass an existing workflow-linked initial Office assignment');

        $view=(string)file_get_contents(BASE_PATH.'/app/Views/officers/show.php');$routes=(string)file_get_contents(BASE_PATH.'/routes/web.php');
        $this->same(true,str_contains($view,'Existing Approved Primary Office')&&str_contains($view,'Use Existing Primary Office and Approve Officer'),'review UI explains and explicitly confirms administrative reconciliation');
        $this->same(true,str_contains($routes,"/hr/officers/{id}/reconcile-initial-office"),'dedicated reconciliation POST route is registered');
    }

    private function officer(string $creator,string $status,?string $primaryOffice):string
    {
        $id=$this->uuid();$dad='REC-'.substr(str_replace('-','',$id),0,16);$officerStatus=(string)$this->value('SELECT id FROM officer_status WHERE active=1 LIMIT 1');
        $this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,arpa_service_permanency,primary_mobile,officer_status_id,primary_office_id,effective_from,operational_status,approval_status,created_by,submitted_by,submitted_at) VALUES(?,?,?,'NOT_PERMANENT_IN_SERVICE','+94761187358',?,?,CURRENT_DATE(),'INACTIVE',?,?,?,NOW())")->execute([$id,$dad,'Reconciliation Test Officer',$officerStatus,$primaryOffice,$status,$creator,$creator]);return $id;
    }

    private function assignment(string $officer,string $office,string $status,bool $primary,string $reason,string $actor):string
    {
        $id=$this->uuid();$approved=$status==='APPROVED';
        $this->pdo->prepare('INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,is_primary,active,reason,approval_status,created_by,submitted_by,submitted_at,approved_by,approved_at) VALUES(?,?,?,CURRENT_DATE(),?,?,?,?,?,?,NOW(),?,IF(?,NOW(),NULL))')->execute([$id,$officer,$office,$primary?1:0,$approved?1:0,$reason,$status,$actor,$actor,$approved?$actor:null,$approved?1:0]);return $id;
    }

    private function actor(string $roleCode,?string $location,string $usernamePrefix):string
    {
        $user=$this->uuid();$username=$usernamePrefix.'-'.substr(str_replace('-','',$user),0,8);$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$user,$username,$username]);$role=(string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$roleCode]);$level=(string)$this->value('SELECT role_level FROM application_role WHERE id=?',[$role]);$assignment=$this->uuid();$this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(?,?,?,CURRENT_DATE(),'APPROVED',1,'Initial Office reconciliation test')")->execute([$assignment,$user,$role]);
        if($level!=='SYSTEM'){$scope=$this->uuid();$type=$level==='NATIONAL'?'NATIONAL':$level;$mode=$level==='NATIONAL'?'NATIONAL':'EXACT';$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(?,?,?,?,?,?,CURRENT_DATE(),'APPROVED',1,'Initial Office reconciliation test')")->execute([$scope,$user,$assignment,$type,$mode,$location]);}
        return $user;
    }

    private function useContext(string $user,string $roleCode):void
    {
        $_SESSION=['user_id'=>$user,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();$contexts=(new UserContextService($this->pdo))->availableContexts($user);$context=array_values(array_filter($contexts,fn(array $row)=>(string)$row['role_code']===$roleCode))[0]??null;if(!$context)throw new RuntimeException("Missing {$roleCode} context.");(new UserContextService($this->pdo))->select($user,(string)$context['role_assignment_id'],$context['scope_assignment_id']===null?null:(string)$context['scope_assignment_id']);Auth::forgetRequestCache();
    }

    private function row(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function count(string $sql,array $params=[]):int{return (int)$this->value($sql,$params);}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
}

exit((new OfficerInitialOfficeReconciliationTest())->run());
