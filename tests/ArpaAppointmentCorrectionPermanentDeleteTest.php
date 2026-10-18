<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use App\Core\{Auth,Database};
use App\Services\{ArpaAppointmentCorrectionAmendmentFeature,ArpaAppointmentCorrectionPermanentDeleteService,ArpaCorrectionPermanentDeletePolicy,UserContextService};

final class ArpaAppointmentCorrectionPermanentDeleteTest
{
    private const ADMIN='00000000-0000-4000-8000-000000000001';
    private const ASC_USER='00000000-0000-4000-8000-000000000002';
    private const DISTRICT_USER='00000000-0000-4000-8000-000000000003';
    private const NATIONAL_USER='00000000-0000-4000-8000-000000000004';
    private const OFFICER='10000000-0000-4000-8000-000000000001';
    private const ASC='20000000-0000-4000-8000-000000000001';
    private const REQUEST='30000000-0000-4000-8000-000000000001';
    private const APPOINTMENT='40000000-0000-4000-8000-000000000001';
    private const SAFE='50000000-0000-4000-8000-000000000001';
    private const ROLLBACK='50000000-0000-4000-8000-000000000002';
    private const MUTATING='50000000-0000-4000-8000-000000000003';
    private const CHANGED='50000000-0000-4000-8000-000000000004';
    private const ACTIVE_SOURCE='50000000-0000-4000-8000-000000000005';
    private const COMPLETED_SOURCE='50000000-0000-4000-8000-000000000006';
    private const APPLIED='50000000-0000-4000-8000-000000000007';
    private const PREDECESSOR='50000000-0000-4000-8000-000000000008';
    private const SUCCESSOR='50000000-0000-4000-8000-000000000009';
    private const CLOSURE_DEPENDENT='50000000-0000-4000-8000-000000000010';

    private PDO $server;
    private PDO $pdo;
    private string $databaseName;
    private ReflectionProperty $databaseProperty;
    private ?PDO $previousDatabase=null;
    private int $assertions=0;

    public function run():int
    {
        $config=config('database');$this->databaseName='dems_test_arpa_corr_delete_'.bin2hex(random_bytes(4));
        if(preg_match('/^dems_test_arpa_corr_delete_[a-f0-9]{8}$/D',$this->databaseName)!==1)throw new RuntimeException('Unsafe isolated test database name.');
        $this->server=new PDO(sprintf('mysql:host=%s;port=%s;charset=%s',$config['host'],$config['port'],$config['charset']),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
        try{
            $this->server->exec('CREATE DATABASE `'.$this->databaseName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $this->pdo=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',$config['host'],$config['port'],$this->databaseName,$config['charset']),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
            $this->fixtureSchema();$this->fixtureAccess();$this->installIsolatedDatabase();$this->useContext(self::ADMIN);

            $this->same(false,ArpaAppointmentCorrectionAmendmentFeature::enabled(),'correction amendment feature remains OFF');
            $legacy=(new ArpaAppointmentCorrectionPermanentDeleteService($this->pdo))->eligibility(self::SAFE,self::ADMIN);
            $this->same('AUDIT_SCHEMA_UNAVAILABLE',$legacy['code'],'schema-absent application fails closed without querying missing amendment columns');

            $this->pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations/062_arpa_appointment_correction_amendment_foundation.sql'));
            $this->pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations/063_arpa_data_correction_permanent_deletion_audit.sql'));
            $this->fixtureCorrections();$this->migrationAssertions();$this->authorizationAssertions();$this->eligibilityAssertions();$this->deletionAssertions();$this->interfaceAssertions();
        }finally{
            $_SESSION=[];Auth::forgetRequestCache();
            if(isset($this->databaseProperty))$this->databaseProperty->setValue(null,$this->previousDatabase);
            if(isset($this->server))$this->server->exec('DROP DATABASE IF EXISTS `'.$this->databaseName.'`');
        }
        echo "ArpaAppointmentCorrectionPermanentDeleteTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function authorizationAssertions():void
    {
        $service=new ArpaAppointmentCorrectionPermanentDeleteService($this->pdo);
        $this->useContext(self::ADMIN);$this->same(true,ArpaCorrectionPermanentDeletePolicy::allowed(),'canonical administrator with System context and permission is authorized');
        foreach([self::ASC_USER,self::DISTRICT_USER,self::NATIONAL_USER] as $user){
            $this->useContext($user);$this->same(false,ArpaCorrectionPermanentDeletePolicy::allowed(),'non-System administrator role is denied');
            $this->throwsDomain(fn()=>$service->eligibility(self::SAFE,$user),'service independently denies non-administrator actor');
        }
        $this->useContext(self::ADMIN);
    }

    private function eligibilityAssertions():void
    {
        $service=new ArpaAppointmentCorrectionPermanentDeleteService($this->pdo);
        $this->same('ELIGIBLE',$service->eligibility(self::SAFE,self::ADMIN)['code'],'standalone no-change historical decision is eligible');
        $this->same('CANONICAL_EFFECTS_POSSIBLE',$service->eligibility(self::MUTATING,self::ADMIN)['code'],'mutating correction action is blocked');
        $this->same('EVIDENCE_CHANGED',$service->eligibility(self::CHANGED,self::ADMIN)['code'],'different before/after evidence is blocked');
        $this->same('AMENDMENT_REFERENCE',$service->eligibility(self::ACTIVE_SOURCE,self::ADMIN)['code'],'active amendment source is blocked');
        $this->same('AMENDMENT_REFERENCE',$service->eligibility(self::COMPLETED_SOURCE,self::ADMIN)['code'],'completed amendment source is blocked');
        $this->same('APPLIED_AMENDMENT',$service->eligibility(self::APPLIED,self::ADMIN)['code'],'applied amendment correction is blocked');
        $this->same('HAS_SUCCESSOR',$service->eligibility(self::PREDECESSOR,self::ADMIN)['code'],'predecessor with successor is blocked');
        $this->same('SUPERSEDES_PREDECESSOR',$service->eligibility(self::SUCCESSOR,self::ADMIN)['code'],'successor decision is blocked');
        $this->same('CANONICAL_CLOSURE_REFERENCE',$service->eligibility(self::CLOSURE_DEPENDENT,self::ADMIN)['code'],'canonical closure dependency is blocked');
    }

    private function deletionAssertions():void
    {
        $service=new ArpaAppointmentCorrectionPermanentDeleteService($this->pdo);
        $this->throwsDomain(fn()=>$service->permanentlyDelete(self::SAFE,'',self::SAFE,self::ADMIN),'deletion reason is mandatory');
        $this->throwsDomain(fn()=>$service->permanentlyDelete(self::SAFE,'Incorrect review','wrong-id',self::ADMIN),'exact correction confirmation is mandatory');
        $operationalBefore=$this->operationalState();
        $result=$service->permanentlyDelete(self::SAFE,'Incorrect duplicate review decision',self::SAFE,self::ADMIN);
        $this->same(self::SAFE,$result['correction_id'],'eligible correction is deleted');
        $this->same(0,$this->count('SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE id=?',[self::SAFE]),'only correction ledger row is removed');
        $audit=$this->row('SELECT * FROM arpa_appointment_data_correction_deletion_audit WHERE correction_id=?',[self::SAFE]);
        $snapshot=json_decode((string)$audit['correction_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        $this->same(self::SAFE,$snapshot['id'],'independent audit contains complete correction snapshot');
        $this->same(self::OFFICER,$audit['officer_id'],'audit records officer identity');
        $this->same(self::APPOINTMENT,$audit['appointment_id'],'audit records appointment identity');
        $this->same(self::ADMIN,$audit['deleted_by'],'audit records administrator identity');
        $this->same('Incorrect duplicate review decision',$audit['deletion_reason'],'audit records mandatory deletion reason');
        $this->same($operationalBefore,$this->operationalState(),'appointment, request and closure state remain unchanged');
        $this->same(1,$this->count("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='arpa.appointment.data-issue.correct'",[self::SAFE]),'existing independent correction audit event is not cascaded');
        $this->throwsDomain(fn()=>$service->permanentlyDelete(self::SAFE,'Retry',self::SAFE,self::ADMIN),'duplicate deletion request is handled safely');
        $this->same(1,$this->count('SELECT COUNT(*) FROM arpa_appointment_data_correction_deletion_audit WHERE correction_id=?',[self::SAFE]),'retry cannot duplicate deletion audit');

        $this->pdo->prepare('INSERT INTO arpa_appointment_data_correction_deletion_audit(correction_id,officer_id,appointment_id,request_id,asc_location_id,original_correction_action,original_resolution_status,correction_snapshot_json,deleted_by,deletion_reason) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([self::ROLLBACK,self::OFFICER,self::APPOINTMENT,self::REQUEST,self::ASC,'KEEP_AS_HISTORICAL_EXCEPTION','KEPT_HISTORICAL_EXCEPTION','{}',self::ADMIN,'Injected audit failure fixture']);
        $this->throwsThrowable(fn()=>$service->permanentlyDelete(self::ROLLBACK,'Should roll back',self::ROLLBACK,self::ADMIN),'audit insertion failure propagates');
        $this->same(1,$this->count('SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE id=?',[self::ROLLBACK]),'audit insertion failure rolls back correction deletion');
    }

    private function migrationAssertions():void
    {
        $this->same(1,$this->count("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='arpa_appointment_data_correction_deletion_audit'"),'independent deletion audit table exists');
        $this->same(0,$this->count("SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='arpa_appointment_data_correction_deletion_audit' AND referenced_table_name='arpa_appointment_data_correction'"),'deletion audit has no FK back to deleted correction');
        $this->same(1,$this->count("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='arpa_appointment_data_correction_deletion_audit' AND index_name='uq_arpa_data_correction_deletion_correction' AND non_unique=0"),'one deletion audit is enforced per correction');
        $migration=(string)file_get_contents(BASE_PATH.'/database/migrations/063_arpa_data_correction_permanent_deletion_audit.sql');
        $this->same(false,str_contains(strtoupper($migration),'ON DELETE CASCADE'),'deletion audit migration contains no cascading deletion');
    }

    private function interfaceAssertions():void
    {
        $routes=(string)file_get_contents(BASE_PATH.'/routes/web.php');$controller=(string)file_get_contents(BASE_PATH.'/app/Controllers/ArpaAppointmentController.php');$view=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/issues/correction_detail.php');
        $this->same(true,str_contains($routes,"post('/hr/arpa-appointments/issues/corrections/{id}/permanent-delete'")&&str_contains($routes,'ArpaCorrectionPermanentDeletePolicy::allowed()'),'POST route has direct administrator middleware');
        $this->same(false,str_contains($routes,"get('/hr/arpa-appointments/issues/corrections/{id}/permanent-delete'"),'no GET deletion route exists');
        $method=substr($controller,(int)strpos($controller,'public function permanentlyDeleteDataIssueCorrection('));
        $this->same(true,strpos($method,'ArpaCorrectionPermanentDeletePolicy::allowed()')<strpos($method,'Csrf::validate()'),'controller denies unauthorized actors before mutation handling');
        $this->same(true,strpos($method,'Csrf::validate()')<strpos($method,'->permanentlyDelete('),'controller validates CSRF before service mutation');
        $this->same(true,str_contains($view,"name=\"deletion_reason\"")&&str_contains($view,"name=\"confirmation\"")&&substr_count($view,'required')>=2,'administrator form requires reason and exact confirmation');
        $this->same(true,str_contains($view,"!empty(\$permanentDeleteUi['authorized'])")&&str_contains($view,"empty(\$permanentDeleteUi['eligible'])"),'delete UI is administrator-only and displays blocked eligibility');
        $this->same(true,str_contains($view,'Csrf::field()'),'delete form includes CSRF token');
        $this->same(true,str_contains($routes,"get('/hr/arpa-appointments/issues/corrections/{id}'"),'existing read-only View route is preserved');
    }

    private function fixtureSchema():void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE system_user(id CHAR(36) PRIMARY KEY,username VARCHAR(100) NOT NULL UNIQUE,display_name VARCHAR(150),enabled TINYINT NOT NULL DEFAULT 1,account_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE location(id CHAR(36) PRIMARY KEY,dad_number VARCHAR(50),name_en VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE application_role(id CHAR(36) PRIMARY KEY,role_code VARCHAR(80) NOT NULL,role_name VARCHAR(150) NOT NULL,role_level VARCHAR(30) NOT NULL,active TINYINT NOT NULL DEFAULT 1,approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE application_permission(id CHAR(36) PRIMARY KEY,permission_key VARCHAR(150) NOT NULL,active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE application_role_permission(role_id CHAR(36) NOT NULL,permission_id CHAR(36) NOT NULL,PRIMARY KEY(role_id,permission_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE user_account_role(id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,role_id CHAR(36) NOT NULL,active TINYINT NOT NULL DEFAULT 1,approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',effective_from DATE NOT NULL,effective_to DATE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE user_account_scope(id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,role_assignment_id CHAR(36) NOT NULL,scope_type VARCHAR(30) NOT NULL,scope_mode VARCHAR(30) NOT NULL,location_id CHAR(36) NULL,active TINYINT NOT NULL DEFAULT 1,approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',effective_from DATE NOT NULL,effective_to DATE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE officer(id CHAR(36) PRIMARY KEY,dad_number VARCHAR(50),name_with_initials VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE arpa_division_appointment_request(id CHAR(36) PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE arpa_division_appointment(id CHAR(36) PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE arpa_division_appointment_closure(id CHAR(36) PRIMARY KEY,appointment_id CHAR(36) NOT NULL,data_correction_id CHAR(36) NULL,effective_to DATE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE arpa_appointment_data_correction(
 id CHAR(36) PRIMARY KEY,issue_row_key VARCHAR(255) NOT NULL,issue_type VARCHAR(100) NOT NULL,officer_id CHAR(36) NOT NULL,appointment_id CHAR(36) NULL,request_id CHAR(36) NULL,
 related_appointment_ids_json JSON NOT NULL,asc_location_id CHAR(36) NOT NULL,corrected_by CHAR(36) NOT NULL,corrected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 correction_action VARCHAR(80) NOT NULL,resolution_status VARCHAR(80) NOT NULL,correction_reason VARCHAR(500) NOT NULL,remarks TEXT NULL,evidence_reference VARCHAR(500) NULL,
 before_json JSON NOT NULL,after_json JSON NOT NULL,source VARCHAR(80) NOT NULL DEFAULT 'APPOINTMENT_DATA_ISSUE',record_origin VARCHAR(40) NOT NULL,
 legacy_source_references_json JSON NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(officer_id) REFERENCES officer(id),FOREIGN KEY(appointment_id) REFERENCES arpa_division_appointment(id),FOREIGN KEY(request_id) REFERENCES arpa_division_appointment_request(id),FOREIGN KEY(asc_location_id) REFERENCES location(id),FOREIGN KEY(corrected_by) REFERENCES system_user(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE audit_event(id BIGINT AUTO_INCREMENT PRIMARY KEY,actor_user_id CHAR(36),action_key VARCHAR(150),target_type VARCHAR(100),target_id CHAR(36),details_json JSON,severity VARCHAR(30),source_ip VARCHAR(64)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    private function fixtureAccess():void
    {
        $today=date('Y-m-d');
        $this->pdo->exec("INSERT INTO location VALUES('".self::ASC."','70004-0000001','ASC One');
INSERT INTO system_user VALUES
('".self::ADMIN."','dems.admin','System Administrator',1,'ACTIVE','APPROVED'),
('".self::ASC_USER."','asc.user','ASC User',1,'ACTIVE','APPROVED'),
('".self::DISTRICT_USER."','district.user','District User',1,'ACTIVE','APPROVED'),
('".self::NATIONAL_USER."','national.user','National User',1,'ACTIVE','APPROVED');
INSERT INTO application_role VALUES
('60000000-0000-4000-8000-000000000001','SYSTEM_ADMIN','System Administrator','SYSTEM',1,'APPROVED'),
('60000000-0000-4000-8000-000000000002','ASC_ADMIN','ASC Administrator','ASC',1,'APPROVED'),
('60000000-0000-4000-8000-000000000003','DISTRICT_ADMIN','District Administrator','DISTRICT',1,'APPROVED'),
('60000000-0000-4000-8000-000000000004','NATIONAL_ADMIN','National Administrator','NATIONAL',1,'APPROVED');
INSERT INTO application_permission VALUES('61000000-0000-4000-8000-000000000001','arpa.appointment.view',1);
INSERT INTO application_role_permission SELECT id,'61000000-0000-4000-8000-000000000001' FROM application_role;");
        $role=$this->pdo->prepare("INSERT INTO user_account_role VALUES(?,?,?,1,'APPROVED',?,NULL)");
        foreach([[self::ADMIN,'60000000-0000-4000-8000-000000000001'],[self::ASC_USER,'60000000-0000-4000-8000-000000000002'],[self::DISTRICT_USER,'60000000-0000-4000-8000-000000000003'],[self::NATIONAL_USER,'60000000-0000-4000-8000-000000000004']] as $index=>$row)$role->execute([sprintf('62000000-0000-4000-8000-%012d',$index+1),$row[0],$row[1],$today]);
        $scope=$this->pdo->prepare("INSERT INTO user_account_scope VALUES(?,?,?,?,?,?,1,'APPROVED',?,NULL)");
        foreach([[self::ASC_USER,'ASC','EXACT'],[self::DISTRICT_USER,'DISTRICT','INCLUDE_CHILDREN'],[self::NATIONAL_USER,'NATIONAL','NATIONAL']] as $index=>$row)$scope->execute([sprintf('63000000-0000-4000-8000-%012d',$index+1),$row[0],sprintf('62000000-0000-4000-8000-%012d',$index+2),$row[1],$row[2],self::ASC,$today]);
    }

    private function fixtureCorrections():void
    {
        $this->pdo->exec("INSERT INTO officer VALUES('".self::OFFICER."','70045-0000001','Fixture Officer');INSERT INTO arpa_division_appointment_request VALUES('".self::REQUEST."');INSERT INTO arpa_division_appointment VALUES('".self::APPOINTMENT."');");
        $insert=$this->pdo->prepare('INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,related_appointment_ids_json,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin,legacy_source_references_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $safe=function(string $id,string $before='{"a":1,"nested":{"b":2}}',string $after='{"nested":{"b":2},"a":1}')use($insert):void{$insert->execute([$id,'issue-'.$id,'LEGACY_HISTORICAL_EXCEPTION',self::OFFICER,self::APPOINTMENT,self::REQUEST,'["'.self::APPOINTMENT.'"]',self::ASC,self::ADMIN,'KEEP_AS_HISTORICAL_EXCEPTION','KEPT_HISTORICAL_EXCEPTION','Fixture decision',$before,$after,'LEGACY_IMPORT','[]']);};
        foreach([self::SAFE,self::ROLLBACK,self::ACTIVE_SOURCE,self::COMPLETED_SOURCE,self::APPLIED,self::PREDECESSOR,self::SUCCESSOR,self::CLOSURE_DEPENDENT] as $id)$safe($id);
        $insert->execute([self::MUTATING,'issue-mutating','LEGACY_HISTORICAL_EXCEPTION',self::OFFICER,self::APPOINTMENT,self::REQUEST,'[]',self::ASC,self::ADMIN,'CORRECT_EFFECTIVE_FROM','RESOLVED_BY_CORRECTION','Mutating fixture','{}','{}','LEGACY_IMPORT','[]']);
        $safe(self::CHANGED,'{"a":1}','{"a":2}');
        $this->pdo->prepare('UPDATE arpa_appointment_data_correction SET supersedes_correction_id=? WHERE id=?')->execute([self::PREDECESSOR,self::SUCCESSOR]);
        $amend=$this->pdo->prepare("INSERT INTO arpa_appointment_correction_amendment_request(id,source_correction_id,appointment_id,officer_id,asc_location_id,proposed_action,baseline_json,baseline_hash,proposed_json,correction_reason,workflow_status,created_by,applied_correction_id) VALUES(?,?,?,?,?,'KEEP_AS_HISTORICAL_EXCEPTION','{}',REPEAT('0',64),'{}','Fixture',?,?,?)");
        $amend->execute(['70000000-0000-4000-8000-000000000001',self::ACTIVE_SOURCE,self::APPOINTMENT,self::OFFICER,self::ASC,'DRAFT',self::ADMIN,null]);
        $amend->execute(['70000000-0000-4000-8000-000000000002',self::COMPLETED_SOURCE,self::APPOINTMENT,self::OFFICER,self::ASC,'NATIONAL_APPROVED',self::ADMIN,self::APPLIED]);
        $this->pdo->prepare('UPDATE arpa_appointment_data_correction SET amendment_request_id=? WHERE id=?')->execute(['70000000-0000-4000-8000-000000000002',self::APPLIED]);
        $this->pdo->prepare('INSERT INTO arpa_division_appointment_closure VALUES(?,?,?,?)')->execute(['71000000-0000-4000-8000-000000000001',self::APPOINTMENT,self::CLOSURE_DEPENDENT,'2025-12-31']);
        $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,source_ip) VALUES(?,'arpa.appointment.data-issue.correct','ARPA_APPOINTMENT_DATA_ISSUE',?,'{}','WARNING','CLI')")->execute([self::ADMIN,self::SAFE]);
    }

    private function installIsolatedDatabase():void{$this->databaseProperty=new ReflectionProperty(Database::class,'pdo');$this->databaseProperty->setAccessible(true);$this->previousDatabase=$this->databaseProperty->getValue();$this->databaseProperty->setValue(null,$this->pdo);Auth::forgetRequestCache();}
    private function useContext(string $userId):void{$_SESSION=['user_id'=>$userId,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();$contexts=(new UserContextService($this->pdo))->availableContexts($userId);if(count($contexts)!==1)throw new RuntimeException('Fixture user must have one context.');$context=$contexts[0];(new UserContextService($this->pdo))->select($userId,(string)$context['role_assignment_id'],$context['scope_assignment_id']===null?null:(string)$context['scope_assignment_id']);Auth::forgetRequestCache();}
    private function operationalState():array{return ['appointments'=>$this->count('SELECT COUNT(*) FROM arpa_division_appointment'),'requests'=>$this->count('SELECT COUNT(*) FROM arpa_division_appointment_request'),'closures'=>$this->count('SELECT COUNT(*) FROM arpa_division_appointment_closure'),'appointment_exists'=>$this->count('SELECT COUNT(*) FROM arpa_division_appointment WHERE id=?',[self::APPOINTMENT]),'request_exists'=>$this->count('SELECT COUNT(*) FROM arpa_division_appointment_request WHERE id=?',[self::REQUEST])];}
    private function row(string $sql,array $params=[]):array{$stmt=$this->pdo->prepare($sql);$stmt->execute($params);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Expected fixture row was not found.');return $row;}
    private function count(string $sql,array $params=[]):int{$stmt=$this->pdo->prepare($sql);$stmt->execute($params);return (int)$stmt->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.'; expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throwsDomain(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(DomainException){return;}catch(Throwable $e){throw new RuntimeException($message.'; expected DomainException, got '.get_class($e).': '.$e->getMessage(),0,$e);}throw new RuntimeException($message.'; no exception was thrown.');}
    private function throwsThrowable(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(Throwable){return;}throw new RuntimeException($message.'; no exception was thrown.');}
}

try{exit((new ArpaAppointmentCorrectionPermanentDeleteTest())->run());}
catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
