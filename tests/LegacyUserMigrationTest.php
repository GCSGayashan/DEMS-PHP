<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\LegacyDatabase;
use App\Services\LegacyUser\LegacyUserMigrationService;

require dirname(__DIR__).'/bootstrap.php';

final class LegacyUserMigrationTest
{
    private int $assertions=0;

    public function run(): int
    {
        $this->testVerifiedSourceDryRun();
        $this->testSafeExecuteAndIdempotencyFixture();
        echo "LegacyUserMigrationTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function testVerifiedSourceDryRun(): void
    {
        $target=Database::pdo();$before=$this->state($target);
        $summary=(new LegacyUserMigrationService(LegacyDatabase::pdo(),$target,true,500))->run();
        $this->same(1324,$summary['legacy_user_count'],'verified legacy user population');
        $this->same(1014,$summary['workflow_user_ids_referenced'],'all distinct appointment workflow users discovered');
        $this->same(1014,$summary['workflow_user_ids_found'],'every workflow ID exists in tbl_user');
        $this->same(169,$summary['missing_usernames'],'missing usernames are reported without invention');
        $this->same(1155,$summary['legacy_usernames_used'],'usable legacy usernames are retained');
        $this->same(169,$summary['generated_usernames_used'],'missing usernames receive deterministic historical usernames');
        $this->same(1324,$summary['invalid_emails'],'legacy hash-like email values are never treated as email');
        $this->same(0,$summary['target_email_users'],'legacy digest is never an active email');
        $this->same(1324,$summary['null_email_users'],'all imported historical emails remain null');
        $this->same(0,$summary['critical_blockers'],'warning-only source defects do not block identities');
        $this->same(1014,$summary['workflow_user_ids_resolved'],'all workflow identities are resolvable');
        $this->same(0,$summary['workflow_user_ids_unresolved'],'no workflow identity remains unresolved');
        $this->same(100.0,$summary['workflow_coverage_percent'],'workflow identity coverage is complete');
        $this->same(1324,$summary['new_users_to_create']+$summary['already_migrated'],'every source identity is accounted for');
        $this->same('READY_WITH_WARNINGS',$summary['status'],'dry-run is safe with auditable warnings');
        $this->same(true,$summary['protected_state']['unchanged'],'dry-run protects current identities and operational data');
        $this->same($before,$this->state($target),'dry-run performs zero database writes');
        $this->same(0,$this->scalar($target,"SELECT COUNT(*) FROM subject_master WHERE system_key IN ('ADMIN','HR','CULTIVATION','SUBSIDY')"),'legacy application subjects are absent from Subject Master');
    }

    private function testSafeExecuteAndIdempotencyFixture(): void
    {
        $suffix=strtolower(bin2hex(random_bytes(5)));$sourceDb='dems_user_src_'.$suffix;$targetDb='dems_user_tgt_'.$suffix;
        if(preg_match('/^[a-z0-9_]+$/',$sourceDb.$targetDb)!==1)throw new RuntimeException('Unsafe fixture database name.');
        $server=$this->server();
        try{
            $server->exec("CREATE DATABASE `{$sourceDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $server->exec("CREATE DATABASE `{$targetDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $source=$this->database($sourceDb);$target=$this->database($targetDb);
            foreach($this->migrationFiles() as $file)$target->exec((string)file_get_contents($file));
            $this->createSourceFixture($source);
            $currentId='00000000-0000-4000-8000-000000000901';$hash=password_hash('Existing-Credential-Only',PASSWORD_DEFAULT);
            $target->prepare("INSERT INTO system_user(id,identity_type,username,display_name,password_hash,account_status,enabled,approval_status) VALUES(?,'STAFF','current.fixture','Current Fixture',?,'ACTIVE',1,'APPROVED')")->execute([$currentId,$hash]);
            $currentBefore=$target->query("SELECT username,password_hash,account_status,enabled FROM system_user WHERE id='{$currentId}'")->fetch();
            $roleBefore=$this->scalar($target,'SELECT COUNT(*) FROM user_account_role');$scopeBefore=$this->scalar($target,'SELECT COUNT(*) FROM user_account_scope');

            $dry=(new LegacyUserMigrationService($source,$target,true,10))->run();
            $this->same(0,$dry['critical_blockers'],'safe fixture has no blocker');$this->same(2,$dry['new_users_to_create'],'safe fixture would create both identities');
            $this->same(1,$dry['generated_usernames_used'],'blank source username uses deterministic identity');
            $execute=(new LegacyUserMigrationService($source,$target,false,10))->run();
            $this->same(2,$execute['created'],'execute creates both historical identities');$this->same(2,$execute['mappings_created'],'execute creates deterministic legacy mappings');
            $imported=$target->query("SELECT * FROM system_user WHERE username='legacy.reviewer'")->fetch();
            $this->same('HISTORICAL',$imported['identity_type'],'import uses historical identity type');
            $this->same('DISABLED',$imported['account_status'],'imported identity is disabled');$this->same(0,(int)$imported['enabled'],'imported identity is not enabled');
            $this->same(null,$imported['password_hash'],'legacy password is never placed in active password field');
            $this->same(null,$imported['email'],'legacy SHA-256 email digest is not used as email');
            $this->same(null,$imported['email_normalized'],'legacy SHA-256 email digest is not normalized as email');
            $this->same(0,$this->scalar($target,"SELECT COUNT(*) FROM system_user WHERE username='legacy.reviewer' AND enabled=1 AND account_status='ACTIVE'"),'imported disabled user cannot satisfy authentication predicate');
            $this->same(1,$this->scalar($target,"SELECT COUNT(*) FROM legacy_user_reference WHERE source_system='dems_legacy_hr' AND source_table='tbl_user' AND legacy_user_id='10' AND system_user_id='{$imported['id']}'"),'legacy user ID resolves deterministically');
            $this->same(1,$this->scalar($target,'SELECT COUNT(*) FROM legacy_user_organization_context'),'historical ASC context is preserved without a scope');
            $this->same(1,$this->scalar($target,"SELECT COUNT(*) FROM legacy_user_access_metadata WHERE legacy_subject_variable='admin'"),'legacy access is metadata only');
            $generated=$target->query("SELECT * FROM system_user WHERE username='legacy.hr.11'")->fetch();
            $this->same('HISTORICAL',$generated['identity_type'],'generated username belongs to a historical identity');
            $this->same('DISABLED',$generated['account_status'],'generated historical identity is disabled');
            $this->same(0,(int)$generated['enabled'],'generated historical identity cannot log in');
            $this->same(null,$generated['password_hash'],'generated historical identity has no password hash');
            $this->same(1,$this->scalar($target,"SELECT COUNT(*) FROM legacy_user_reference WHERE legacy_user_id='11' AND legacy_username IS NULL"),'original missing username remains traceable');
            $this->same(1,$this->scalar($target,"SELECT COUNT(*) FROM legacy_user_reference WHERE legacy_user_id='10' AND legacy_created_by_user_id='10'"),'created_location is preserved as legacy creator user ID');
            $this->same(0,$this->scalar($target,"SELECT COUNT(*) FROM subject_master WHERE system_key='ADMIN'"),'legacy ADMIN subject is not a central work subject');
            $this->same($roleBefore,$this->scalar($target,'SELECT COUNT(*) FROM user_account_role'),'no role granted');$this->same($scopeBefore,$this->scalar($target,'SELECT COUNT(*) FROM user_account_scope'),'no scope granted');
            $this->same(0,$this->scalar($target,'SELECT COUNT(*) FROM arpa_division_appointment'),'no appointment imported');$this->same(0,$this->scalar($target,'SELECT COUNT(*) FROM arpa_subject_assignment'),'no ARPA assignment imported');
            $this->same($currentBefore,$target->query("SELECT username,password_hash,account_status,enabled FROM system_user WHERE id='{$currentId}'")->fetch(),'existing account remains unchanged');
            $rerun=(new LegacyUserMigrationService($source,$target,true,10))->run();
            $this->same(0,$rerun['new_users_to_create'],'rerun would create no duplicate');$this->same(2,$rerun['already_migrated'],'rerun resolves existing mappings');
            $this->same(1,$this->scalar($target,"SELECT COUNT(*) FROM system_user WHERE username='legacy.reviewer'"),'idempotent rerun retains one target identity');
            $this->same(1,$this->scalar($target,"SELECT COUNT(*) FROM system_user WHERE username='legacy.hr.11'"),'idempotent rerun retains one generated identity');
        }finally{
            if(isset($source))$source=null;if(isset($target))$target=null;
            $server->exec("DROP DATABASE IF EXISTS `{$sourceDb}`");$server->exec("DROP DATABASE IF EXISTS `{$targetDb}`");
        }
    }

    private function createSourceFixture(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE tbl_user(id INT PRIMARY KEY,username VARCHAR(50) NULL UNIQUE,user_inname VARCHAR(1000) NOT NULL,user_nic VARCHAR(20) NULL,email VARCHAR(100) NULL,tp_number VARCHAR(20) NULL,password VARCHAR(255) NULL,status INT NOT NULL,user_role INT NULL,created_location INT NULL,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL);
CREATE TABLE tbl_role(auto_id INT PRIMARY KEY,role_name VARCHAR(200),user_level INT,status INT,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL);
CREATE TABLE tbl_user_level(auto_id INT PRIMARY KEY,user_level_name VARCHAR(200),status INT,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL);
CREATE TABLE tbl_user_has_asc(id INT PRIMARY KEY,user_id INT NULL,location_id INT NULL,status INT NOT NULL);
CREATE TABLE tbl_user_has_district(id INT PRIMARY KEY,user_id INT NULL,location_id INT NULL,status INT NOT NULL);
CREATE TABLE tbl_user_has_arpa(id INT PRIMARY KEY,user_id INT NULL,location_id INT NULL,status INT NOT NULL);
CREATE TABLE tbl_user_location(id INT PRIMARY KEY,user_id INT NULL,user_level_id INT NULL,location_id INT NULL,status INT NOT NULL);
CREATE TABLE tbl_subject(id INT PRIMARY KEY,subject_name VARCHAR(200),variable VARCHAR(100),subject_status INT);
CREATE TABLE tbl_user_has_subject(auto_id INT PRIMARY KEY,user_id INT NOT NULL,subject_id INT NOT NULL,status INT NOT NULL);
CREATE TABLE tbl_user_role(UserRoleID INT PRIMARY KEY,user_id INT,role_id INT);
CREATE TABLE tbl_officer(officer_id INT PRIMARY KEY,nic VARCHAR(20));
CREATE TABLE tbl_officer_apoint(auto_id INT PRIMARY KEY,asc_varify_by INT NULL,asc_approve_by INT NULL,district_varify_by INT NULL,district_approve_by INT NULL,national_varify_by INT NULL,national_approve_by INT NULL);
CREATE TABLE tbl_officer_apoint_2026 LIKE tbl_officer_apoint;");
        $pdo->exec("INSERT INTO tbl_user_level VALUES(5,'Agrarian Service Center',1,NOW(),NOW());
INSERT INTO tbl_role VALUES(7,'ASC Admin',5,1,NOW(),NOW());
INSERT INTO tbl_user VALUES(10,'legacy.reviewer','Legacy Reviewer','901234567V','84012c2f90e986a04b8f2d8235f84f1b27a13e4b503c52f244324a28765d5818','0712345678','DO-NOT-IMPORT',1,7,10,NOW(),NOW());
INSERT INTO tbl_user VALUES(11,NULL,'Historical Without Login',NULL,'20df888c5bed9c7d0945e11f417ec9a407f034621e1171d9283753a8b59f2e10',NULL,'ALSO-DO-NOT-IMPORT',0,7,10,NOW(),NOW());
INSERT INTO tbl_user_has_asc VALUES(1,10,200,1);
INSERT INTO tbl_subject VALUES(1,'ADMIN','admin',1);
INSERT INTO tbl_user_has_subject VALUES(1,10,1,1);
INSERT INTO tbl_officer_apoint VALUES(1,10,11,NULL,NULL,NULL,NULL);");
    }

    private function migrationFiles(): array{$files=glob(BASE_PATH.'/database/migrations/*.sql')?:[];sort($files);return $files;}
    private function server(): PDO{$cfg=config('database');return new PDO("mysql:host={$cfg['host']};port={$cfg['port']};charset={$cfg['charset']}",$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);}
    private function database(string $name): PDO{$cfg=config('database');return new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$name};charset={$cfg['charset']}",$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);}
    private function state(PDO $pdo): array{return ['users'=>$this->scalar($pdo,'SELECT COUNT(*) FROM system_user'),'refs'=>$this->scalar($pdo,'SELECT COUNT(*) FROM legacy_user_reference'),'runs'=>$this->scalar($pdo,'SELECT COUNT(*) FROM legacy_user_migration_run'),'issues'=>$this->scalar($pdo,'SELECT COUNT(*) FROM legacy_user_migration_issue'),'contexts'=>$this->scalar($pdo,'SELECT COUNT(*) FROM legacy_user_organization_context'),'access'=>$this->scalar($pdo,'SELECT COUNT(*) FROM legacy_user_access_metadata'),'roles'=>$this->scalar($pdo,'SELECT COUNT(*) FROM user_account_role'),'scopes'=>$this->scalar($pdo,'SELECT COUNT(*) FROM user_account_scope'),'appointments'=>$this->scalar($pdo,'SELECT COUNT(*) FROM arpa_division_appointment')];}
    private function scalar(PDO $pdo,string $sql): int{return (int)$pdo->query($sql)->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message): void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
}

exit((new LegacyUserMigrationTest())->run());
