<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\ScopeService;

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentAuthorizationTest
{
    private PDO $pdo;
    private int $assertions=0;
    private const MATRIX=[
        'ASC_SUBJECT_OFFICER'=>['arpa.appointment.asc-verify','arpa.appointment.create','arpa.appointment.data-issue.correct','arpa.appointment.edit','arpa.appointment.end','arpa.appointment.submit','arpa.appointment.transfer','arpa.appointment.view','arpa.subject.create','arpa.subject.end'],
        'ASC_ADMIN'=>['arpa.appointment.asc-approve','arpa.appointment.reject','arpa.appointment.return','arpa.appointment.view'],
        'ASC_VIEWER'=>['arpa.appointment.view'],
        'DISTRICT_SUBJECT_OFFICER'=>['arpa.appointment.district-review-edit','arpa.appointment.district-verify','arpa.appointment.return','arpa.appointment.view'],
        'DISTRICT_ADMIN'=>['arpa.appointment.district-approve','arpa.appointment.reject','arpa.appointment.return','arpa.appointment.view'],
        'DISTRICT_VIEWER'=>['arpa.appointment.view'],
        'NATIONAL_SUBJECT_OFFICER'=>['arpa.appointment.national-review-edit','arpa.appointment.national-verify','arpa.appointment.return','arpa.appointment.view'],
        'NATIONAL_ADMIN'=>['arpa.appointment.national-approve','arpa.appointment.reject','arpa.appointment.return','arpa.appointment.view'],
        'NATIONAL_VIEWER'=>['arpa.appointment.view'],
    ];

    public function run():int
    {
        $this->pdo=Database::pdo();
        foreach(self::MATRIX as $role=>$expected){sort($expected);$actual=$this->permissions($role);$this->same($expected,$actual,"{$role} exact ARPA workflow matrix");}
        $this->same(0,$this->scalar("SELECT COUNT(*) FROM application_role_permission rp JOIN application_permission p ON p.id=rp.permission_id JOIN application_role r ON r.id=rp.role_id WHERE p.permission_key='arpa.subject.manage' AND r.role_code IN ('ASC_SUBJECT_OFFICER','ASC_ADMIN','ASC_VIEWER','DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN','DISTRICT_VIEWER','NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN','NATIONAL_VIEWER')"),'obsolete ASC subject-master permission is not mapped');
        $this->same(0,$this->scalar("SELECT COUNT(*) FROM application_role_permission rp JOIN application_permission p ON p.id=rp.permission_id JOIN application_role r ON r.id=rp.role_id WHERE p.permission_key='arpa.appointment.data-issue.correct' AND r.role_code<>'ASC_SUBJECT_OFFICER'"),'direct data correction is not mapped to administrators, viewers, or broader geographic roles');
        $this->same(1,$this->scalar("SELECT COUNT(*) FROM application_permission WHERE permission_key='arpa.subject.manage'"),'obsolete permission master is retained for reference integrity');
        $this->same(0,$this->scalar("SELECT COUNT(*) FROM system_user WHERE identity_type='HISTORICAL' AND enabled=1"),'historical users remain disabled');
        $this->same(0,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_appointment_source_reference sr JOIN arpa_division_appointment_request r ON r.id=sr.target_appointment_request_id WHERE r.record_origin<>'LEGACY_IMPORT'"),'legacy source references never point to native appointment requests');
        $this->same(0,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_appointment_source_reference sr JOIN arpa_subject_assignment_request r ON r.id=sr.target_subject_request_id WHERE r.record_origin<>'LEGACY_IMPORT'"),'legacy source references never point to native subject requests');
        $controller=file_get_contents(dirname(__DIR__).'/app/Controllers/ArpaAppointmentController.php');
        $this->same(true,str_contains($controller,'Auth::requirePermission($permission)')&&str_contains($controller,'assertArpaStageScope'),'workflow endpoints enforce permission and scope server-side');
        $this->testScopeEnforcement();
        echo "ArpaAppointmentAuthorizationTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function permissions(string $role):array
    {
        $s=$this->pdo->prepare("SELECT p.permission_key FROM application_role r JOIN application_role_permission rp ON rp.role_id=r.id JOIN application_permission p ON p.id=rp.permission_id WHERE r.role_code=? AND (p.permission_key LIKE 'arpa.appointment.%' OR p.permission_key IN('arpa.subject.create','arpa.subject.end','arpa.subject.manage')) ORDER BY p.permission_key");$s->execute([$role]);return array_column($s->fetchAll(),'permission_key');
    }
    private function testScopeEnforcement():void
    {
        $this->pdo->beginTransaction();
        try{
            $asc=(string)$this->pdo->query("SELECT l.id FROM location l JOIN location_type t ON t.id=l.location_type_id WHERE t.system_key='ASC' LIMIT 1")->fetchColumn();
            $districtStmt=$this->pdo->prepare("WITH RECURSIVE parents(id) AS (SELECT ? UNION DISTINCT SELECT lr.parent_location_id FROM location_relationship lr JOIN parents p ON p.id=lr.child_location_id WHERE lr.active=1 AND lr.approval_status='APPROVED') SELECT l.id FROM parents p JOIN location l ON l.id=p.id JOIN location_type t ON t.id=l.location_type_id WHERE t.system_key='DISTRICT' LIMIT 1");$districtStmt->execute([$asc]);$district=(string)$districtStmt->fetchColumn();
            if($asc===''||$district==='')throw new RuntimeException('ASC and District fixtures are required.');
            $ascUser='00000000-0000-4000-8000-000000000201';$districtUser='00000000-0000-4000-8000-000000000202';$nationalUser='00000000-0000-4000-8000-000000000203';
            $ascRole='00000000-0000-4000-8000-000000000211';$districtRole='00000000-0000-4000-8000-000000000212';$nationalRole='00000000-0000-4000-8000-000000000213';
            $this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,account_status,enabled) VALUES(?,'STAFF',?,'ACTIVE',1),(?,'STAFF',?,'ACTIVE',1),(?,'STAFF',?,'ACTIVE',1)")->execute([$ascUser,'scope-asc-test',$districtUser,'scope-district-test',$nationalUser,'scope-national-test']);
            $role=$this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active) SELECT ?,?,id,CURRENT_DATE(),'APPROVED',1 FROM application_role WHERE role_code=?");
            $role->execute([$ascRole,$ascUser,'ASC_SUBJECT_OFFICER']);$role->execute([$districtRole,$districtUser,'DISTRICT_SUBJECT_OFFICER']);$role->execute([$nationalRole,$nationalUser,'NATIONAL_SUBJECT_OFFICER']);
            $scope=$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active) VALUES(UUID(),?,?,?,?,?,CURRENT_DATE(),'APPROVED',1)");
            $scope->execute([$ascUser,$ascRole,'ASC','EXACT',$asc]);$scope->execute([$districtUser,$districtRole,'DISTRICT','INCLUDE_CHILDREN',$district]);
            $this->same(true,ScopeService::canAccessCurrentArpaStage($ascUser,'ASC',$asc),'ASC scope permits its ASC stage');
            $this->same(false,ScopeService::canAccessCurrentArpaStage($ascUser,'DISTRICT',$asc),'ASC scope cannot perform District stage');
            $this->same(true,ScopeService::canAccessCurrentArpaStage($districtUser,'DISTRICT',$asc),'District scope permits child ASC');
            $this->same(false,ScopeService::canAccessCurrentArpaStage($districtUser,'NATIONAL',$asc),'District scope cannot perform National stage');
            $this->same(false,ScopeService::canAccessCurrentArpaStage($nationalUser,'NATIONAL',$asc),'National role identity without NATIONAL scope is denied');
            $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active) VALUES(UUID(),?,?,'NATIONAL','NATIONAL',NULL,CURRENT_DATE(),'APPROVED',1)")->execute([$nationalUser,$nationalRole]);
            $this->same(true,ScopeService::canAccessCurrentArpaStage($nationalUser,'NATIONAL',$asc),'NATIONAL scope permits National stage');
        }finally{$this->pdo->rollBack();}
    }
    private function scalar(string $sql):int{return (int)$this->pdo->query($sql)->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.json_encode($expected).', got '.json_encode($actual));}
}

exit((new ArpaAppointmentAuthorizationTest())->run());
