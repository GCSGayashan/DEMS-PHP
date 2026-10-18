<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentCorrectionAmendmentMigrationTest
{
    private PDO $server;
    private string $databaseName;
    private int $assertions=0;

    public function run():int
    {
        $config=config('database');
        $this->databaseName='dems_test_arpa_correction_amendment_'.bin2hex(random_bytes(4));
        if(preg_match('/^dems_test_arpa_correction_amendment_[a-f0-9]{8}$/D',$this->databaseName)!==1){
            throw new RuntimeException('Unsafe isolated test database name.');
        }
        $this->server=new PDO(
            sprintf('mysql:host=%s;port=%s;charset=%s',$config['host'],$config['port'],$config['charset']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]
        );

        try{
            $this->createFixtureSchema();
            $pdo=$this->database();
            $this->same(false,\App\Services\ArpaAppointmentCorrectionDecision::lineageAvailable($pdo),'legacy schema reports lineage unavailable');
            $this->same('1=1',\App\Services\ArpaAppointmentCorrectionDecision::effectivePredicate($pdo,'legacy_decision'),'legacy schema retains correction behavior without lineage SQL');
            $migration=(string)file_get_contents(BASE_PATH.'/database/migrations/062_arpa_appointment_correction_amendment_foundation.sql');
            $this->same(false,str_contains(strtoupper($migration),'ON DELETE CASCADE'),'migration never cascades deletion of amendment or correction history');
            $pdo->exec($migration);

            $this->schema($pdo);
            $this->activeRequestConstraint($pdo);
            $this->lineageConstraints($pdo);
            $this->structuredEventEvidence($pdo);
        }finally{
            if(isset($this->server))$this->server->exec('DROP DATABASE IF EXISTS `'.$this->databaseName.'`');
        }

        echo "ArpaAppointmentCorrectionAmendmentMigrationTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function createFixtureSchema():void
    {
        $this->server->exec('CREATE DATABASE `'.$this->databaseName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo=$this->database();
        $pdo->exec("CREATE TABLE system_user(id CHAR(36) PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            CREATE TABLE location(id CHAR(36) PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            CREATE TABLE officer(id CHAR(36) PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            CREATE TABLE arpa_division_appointment(id CHAR(36) PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            CREATE TABLE arpa_appointment_data_correction(id CHAR(36) PRIMARY KEY,legacy_source_references_json JSON NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            INSERT INTO system_user VALUES('00000000-0000-0000-0000-000000000001');
            INSERT INTO location VALUES('00000000-0000-0000-0000-000000000002');
            INSERT INTO officer VALUES('00000000-0000-0000-0000-000000000003');
            INSERT INTO arpa_division_appointment VALUES('00000000-0000-0000-0000-000000000004');
            INSERT INTO arpa_appointment_data_correction(id) VALUES
              ('10000000-0000-0000-0000-000000000001'),
              ('10000000-0000-0000-0000-000000000002'),
              ('10000000-0000-0000-0000-000000000003'),
              ('10000000-0000-0000-0000-000000000004'),
              ('10000000-0000-0000-0000-000000000005');");
    }

    private function schema(PDO $pdo):void
    {
        $this->same(1,$this->count($pdo,"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='arpa_appointment_correction_amendment_request'"),'amendment request table exists');
        $this->same(1,$this->count($pdo,"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='arpa_appointment_correction_amendment_event'"),'amendment event table exists');
        $this->same('YES',$this->value($pdo,"SELECT is_nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_appointment_data_correction' AND column_name='supersedes_correction_id'"),'supersedes lineage is nullable for existing corrections');
        $this->same('YES',$this->value($pdo,"SELECT is_nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_appointment_data_correction' AND column_name='amendment_request_id'"),'amendment lineage is nullable for existing corrections');
        $this->same('NO',$this->value($pdo,"SELECT is_nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_appointment_correction_amendment_request' AND column_name='source_correction_id'"),'source correction is required');
        $this->same('NO',$this->value($pdo,"SELECT is_nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_appointment_correction_amendment_request' AND column_name='appointment_id'"),'existing appointment is required');
        $existing=$this->row($pdo,"SELECT supersedes_correction_id,amendment_request_id FROM arpa_appointment_data_correction WHERE id='10000000-0000-0000-0000-000000000001'");
        $this->same(['supersedes_correction_id'=>null,'amendment_request_id'=>null],$existing,'existing correction remains unchanged with null lineage');
        $this->same('DRAFT',$this->value($pdo,"SELECT column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_appointment_correction_amendment_request' AND column_name='workflow_status'"),'new amendment requests default to DRAFT');
        $columnType=(string)$this->value($pdo,"SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_appointment_correction_amendment_request' AND column_name='workflow_status'");
        foreach(['DRAFT','SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','NATIONAL_APPROVED','RETURNED','REJECTED','WITHDRAWN'] as $status){
            $this->same(true,str_contains($columnType,"'{$status}'"),"workflow status {$status} is supported");
        }
        $this->throws(fn()=>$pdo->exec("INSERT INTO arpa_appointment_correction_amendment_request(id,source_correction_id,appointment_id,officer_id,asc_location_id,proposed_action,baseline_json,baseline_hash,proposed_json,correction_reason,created_by) VALUES(UUID(),'10000000-0000-0000-0000-000000000001',NULL,'00000000-0000-0000-0000-000000000003','00000000-0000-0000-0000-000000000002','SET_EFFECTIVE_TO','{}',REPEAT('a',64),'{}','Required appointment test','00000000-0000-0000-0000-000000000001')"),'appointment_id is required');
        $this->throws(fn()=>$pdo->exec("INSERT INTO arpa_appointment_correction_amendment_request(id,source_correction_id,appointment_id,officer_id,asc_location_id,proposed_action,baseline_json,baseline_hash,proposed_json,correction_reason,created_by) VALUES(UUID(),'10000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-000000000004','00000000-0000-0000-0000-000000000003','00000000-0000-0000-0000-000000000002','NOT_A_CORRECTION_ACTION','{}',REPEAT('a',64),'{}','Invalid action test','00000000-0000-0000-0000-000000000001')"),'proposed action must use an established correction action');
    }

    private function activeRequestConstraint(PDO $pdo):void
    {
        $this->insertRequest($pdo,'20000000-0000-0000-0000-000000000001','10000000-0000-0000-0000-000000000002','DRAFT');
        $this->same('10000000-0000-0000-0000-000000000002',$this->value($pdo,"SELECT active_source_correction_id FROM arpa_appointment_correction_amendment_request WHERE id='20000000-0000-0000-0000-000000000001'"),'DRAFT is an active amendment');
        $this->throws(fn()=>$this->insertRequest($pdo,'20000000-0000-0000-0000-000000000002','10000000-0000-0000-0000-000000000002','ASC_APPROVED'),'second active amendment for one source is rejected');
        $pdo->exec("UPDATE arpa_appointment_correction_amendment_request SET workflow_status='NATIONAL_APPROVED' WHERE id='20000000-0000-0000-0000-000000000001'");
        $this->same(null,$this->value($pdo,"SELECT active_source_correction_id FROM arpa_appointment_correction_amendment_request WHERE id='20000000-0000-0000-0000-000000000001'"),'NATIONAL_APPROVED is terminal');
        $this->insertRequest($pdo,'20000000-0000-0000-0000-000000000002','10000000-0000-0000-0000-000000000002','RETURNED');
        $this->throws(fn()=>$this->insertRequest($pdo,'20000000-0000-0000-0000-000000000003','10000000-0000-0000-0000-000000000002','SUBMITTED'),'RETURNED remains active and blocks another proposal');

        $this->insertRequest($pdo,'20000000-0000-0000-0000-000000000004','10000000-0000-0000-0000-000000000004','REJECTED');
        $this->insertRequest($pdo,'20000000-0000-0000-0000-000000000005','10000000-0000-0000-0000-000000000004','DRAFT');
        $this->insertRequest($pdo,'20000000-0000-0000-0000-000000000006','10000000-0000-0000-0000-000000000005','WITHDRAWN');
        $this->insertRequest($pdo,'20000000-0000-0000-0000-000000000007','10000000-0000-0000-0000-000000000005','DISTRICT_VERIFIED');
        $this->same(2,$this->count($pdo,"SELECT COUNT(*) FROM arpa_appointment_correction_amendment_request WHERE active_source_correction_id IN('10000000-0000-0000-0000-000000000004','10000000-0000-0000-0000-000000000005')"),'REJECTED and WITHDRAWN are terminal while later pending requests are active');
    }

    private function lineageConstraints(PDO $pdo):void
    {
        $this->insertRequest($pdo,'20000000-0000-0000-0000-000000000008','10000000-0000-0000-0000-000000000001','NATIONAL_APPROVED');
        $pdo->exec("INSERT INTO arpa_appointment_data_correction(id,supersedes_correction_id,amendment_request_id) VALUES('30000000-0000-0000-0000-000000000001','10000000-0000-0000-0000-000000000001','20000000-0000-0000-0000-000000000008')");
        $pdo->exec("UPDATE arpa_appointment_correction_amendment_request SET applied_correction_id='30000000-0000-0000-0000-000000000001' WHERE id='20000000-0000-0000-0000-000000000008'");
        $this->throws(fn()=>$pdo->exec("INSERT INTO arpa_appointment_data_correction(id,supersedes_correction_id) VALUES('30000000-0000-0000-0000-000000000002','10000000-0000-0000-0000-000000000001')"),'one source decision cannot have multiple replacement corrections');
        $this->throws(fn()=>$pdo->exec("INSERT INTO arpa_appointment_data_correction(id,amendment_request_id) VALUES('30000000-0000-0000-0000-000000000003','20000000-0000-0000-0000-000000000008')"),'one amendment cannot create multiple applied correction rows');
        $this->throws(fn()=>$pdo->exec("INSERT INTO arpa_appointment_data_correction(id,supersedes_correction_id) VALUES('30000000-0000-0000-0000-000000000004','30000000-0000-0000-0000-000000000004')"),'a correction cannot supersede itself');
        $this->throws(fn()=>$pdo->exec("DELETE FROM arpa_appointment_data_correction WHERE id='10000000-0000-0000-0000-000000000001'"),'source correction cannot be deleted while amendment history references it');
    }

    private function structuredEventEvidence(PDO $pdo):void
    {
        $pdo->exec("INSERT INTO arpa_appointment_correction_amendment_event(amendment_request_id,action,stage,user_id,previous_status,new_status,before_proposal_json,after_proposal_json) VALUES('20000000-0000-0000-0000-000000000008','EDIT_DRAFT','CREATOR','00000000-0000-0000-0000-000000000001','DRAFT','DRAFT','{\"effective_to\":\"2025-01-01\"}','{\"effective_to\":\"2025-01-02\"}')");
        $event=$this->row($pdo,"SELECT JSON_UNQUOTE(JSON_EXTRACT(before_proposal_json,'$.effective_to')) before_date,JSON_UNQUOTE(JSON_EXTRACT(after_proposal_json,'$.effective_to')) after_date FROM arpa_appointment_correction_amendment_event WHERE amendment_request_id='20000000-0000-0000-0000-000000000008'");
        $this->same(['before_date'=>'2025-01-01','after_date'=>'2025-01-02'],$event,'event retains structured before/after proposal evidence');
    }

    private function insertRequest(PDO $pdo,string $id,string $source,string $status):void
    {
        $stmt=$pdo->prepare('INSERT INTO arpa_appointment_correction_amendment_request(id,source_correction_id,appointment_id,officer_id,asc_location_id,proposed_action,baseline_json,baseline_hash,proposed_json,correction_reason,workflow_status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$id,$source,'00000000-0000-0000-0000-000000000004','00000000-0000-0000-0000-000000000003','00000000-0000-0000-0000-000000000002','SET_EFFECTIVE_TO','{}',str_repeat('a',64),'{}','Migration constraint fixture',$status,'00000000-0000-0000-0000-000000000001']);
    }

    private function database():PDO
    {
        $config=config('database');
        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',$config['host'],$config['port'],$this->databaseName,$config['charset']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]
        );
    }

    private function count(PDO $pdo,string $sql):int{return (int)$pdo->query($sql)->fetchColumn();}
    private function value(PDO $pdo,string $sql):mixed{return $pdo->query($sql)->fetchColumn();}
    private function row(PDO $pdo,string $sql):array{return $pdo->query($sql)->fetch()?:[];}
    private function same(mixed $expected,mixed $actual,string $message):void
    {
        $this->assertions++;
        if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
    }
    private function throws(callable $callback,string $message):void
    {
        $this->assertions++;
        try{$callback();}catch(Throwable){return;}
        throw new RuntimeException($message.': expected database constraint failure');
    }
}

exit((new ArpaAppointmentCorrectionAmendmentMigrationTest())->run());
