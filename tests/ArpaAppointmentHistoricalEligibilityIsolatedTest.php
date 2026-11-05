<?php
declare(strict_types=1);

use App\Core\{Auth,Database,ScopeService};
use App\Services\{ArpaAppointmentCandidateService,ArpaAppointmentReadService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentHistoricalEligibilityIsolatedTest
{
    private PDO $pdo;
    private int $assertions=0;

    public function run():void
    {
        $c=config('database');
        if(!in_array($c['host'],['127.0.0.1','localhost'],true))throw new RuntimeException('A local disposable test server is required.');
        $name='dems_test_arpa_historical_'.bin2hex(random_bytes(5));
        if(!preg_match('/^dems_test_arpa_historical_[a-f0-9]{10}$/D',$name))throw new RuntimeException('Unsafe test database.');
        $server=new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4',$c['host'],$c['port']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $property=new ReflectionProperty(Database::class,'pdo');$previous=$property->getValue();$session=$_SESSION??[];$created=false;
        try{
            $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
            $this->pdo=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c['host'],$c['port'],$name),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
            if($this->pdo->query('SELECT DATABASE()')->fetchColumn()!==$name)throw new RuntimeException('Wrong fixture database before writes.');
            $property->setValue(null,$this->pdo);$this->fixtures();$this->exercise();
            echo 'ArpaAppointmentHistoricalEligibilityIsolatedTest: '.$this->assertions.' assertions passed; MySQL '.$server->getAttribute(PDO::ATTR_SERVER_VERSION).".\n";
        }finally{
            $property->setValue(null,$previous);$_SESSION=$session;Auth::forgetRequestCache();
            if($created)$server->exec('DROP DATABASE `'.$name.'`');
        }
    }

    private function fixtures():void
    {
        $this->pdo->exec("SET SESSION default_storage_engine='InnoDB'");
        $this->pdo->exec(<<<'SQL'
CREATE TABLE location_type(id CHAR(36) PRIMARY KEY,system_key VARCHAR(80));
CREATE TABLE location(id CHAR(36) PRIMARY KEY,location_type_id CHAR(36),dad_number VARCHAR(50),name_en VARCHAR(255),effective_from DATE,effective_to DATE NULL,approval_status VARCHAR(30) DEFAULT 'APPROVED',operational_status VARCHAR(30) DEFAULT 'ACTIVE');
CREATE TABLE location_relationship(id CHAR(36) PRIMARY KEY,parent_location_id CHAR(36),child_location_id CHAR(36),relationship_type VARCHAR(80),active TINYINT DEFAULT 1,approval_status VARCHAR(30) DEFAULT 'APPROVED',effective_from DATE,effective_to DATE NULL);
CREATE TABLE system_user(id CHAR(36) PRIMARY KEY,enabled TINYINT DEFAULT 1,account_status VARCHAR(30) DEFAULT 'ACTIVE',approval_status VARCHAR(30) DEFAULT 'APPROVED');
CREATE TABLE application_role(id CHAR(36) PRIMARY KEY,role_code VARCHAR(80),role_name VARCHAR(100),role_level VARCHAR(30),active TINYINT DEFAULT 1,approval_status VARCHAR(30) DEFAULT 'APPROVED');
CREATE TABLE user_account_role(id CHAR(36) PRIMARY KEY,user_id CHAR(36),role_id CHAR(36),active TINYINT DEFAULT 1,approval_status VARCHAR(30) DEFAULT 'APPROVED',effective_from DATE,effective_to DATE NULL);
CREATE TABLE user_account_scope(id CHAR(36) PRIMARY KEY,user_id CHAR(36),role_assignment_id CHAR(36),scope_type VARCHAR(30),scope_mode VARCHAR(30),location_id CHAR(36),active TINYINT DEFAULT 1,approval_status VARCHAR(30) DEFAULT 'APPROVED',effective_from DATE,effective_to DATE NULL);
CREATE TABLE designation(id CHAR(36) PRIMARY KEY,system_key VARCHAR(80),active TINYINT DEFAULT 1,approval_status VARCHAR(30) DEFAULT 'APPROVED');
CREATE TABLE officer(id CHAR(36) PRIMARY KEY,dad_number VARCHAR(50),name_with_initials VARCHAR(255),nic VARCHAR(20),primary_designation_id CHAR(36),arpa_service_permanency VARCHAR(80),initial_appointment_date DATE NULL,effective_from DATE,effective_to DATE NULL,created_by CHAR(36) NULL,approval_status VARCHAR(30) DEFAULT 'APPROVED',operational_status VARCHAR(30) DEFAULT 'ACTIVE');
CREATE TABLE legacy_officer_reference(id CHAR(36) PRIMARY KEY,officer_id CHAR(36),source_system VARCHAR(80),source_table VARCHAR(80),legacy_payload_json JSON NULL);
CREATE TABLE office_type(id CHAR(36) PRIMARY KEY,system_key VARCHAR(80));
CREATE TABLE office(id CHAR(36) PRIMARY KEY,office_type_id CHAR(36),linked_location_id CHAR(36),effective_from DATE,effective_to DATE NULL,approval_status VARCHAR(30) DEFAULT 'APPROVED',operational_status VARCHAR(30) DEFAULT 'ACTIVE');
CREATE TABLE officer_office_assignment(id CHAR(36) PRIMARY KEY,officer_id CHAR(36),office_id CHAR(36),effective_from DATE,effective_to DATE NULL,active TINYINT DEFAULT 1,approval_status VARCHAR(30) DEFAULT 'APPROVED');
CREATE TABLE arpa_division_appointment_request(id CHAR(36) PRIMARY KEY,officer_id CHAR(36),appointment_type VARCHAR(50),asc_location_id CHAR(36),arpa_division_location_id CHAR(36),requested_effective_from DATE,requested_effective_to DATE NULL,workflow_status VARCHAR(50),request_type VARCHAR(50) DEFAULT 'APPOINTMENT',record_origin VARCHAR(50) DEFAULT 'NATIVE',legacy_history_only TINYINT DEFAULT 0,deleted_at DATETIME NULL);
CREATE TABLE arpa_division_appointment(id CHAR(36) PRIMARY KEY,request_id CHAR(36),officer_id CHAR(36),appointment_type VARCHAR(50),arpa_division_location_id CHAR(36),effective_from DATE,legacy_history_only TINYINT DEFAULT 0);
CREATE TABLE arpa_division_appointment_closure(id CHAR(36) PRIMARY KEY,appointment_id CHAR(36),effective_to DATE);
INSERT INTO location_type VALUES('asc-type','ASC'),('division-type','ARPA_DIVISION');
INSERT INTO location(id,location_type_id,dad_number,name_en,effective_from) VALUES('thalalla','asc-type','70004-0000183','Synthetic Thalalla','2024-01-05'),('outside','asc-type','70004-OUT','Synthetic outside','2024-01-05'),('naotunna','division-type','70007-0004133','Synthetic Naotunna North','2024-01-05');
INSERT INTO location_relationship(id,parent_location_id,child_location_id,relationship_type,effective_from) VALUES('asc-arpa','thalalla','naotunna','ASC_ARPA_DIVISION','2024-01-05');
INSERT INTO system_user(id) VALUES('subject'),('admin'),('foreign-subject');
INSERT INTO application_role(id,role_code,role_name,role_level) VALUES('subject-role','ASC_SUBJECT_OFFICER','ASC Subject Officer','ASC'),('admin-role','ASC_ADMIN','ASC Administrator','ASC');
INSERT INTO designation(id,system_key) VALUES('arpa','ARPA_OFFICER');
INSERT INTO office_type VALUES('asc-office-type','ASC_OFFICE');
INSERT INTO office(id,office_type_id,linked_location_id,effective_from) VALUES('office','asc-office-type','thalalla','2024-01-05');
INSERT INTO officer(id,dad_number,name_with_initials,primary_designation_id,arpa_service_permanency,initial_appointment_date,effective_from) VALUES('walpita','70045-0002450','Synthetic L R Walpita','arpa','PERMANENT_IN_SERVICE','2000-01-03','2025-01-22'),('first-permanent','70045-NEW','Synthetic first Permanent','arpa','PERMANENT_IN_SERVICE','2000-01-03','2025-01-22');
INSERT INTO legacy_officer_reference VALUES('ref-walpita','walpita','AGRARIANADMIN_HR','tbl_officer','{"created_at":"2025-01-22 09:12:54"}'),('ref-new','first-permanent','AGRARIANADMIN_HR','tbl_officer','{"created_at":"2025-01-22 09:12:54"}');
INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from) VALUES('walpita-office','walpita','office','2026-08-12'),('new-office','first-permanent','office','2026-08-12');
INSERT INTO arpa_division_appointment_request(id,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,record_origin) VALUES('foundation-request','walpita','PERMANENT','thalalla','devinuwara','2022-01-03','DISTRICT_APPROVED','LEGACY_IMPORT'),('next-request','walpita','ACTING','thalalla','naotunna','2026-01-01','DISTRICT_APPROVED','LEGACY_IMPORT');
INSERT INTO arpa_division_appointment(id,request_id,officer_id,appointment_type,arpa_division_location_id,effective_from) VALUES('foundation','foundation-request','walpita','PERMANENT','devinuwara','2022-01-03'),('next','next-request','walpita','ACTING','naotunna','2026-01-01');
SQL);
        if((int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND engine<>'InnoDB'")->fetchColumn()!==0)throw new RuntimeException('Transactional fixtures are required.');
        // Current Working Context starts now, not at the historical business date.
        $today=date('Y-m-d');
        foreach([['subject','subject-role','thalalla'],['admin','admin-role','thalalla'],['foreign-subject','subject-role','outside']] as [$user,$role,$asc]){
            $this->pdo->prepare('INSERT INTO user_account_role(id,user_id,role_id,effective_from) VALUES(?,?,?,?)')->execute([$user.'-role',$user,$role,$today]);
            $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from) VALUES(?,?,?,'ASC','EXACT',?,?)")->execute([$user.'-scope',$user,$user.'-role',$asc,$today]);
        }
    }

    private function exercise():void
    {
        $read=new ArpaAppointmentReadService($this->pdo);$candidate=new ArpaAppointmentCandidateService($this->pdo);$before=$this->state();
        $this->pdo->exec('SET TRANSACTION READ ONLY');$this->pdo->beginTransaction();
        try{
            foreach(['subject','admin'] as $actor){
                $this->context($actor);
                $this->same(true,ScopeService::canAccessCurrentArpaStage($actor,'ASC','thalalla'),'current ASC context authorizes historical reads');
                $rows=array_column($candidate->optionsForAsc($actor,'thalalla','2025-01-20'),null,'id');
                $this->same(true,isset($rows['walpita']),'imported Officer appears before the legacy registration date');
                $this->same(['ACTING','DUTY_COVERING'],$rows['walpita']['allowed_appointment_types'],'Permanent foundation supports Acting; second Permanent is still excluded');
                $read->assertEligibleOfficer('walpita','thalalla','2025-01-20');$this->assertions++;
                $read->assertAppointmentTypeAvailable('walpita','ACTING','naotunna','2025-01-20','2025-12-31');$this->assertions++;
                $read->assertDivisionPeriodAvailable('thalalla','naotunna','2025-01-20','2025-12-31');$this->assertions++;
                $this->throws(fn()=>$read->assertDivisionPeriodAvailable('thalalla','naotunna','2025-01-20','2026-01-01'),'next canonical appointment still blocks a real overlap');
                $this->throws(fn()=>$read->assertAppointmentTypeAvailable('walpita','ACTING','naotunna','2026-01-01',null),'duplicate same-Division Acting still blocked');
                $this->throws(fn()=>$read->assertAppointmentTypeAvailable('walpita','PERMANENT','naotunna','2025-01-20','2025-12-31'),'second Permanent remains blocked');
                $this->same(['PERMANENT'],$rows['first-permanent']['allowed_appointment_types'],'a first Permanent does not require a Permanent foundation');
                $read->assertAppointmentTypeAvailable('first-permanent','PERMANENT','naotunna','2025-01-20','2025-12-31');$this->assertions++;
                foreach(['ACTING','DUTY_COVERING','ATTEND_TO_DUTY'] as $type)$this->throws(fn()=>$read->assertAppointmentTypeAvailable('first-permanent',$type,'naotunna','2025-01-20','2025-12-31'),'dependent prerequisite unchanged: '.$type);
                $this->throws(fn()=>$read->assertAppointmentTypeAvailable('walpita','ATTEND_TO_DUTY','naotunna','2025-01-20','2025-12-31'),'Permanent-in-service cannot receive Attend to Duty');
                $this->same([],array_column($candidate->optionsForAsc($actor,'thalalla','1999-12-31'),'id'),'a date preceding both service evidence and registration stays ineligible');
                $this->same([],$candidate->optionsForAsc($actor,'outside','2025-01-20'),'forged ASC filter denied');
            }
            $this->context('foreign-subject');
            $this->same([],$candidate->optionsForAsc('foreign-subject','thalalla','2025-01-20'),'wrong ASC cannot retrieve historical candidates');
            $this->same($before,$this->state(),'candidate and validation reads change no source or authorization records');
        }finally{$this->pdo->rollBack();}
        $this->context('subject');
        foreach([
            "UPDATE officer SET initial_appointment_date=NULL WHERE id='walpita'",
            "UPDATE legacy_officer_reference SET source_system='UNVERIFIED' WHERE officer_id='walpita'",
            "UPDATE legacy_officer_reference SET source_table='unverified_source' WHERE officer_id='walpita'",
            "DELETE FROM legacy_officer_reference WHERE officer_id='walpita'",
            "UPDATE legacy_officer_reference SET legacy_payload_json=NULL WHERE officer_id='walpita'",
            "UPDATE legacy_officer_reference SET legacy_payload_json='{\"created_at\":\"2025-01-23\"}' WHERE officer_id='walpita'",
            "UPDATE officer SET created_by='subject' WHERE id='walpita'",
            "UPDATE officer SET initial_appointment_date='2025-01-21' WHERE id='walpita'",
            "UPDATE officer SET effective_to='2025-01-19' WHERE id='walpita'",
            "UPDATE officer SET operational_status='INACTIVE' WHERE id='walpita'",
            "UPDATE officer SET approval_status='SUBMITTED' WHERE id='walpita'",
            "UPDATE designation SET active=0 WHERE id='arpa'",
            "UPDATE designation SET approval_status='SUBMITTED' WHERE id='arpa'",
            "UPDATE officer_office_assignment SET active=0 WHERE id='walpita-office'",
            "UPDATE officer_office_assignment SET approval_status='SUBMITTED' WHERE id='walpita-office'",
            "UPDATE officer_office_assignment SET effective_to='2025-12-31' WHERE id='walpita-office'",
            "UPDATE officer_office_assignment SET effective_from='2099-01-01' WHERE id='walpita-office'",
            "UPDATE office SET linked_location_id='outside' WHERE id='office'",
            "UPDATE office SET operational_status='INACTIVE' WHERE id='office'",
            "UPDATE office SET approval_status='SUBMITTED' WHERE id='office'",
            "UPDATE office SET effective_from='2099-01-01' WHERE id='office'",
        ] as $sql){
            $this->pdo->beginTransaction();
            try{$this->pdo->exec($sql);$rows=array_column($candidate->optionsForAsc('subject','thalalla','2025-01-20'),null,'id');$this->same(false,isset($rows['walpita']),'unsafe or unsupported evidence remains excluded: '.$sql);$this->throws(fn()=>$read->assertEligibleOfficer('walpita','thalalla','2025-01-20'),'backend agrees with exclusion: '.$sql);}
            finally{$this->pdo->rollBack();}
        }
        $this->pdo->beginTransaction();
        try{
            $this->pdo->exec("INSERT INTO arpa_division_appointment_request(id,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status) VALUES('pending','first-permanent','ACTING','thalalla','naotunna','2025-06-01','SUBMITTED')");
            $this->throws(fn()=>$read->assertDivisionPeriodAvailable('thalalla','naotunna','2025-01-20','2025-12-31'),'genuine pending reservation still blocks');
        }finally{$this->pdo->rollBack();}
        $this->commencementBounds($read,$candidate);
        $this->same($before,$this->state(),'all variant fixtures rolled back; no appointment or Office dates backdated');
    }

    private function commencementBounds(ArpaAppointmentReadService $read,ArpaAppointmentCandidateService $candidate):void
    {
        foreach([
            // Different verified imports share the same generic calculation.
            ['walpita',null,'2000-01-03','2025-01-22','2025-01-20',true],
            ['first-permanent',null,'2005-06-07','2025-01-22','2025-01-20',true],
            // Native creation must not be reinterpreted by an attached legacy reference.
            ['first-permanent','subject','2000-01-03','2025-01-22','2025-01-20',false],
            ['first-permanent','subject','2000-01-03','2025-01-22','2025-01-22',true],
            // A registration date cannot override a known later service commencement.
            ['first-permanent',null,'2030-06-01','2025-01-22','2030-05-31',false],
            ['first-permanent',null,'2030-06-01','2025-01-22','2030-06-01',true],
            ['first-permanent','subject','2030-06-01','2025-01-22','2030-05-31',false],
            ['first-permanent','subject','2030-06-01','2025-01-22','2030-06-01',true],
            // Missing commencement evidence cannot authorize earlier history.
            ['first-permanent',null,null,'2025-01-22','2025-01-20',false],
            ['first-permanent','subject',null,'2025-01-22','2025-01-20',false],
        ] as [$id,$creator,$commencement,$registered,$date,$expected]){
            $this->pdo->beginTransaction();
            try{
                $this->pdo->prepare('UPDATE officer SET created_by=?,initial_appointment_date=?,effective_from=? WHERE id=?')->execute([$creator,$commencement,$registered,$id]);
                $rows=array_column($candidate->optionsForAsc('subject','thalalla',$date),null,'id');
                $this->same($expected,isset($rows[$id]),'shared candidate commencement boundary: '.$id.' / '.$date.' / '.($creator??'imported'));
                if($expected){$read->assertEligibleOfficer($id,'thalalla',$date);$this->assertions++;}
                else $this->throws(fn()=>$read->assertEligibleOfficer($id,'thalalla',$date),'backend rejects the same unsupported commencement');
            }finally{$this->pdo->rollBack();}
        }
    }

    private function context(string $id):void
    {
        $_SESSION=['user_id'=>$id,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();
        (new UserContextService($this->pdo))->select($id,$id.'-role',$id.'-scope');Auth::forgetRequestCache();
    }
    private function state():array{$state=[];foreach($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$state[$table]=hash('sha256',json_encode($this->pdo->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetchAll(),JSON_THROW_ON_ERROR));return $state;}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.'; expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $work,string $message):void{$this->assertions++;try{$work();}catch(DomainException){return;}throw new RuntimeException($message.'; expected DomainException');}
}

try{(new ArpaAppointmentHistoricalEligibilityIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
