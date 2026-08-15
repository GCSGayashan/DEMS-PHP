<?php
declare(strict_types=1);

use App\Core\NicNormalizer;
use App\Services\LegacyOfficer\LegacyArpaOfficerMigrationService;

require dirname(__DIR__) . '/bootstrap.php';

final class LegacyArpaOfficerMigrationTest
{
    private PDO $server;
    private string $sourceDatabase;
    private string $targetDatabase;
    private array $reports = [];
    private int $assertions = 0;

    public function run(): int
    {
        $this->connectServer();
        try {
            $this->createFixtureSchemas();
            $this->testExpectedPopulationAndZeroWriteDryRun();
            $this->testExecuteMappingAndIdempotency();
        } finally {
            $this->dropFixture();
            foreach ($this->reports as $report) {
                foreach ([$report, is_string($report) ? substr($report, 0, -4) . '.json' : null] as $file) {
                    if (is_string($file) && is_file($file)) {
                        unlink($file);
                    }
                }
            }
        }
        echo "LegacyArpaOfficerMigrationTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function connectServer(): void
    {
        $config = config('database');
        $this->server = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $suffix = strtolower(bin2hex(random_bytes(5)));
        $this->sourceDatabase = 'dems_test_arpa_officer_source_' . $suffix;
        $this->targetDatabase = 'dems_test_arpa_officer_target_' . $suffix;
    }

    private function createFixtureSchemas(): void
    {
        $this->server->exec("CREATE DATABASE `{$this->sourceDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->server->exec("CREATE DATABASE `{$this->targetDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $source = $this->pdo($this->sourceDatabase);
        $target = $this->pdo($this->targetDatabase);
        $source->exec('CREATE TABLE tbl_designation (designation_id INT PRIMARY KEY, designation_name VARCHAR(500)) ENGINE=InnoDB');
        $source->exec('CREATE TABLE tbl_officer (
            officer_id INT PRIMARY KEY, designation_id INT NULL, full_name VARCHAR(1000), name_with_initial VARCHAR(1000),
            nic VARCHAR(100), residential_address VARCHAR(1000), birth_day VARCHAR(20) NULL, gender VARCHAR(20),
            tp_no VARCHAR(30), whatsapp_no VARCHAR(30), email_address VARCHAR(1000), first_appoint_date VARCHAR(20) NULL,
            grade VARCHAR(50) NULL, officer_status INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL
        ) ENGINE=InnoDB');
        $source->exec('CREATE TABLE tbl_officer_apoint_2026 (
            auto_id BIGINT AUTO_INCREMENT PRIMARY KEY, officer_id INT NOT NULL, officer_level VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB');
        $source->exec("INSERT INTO tbl_designation VALUES (1,'Agriculture Research and Production Assistant')");

        $target->exec('CREATE TABLE officer_status (id CHAR(36) PRIMARY KEY, system_key VARCHAR(80) UNIQUE, active TINYINT NOT NULL) ENGINE=InnoDB');
        $target->exec("INSERT INTO officer_status VALUES ('status-active','ACTIVE',1),('status-inactive','INACTIVE',1)");
        $target->exec('CREATE TABLE designation (id CHAR(36) PRIMARY KEY,system_key VARCHAR(80) UNIQUE,active TINYINT,approval_status VARCHAR(30)) ENGINE=InnoDB');
        $target->exec("INSERT INTO designation VALUES ('designation-arpa','ARPA_OFFICER',1,'APPROVED')");
        $target->exec('CREATE TABLE officer_class (id CHAR(36) PRIMARY KEY,system_key VARCHAR(80) UNIQUE,active TINYINT,approval_status VARCHAR(30)) ENGINE=InnoDB');
        $target->exec("INSERT INTO officer_class VALUES ('class-i','CLASS_I',1,'APPROVED'),('class-ii','CLASS_II',1,'APPROVED'),('class-iii','CLASS_III',1,'APPROVED')");
        $target->exec('CREATE TABLE number_category (
            id CHAR(36) PRIMARY KEY, category_key VARCHAR(80) UNIQUE, category_code VARCHAR(5), next_value BIGINT NOT NULL, active TINYINT NOT NULL
        ) ENGINE=InnoDB');
        $target->exec("INSERT INTO number_category VALUES ('category-officer','OFFICER','70045',1,1)");
        $target->exec('CREATE TABLE number_allocation (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, category_id CHAR(36), allocated_number VARCHAR(20) UNIQUE, allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
        $target->exec("CREATE TABLE officer (
            id CHAR(36) PRIMARY KEY,dad_number VARCHAR(20) NOT NULL UNIQUE,nic VARCHAR(20) NULL,
            nic_normalized VARCHAR(20) NULL UNIQUE,nic_match_key VARCHAR(64) NULL UNIQUE,
            employee_number VARCHAR(100) NULL UNIQUE,title_id CHAR(36) NULL,name_with_initials VARCHAR(255) NULL,
            full_name_en VARCHAR(255) NULL,full_name_si VARCHAR(255) NULL,full_name_ta VARCHAR(255) NULL,
            date_of_birth DATE NULL,expected_retirement_date DATE NULL,gender ENUM('MALE','FEMALE') NULL,
            civil_status_id CHAR(36) NULL,permanent_address TEXT NULL,temporary_address TEXT NULL,
            primary_mobile VARCHAR(20) NULL,alternative_mobile VARCHAR(20) NULL,personal_email VARCHAR(255) NULL UNIQUE,
            official_email VARCHAR(255) NULL UNIQUE,photograph_path VARCHAR(500) NULL,initial_appointment_date DATE NULL,
            appointment_nature_id CHAR(36) NULL,primary_designation_id CHAR(36) NULL,class_id CHAR(36) NULL,
            arpa_service_permanency ENUM('PERMANENT_IN_SERVICE','NOT_PERMANENT_IN_SERVICE') NULL,
            officer_status_id CHAR(36) NOT NULL,primary_office_id CHAR(36) NULL,effective_from DATE NOT NULL,
            effective_to DATE NULL,operational_status VARCHAR(30) NOT NULL,approval_status VARCHAR(30) NOT NULL,
            created_by CHAR(36) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_by CHAR(36) NULL,
            updated_at TIMESTAMP NULL,version BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB");
        $target->exec('CREATE TABLE legacy_officer_migration_run (
            id CHAR(36) PRIMARY KEY,source_system VARCHAR(80),source_table VARCHAR(80),started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,status VARCHAR(40),batch_size INT,source_appointment_row_count INT DEFAULT 0,
            distinct_source_officer_count INT DEFAULT 0,matched_source_master_count INT DEFAULT 0,existing_reference_count INT DEFAULT 0,
            would_create_count INT DEFAULT 0,would_update_count INT DEFAULT 0,created_count INT DEFAULT 0,updated_count INT DEFAULT 0,
            skipped_count INT DEFAULT 0,warning_count INT DEFAULT 0,error_count INT DEFAULT 0,report_path VARCHAR(500),
            summary_json JSON,zero_write_verification_json JSON
        ) ENGINE=InnoDB');
        $target->exec('CREATE TABLE legacy_officer_reference (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,source_system VARCHAR(80),source_table VARCHAR(80),legacy_officer_id VARCHAR(100),
            officer_id CHAR(36),legacy_nic VARCHAR(100),legacy_designation_id VARCHAR(100),legacy_designation_name VARCHAR(500),
            legacy_officer_status VARCHAR(50),legacy_payload_json JSON,migration_run_id CHAR(36),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_source(source_system,source_table,legacy_officer_id),UNIQUE KEY uq_target(officer_id)
        ) ENGINE=InnoDB');
        $target->exec('CREATE TABLE legacy_officer_migration_issue (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,migration_run_id CHAR(36),legacy_officer_id VARCHAR(100),issue_type VARCHAR(80),
            severity VARCHAR(20),message TEXT,source_payload_json JSON,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
        foreach (['system_user', 'application_role', 'application_permission', 'user_account_role', 'user_account_scope', 'office', 'location', 'location_relationship', 'officer_appointment', 'officer_appointment_history', 'officer_assignment', 'office_assignment', 'arpa_assignment', 'officer_location_assignment'] as $table) {
            $target->exec("CREATE TABLE `{$table}` (id BIGINT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB");
        }
    }

    private function testExpectedPopulationAndZeroWriteDryRun(): void
    {
        $this->same('851234567V', NicNormalizer::normalize(' 851234567v '), 'NIC normalization reuses trim and uppercase behavior');
        $this->same('760952605V', NicNormalizer::normalize(' 760952605 v / '), 'safe old-NIC whitespace and trailing slash cleanup');
        $this->same('19710322449', NicNormalizer::normalize('19710322449'), 'NIC cleanup never inserts a missing numeric digit');
        $this->same(false, NicNormalizer::isValid(NicNormalizer::normalize('19710322449')), 'an invalid numeric NIC remains invalid');
        $this->same('19710322449/', NicNormalizer::normalize('19710322449/'), 'trailing slash remains when removal would not produce a valid NIC');
        $this->same('198512304567', NicNormalizer::matchKey('851234567V'), 'old NIC maps to canonical match key');
        $this->same(NicNormalizer::matchKey('851234567V'), NicNormalizer::matchKey('198512304567'), 'old and new NIC forms share a match key');
        $source = $this->pdo($this->sourceDatabase);
        $target = $this->pdo($this->targetDatabase);
        $digits = '(SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        $source->exec("INSERT INTO tbl_officer
            SELECT n,1,CONCAT('Full Name ',n),CONCAT('Initial Name ',n),CAST(199000000000+n AS CHAR),
                   CONCAT('Address ',n),'1990-01-01',IF(MOD(n,2)=0,'Female','Male'),'0712345678',NULL,NULL,
                   '2020-01-01','Grade1',1,'2025-01-22 09:12:53','2025-01-22 09:12:53'
            FROM (SELECT 1+(a.n+10*b.n+100*c.n+1000*d.n) n FROM {$digits} a CROSS JOIN {$digits} b CROSS JOIN {$digits} c CROSS JOIN {$digits} d) numbers
            WHERE n<=5286");
        $source->exec("INSERT INTO tbl_officer_apoint_2026(officer_id,officer_level) SELECT officer_id,'ARPA Division' FROM tbl_officer");
        $source->exec("INSERT INTO tbl_officer_apoint_2026(officer_id,officer_level) SELECT officer_id,'ARPA Division' FROM tbl_officer WHERE officer_id<=3871");
        $source->exec("INSERT INTO tbl_officer_apoint_2026(officer_id,officer_level) VALUES (1,'District')");

        $before = $this->allTargetCounts($target);
        $dry = (new LegacyArpaOfficerMigrationService($source, $target, true, 500, '2026-08-11'))->run();
        $this->reports[] = $dry['report_path'];
        $this->same(9157, $dry['source_appointment_rows'], '9157 appointment rows selected by exact ARPA Division level');
        $this->same(5286, $dry['distinct_source_officers'], 'appointment rows collapse to 5286 distinct Officers');
        $this->same(5286, $dry['matched_source_officer_masters'], 'fixture has all expected Officer masters');
        $this->same(5286, $dry['would_create'], 'all fixture Officers would be created');
        $this->same(5286, $dry['class_i'], 'future clean import maps every Grade1 fixture Officer to Class I');
        $this->same($before, $this->allTargetCounts($target), 'dry-run performs zero target writes including audit and number tables');

        $source->exec('TRUNCATE TABLE tbl_officer_apoint_2026');
        $source->exec('TRUNCATE TABLE tbl_officer');
    }

    private function testExecuteMappingAndIdempotency(): void
    {
        $source = $this->pdo($this->sourceDatabase);
        $target = $this->pdo($this->targetDatabase);
        $insert = $source->prepare('INSERT INTO tbl_officer VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $insert->execute([1,1,'Active Full','A Active','901234567V',null,'1990-05-03','Male','0711111111',null,null,null,'Grade1',1,'2025-01-22 09:12:53','2025-01-22 09:12:53']);
        $insert->execute([2,1,'Inactive Full','I Inactive','199288800001','Address 2','1992-12-01','Female','0722222222','0772222222','inactive@example.test','2015-02-03','Grade2',0,'2025-01-22 09:12:53','2025-01-22 09:12:53']);
        $insert->execute([3,1,'Unknown Full','U Unknown',' BAD-NIC ','Address 3','0000-00-00','Select','0733333333','', 'shared@example.test','0000-00-00','Select',1,'2025-01-22 09:12:53','2025-01-22 09:12:53']);
        $insert->execute([4,1,'Existing Full','E Existing','851234567V','Address 4','1985-05-02','Male','0744444444',null,'shared@example.test','2010-01-01','Grade3',1,'2025-01-22 09:12:53','2025-01-22 09:12:53']);
        $insert->execute([5,1,'Cleaned Full','C Cleaned','760952605 v /','Address 5','1976-04-04','Male','0755555555',null,'broken@example','2011-01-01','Grade 1',1,'2025-01-22 09:12:53','2025-01-22 09:12:53']);
        $source->exec("INSERT INTO tbl_officer_apoint_2026(officer_id,officer_level) VALUES
            (1,'ARPA Division'),(1,'ARPA Division'),(2,'ARPA Division'),(3,'ARPA Division'),(4,'ARPA Division'),(5,'ARPA Division'),(2,'District')");
        $target->prepare("INSERT INTO officer
            (id,dad_number,nic,nic_normalized,nic_match_key,name_with_initials,full_name_en,date_of_birth,gender,primary_mobile,
             officer_status_id,effective_from,operational_status,approval_status)
            VALUES ('existing-officer','70045-9999999','198512304567','198512304567',?,'Existing','Existing','1985-05-02','MALE','0744444444','status-active','2020-01-01','ACTIVE','APPROVED')")
            ->execute([NicNormalizer::matchKey('198512304567')]);

        $before = $this->allTargetCounts($target);
        $dry = (new LegacyArpaOfficerMigrationService($source, $target, true, 2, '2026-08-11'))->run();
        $this->reports[] = $dry['report_path'];
        $this->same(6, $dry['source_appointment_rows'], 'non-ARPA appointment row is excluded');
        $this->same(5, $dry['distinct_source_officers'], 'duplicate appointments do not duplicate Officers');
        $this->same(4, $dry['would_create'], 'every unmatched Officer remains eligible for creation');
        $this->same(1, $dry['would_update'], 'old/new NIC match attaches one legacy reference');
        $this->same(1, $dry['legacy_inactive'], 'inactive Officer remains selected');
        $this->same(1, $dry['missing_email'], 'missing email accepted');
        $this->same(1, $dry['missing_address'], 'missing address accepted');
        $this->same(1, $dry['missing_initial_appointment_date'], 'missing first appointment date accepted');
        $this->same(1, $dry['invalid_initial_appointment_date'], 'zero first appointment date is separately reported');
        $this->same(1, $dry['invalid_gender'], 'unknown gender reported');
        $this->same(1, $dry['invalid_dob'], 'zero birth date is reported and accepted as NULL');
        $this->same(1, $dry['invalid_nic'], 'invalid NIC reported without fabricating a match key');
        $this->same(1, $dry['invalid_nic_fields_nulled'], 'invalid NIC is planned as NULL without skipping the Officer');
        $this->same(1, $dry['safely_cleaned_nic'], 'safely cleanable V/X NIC is accepted');
        $this->same(0, $dry['skipped'], 'field quality causes no Officer skip');
        $this->same(0, $dry['errors'], 'field quality produces warnings rather than errors');
        $this->same($before, $this->allTargetCounts($target), 'compact dry-run also performs zero writes');

        $execute = (new LegacyArpaOfficerMigrationService($source, $target, false, 2, '2026-08-11'))->run();
        $this->reports[] = $execute['report_path'];
        $this->same(4, $execute['created'], 'execute creates every unmatched Officer master; errors='.json_encode($execute['error_messages']));
        $this->same(1, $execute['updated'], 'execute attaches reference to matched Officer without overwriting it');
        $this->same(5, $this->scalar($target, 'SELECT COUNT(*) FROM legacy_officer_reference'), 'one traceability row per selected Officer');
        $this->same(4, $this->scalar($target, 'SELECT COUNT(*) FROM number_allocation'), 'every created Officer allocates an enterprise number');
        $this->same('70045-0000001', $this->scalar($target, "SELECT MIN(dad_number) FROM officer WHERE dad_number LIKE '70045-0%'"), 'OFFICER category allocates 70045 format');
        $this->same('70045-9999999', $this->scalar($target, "SELECT dad_number FROM officer WHERE id='existing-officer'"), 'existing target DAD number is preserved');
        $this->same(1, $this->scalar($target, "SELECT COUNT(*) FROM officer WHERE operational_status='INACTIVE'"), 'inactive source Officer is retained as inactive');
        $this->same(1, $this->scalar($target, "SELECT COUNT(*) FROM officer WHERE nic IS NULL AND nic_normalized IS NULL AND nic_match_key IS NULL AND gender IS NULL AND date_of_birth IS NULL"), 'invalid legacy identity fields become NULL without losing the Officer');
        $this->same(' BAD-NIC ', $this->scalar($target, "SELECT legacy_nic FROM legacy_officer_reference WHERE legacy_officer_id='3'"), 'exact raw invalid NIC remains traceable');
        $this->same('901234567V', $this->scalar($target, "SELECT nic FROM officer o JOIN legacy_officer_reference r ON r.officer_id=o.id WHERE r.legacy_officer_id='1'"), 'valid old NIC imports');
        $this->same(199288800001, $this->scalar($target, "SELECT nic FROM officer o JOIN legacy_officer_reference r ON r.officer_id=o.id WHERE r.legacy_officer_id='2'"), 'valid new NIC imports');
        $this->same('760952605V', $this->scalar($target, "SELECT nic FROM officer o JOIN legacy_officer_reference r ON r.officer_id=o.id WHERE r.legacy_officer_id='5'"), 'safe V/X cleanup is stored canonically');
        $this->same(3, $this->scalar($target, "SELECT COUNT(*) FROM officer o JOIN legacy_officer_reference r ON r.officer_id=o.id WHERE r.legacy_officer_id IN ('1','3','5') AND o.personal_email IS NULL"), 'missing, shared, and invalid emails become NULL');
        $this->same(1, $this->scalar($target, "SELECT COUNT(*) FROM officer o JOIN legacy_officer_reference r ON r.officer_id=o.id WHERE r.legacy_officer_id='1' AND o.permanent_address IS NULL AND o.initial_appointment_date IS NULL"), 'missing address and appointment date become NULL');
        $this->same(5, $this->scalar($target, 'SELECT COUNT(*) FROM legacy_officer_reference r JOIN officer o ON o.id=r.officer_id WHERE o.dad_number IS NOT NULL'), 'every source Officer resolves to an enterprise DAD number');
        $this->same(4, $this->scalar($target, 'SELECT COUNT(*) FROM officer WHERE id<>\'existing-officer\' AND title_id IS NULL AND full_name_si IS NULL AND full_name_ta IS NULL AND expected_retirement_date IS NULL'), 'title, translations, and retirement date are not fabricated');
        $this->same(4, $this->scalar($target, "SELECT COUNT(*) FROM officer WHERE id<>'existing-officer' AND primary_designation_id='designation-arpa'"), 'future imports receive the ARPA Officer Master designation');
        $this->same(4, $this->scalar($target, 'SELECT COUNT(*) FROM officer WHERE id<>\'existing-officer\' AND appointment_nature_id IS NULL AND primary_office_id IS NULL'), 'appointment nature and primary office remain NULL');
        $this->same(2, $this->scalar($target, "SELECT COUNT(*) FROM officer WHERE id<>'existing-officer' AND class_id='class-i'"), 'future import maps Grade1 spellings to Class I');
        $this->same(1, $this->scalar($target, "SELECT COUNT(*) FROM officer WHERE id<>'existing-officer' AND class_id='class-ii'"), 'future import maps Grade2 to Class II');
        $this->same(1, $this->scalar($target, "SELECT COUNT(*) FROM officer WHERE id<>'existing-officer' AND class_id IS NULL"), 'future import leaves Select class NULL');
        $this->same(4, $this->scalar($target, 'SELECT COUNT(*) FROM officer WHERE id<>\'existing-officer\' AND employee_number IS NULL'), 'employee number is not invented');
        $this->same('1', (string)$this->scalar($target, "SELECT legacy_designation_id FROM legacy_officer_reference WHERE legacy_officer_id='2'"), 'legacy designation is retained only in traceability');
        $this->same('Agriculture Research and Production Assistant', $this->scalar($target, "SELECT legacy_designation_name FROM legacy_officer_reference WHERE legacy_officer_id='2'"), 'legacy designation name is retained');
        foreach (['system_user', 'application_role', 'application_permission', 'user_account_role', 'user_account_scope', 'office', 'location', 'location_relationship', 'officer_appointment', 'officer_appointment_history', 'officer_assignment', 'office_assignment', 'arpa_assignment', 'officer_location_assignment'] as $table) {
            $this->same(0, $this->scalar($target, "SELECT COUNT(*) FROM `{$table}`"), "{$table} remains untouched");
        }
        $rerunBefore = $this->allTargetCounts($target);
        $rerun = (new LegacyArpaOfficerMigrationService($source, $target, true, 2, '2026-08-11'))->run();
        $this->reports[] = $rerun['report_path'];
        $this->same(5, $rerun['already_migrated'], 'legacy reference is authoritative on rerun');
        $this->same(0, $rerun['would_create'], 'idempotent rerun would create no Officer');
        $this->same($rerunBefore, $this->allTargetCounts($target), 'idempotent dry rerun performs no writes');
    }

    private function allTargetCounts(PDO $pdo): array
    {
        $tables = ['officer', 'number_allocation', 'legacy_officer_migration_run', 'legacy_officer_reference', 'legacy_officer_migration_issue', 'system_user', 'application_role', 'application_permission', 'user_account_role', 'user_account_scope', 'office', 'location', 'location_relationship', 'officer_appointment', 'officer_assignment', 'office_assignment', 'arpa_assignment'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = $this->scalar($pdo, "SELECT COUNT(*) FROM `{$table}`");
        }
        return $counts;
    }

    private function pdo(string $database): PDO
    {
        $config = config('database');
        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $database),
            $config['username'], $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    }

    private function scalar(PDO $pdo, string $sql): mixed
    {
        $value = $pdo->query($sql)->fetchColumn();
        return is_numeric($value) ? (int)$value : $value;
    }

    private function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    private function dropFixture(): void
    {
        if (isset($this->server)) {
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->sourceDatabase}`");
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->targetDatabase}`");
        }
    }
}

try {
    exit((new LegacyArpaOfficerMigrationTest())->run());
} catch (Throwable $e) {
    fwrite(STDERR, 'LegacyArpaOfficerMigrationTest failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
