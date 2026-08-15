<?php
declare(strict_types=1);

use App\Services\LegacyOfficer\LegacyArpaOfficerDesignationClassBackfillService;
use App\Services\LegacyOfficer\LegacyOfficerGradeMapper;

require dirname(__DIR__) . '/bootstrap.php';

final class LegacyArpaOfficerDesignationClassBackfillTest
{
    private PDO $server;
    private string $sourceDatabase;
    private string $targetDatabase;
    private array $reports = [];
    private int $assertions = 0;

    public function run(): int
    {
        $this->connect();
        try {
            $this->createFixture();
            $this->testMasterMigration();
            $this->testGradeMapper();
            $this->testBackfill();
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
        echo "LegacyArpaOfficerDesignationClassBackfillTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function connect(): void
    {
        $config = config('database');
        $this->server = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
            $config['username'], $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
        );
        $suffix = strtolower(bin2hex(random_bytes(5)));
        $this->sourceDatabase = 'dems_test_arpa_backfill_source_' . $suffix;
        $this->targetDatabase = 'dems_test_arpa_backfill_target_' . $suffix;
    }

    private function createFixture(): void
    {
        $this->server->exec("CREATE DATABASE `{$this->sourceDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->server->exec("CREATE DATABASE `{$this->targetDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $source = $this->pdo($this->sourceDatabase);
        $target = $this->pdo($this->targetDatabase, true);
        $source->exec('CREATE TABLE tbl_officer (officer_id INT PRIMARY KEY, grade VARCHAR(50) NULL) ENGINE=InnoDB');
        $target->exec('CREATE TABLE designation (
            id CHAR(36) PRIMARY KEY,dad_number VARCHAR(20) UNIQUE,system_key VARCHAR(80) UNIQUE,name_en VARCHAR(150),
            display_order INT,designation_level VARCHAR(20),active TINYINT,effective_from DATE,approval_status VARCHAR(30)
        ) ENGINE=InnoDB');
        $target->exec("INSERT INTO designation VALUES ('preserved-designation-id','72003-0000003','ARPA_OFFICER','ARPA Officer',30,'MAIN',1,'2020-01-01','APPROVED')");
        $target->exec('CREATE TABLE officer_class (
            id CHAR(36) PRIMARY KEY,dad_number VARCHAR(20) UNIQUE,system_key VARCHAR(80) UNIQUE,name_en VARCHAR(150),
            name_si VARCHAR(255) NULL,name_ta VARCHAR(255) NULL,description TEXT NULL,display_order INT,active TINYINT,
            effective_from DATE,effective_to DATE NULL,approval_status VARCHAR(30),created_by CHAR(36) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
        $target->exec('CREATE TABLE number_category (
            id CHAR(36) PRIMARY KEY,category_key VARCHAR(80) UNIQUE,category_code VARCHAR(5),next_value BIGINT,active TINYINT
        ) ENGINE=InnoDB');
        $target->exec("INSERT INTO number_category VALUES ('class-category','OFFICER_CLASS','72004',1,1),('officer-category','OFFICER','70045',5000,1)");
        $target->exec('CREATE TABLE number_allocation (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,category_id CHAR(36),allocated_number VARCHAR(20) UNIQUE,allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
        $target->exec('CREATE TABLE officer (
            id CHAR(36) PRIMARY KEY,dad_number VARCHAR(20),primary_designation_id CHAR(36) NULL,class_id CHAR(36) NULL,
            updated_at TIMESTAMP NULL,version BIGINT DEFAULT 0
        ) ENGINE=InnoDB');
        $target->exec('CREATE TABLE legacy_officer_reference (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,source_system VARCHAR(80),source_table VARCHAR(80),legacy_officer_id VARCHAR(100),officer_id CHAR(36)
        ) ENGINE=InnoDB');
        foreach (['system_user','application_role','application_permission','application_role_permission','user_account_role','user_account_scope','officer_appointment','officer_appointment_history','officer_assignment','office_assignment','arpa_assignment','officer_location_assignment'] as $table) {
            $target->exec("CREATE TABLE `{$table}` (id BIGINT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB");
        }
    }

    private function testMasterMigration(): void
    {
        $target = $this->pdo($this->targetDatabase, true);
        $sql = file_get_contents(BASE_PATH . '/database/migrations/012_arpa_officer_designation_classes.sql');
        $target->exec($sql);
        $this->same('preserved-designation-id', $this->scalar($target, "SELECT id FROM designation WHERE system_key='ARPA_OFFICER'"), 'designation ID is preserved');
        $this->same('72003-0000003', $this->scalar($target, "SELECT dad_number FROM designation WHERE system_key='ARPA_OFFICER'"), 'designation DAD number is preserved');
        $this->same('Agriculture Research and Production Assistant', $this->scalar($target, "SELECT name_en FROM designation WHERE system_key='ARPA_OFFICER'"), 'designation display name is renamed');
        $this->same(1, $this->scalar($target, "SELECT COUNT(*) FROM designation WHERE system_key='ARPA_OFFICER'"), 'no duplicate designation is created');
        $this->same(3, $this->scalar($target, 'SELECT COUNT(*) FROM officer_class'), 'exactly three Officer Classes are created');
        $this->same('72004-0000001', $this->scalar($target, "SELECT dad_number FROM officer_class WHERE system_key='CLASS_I'"), 'Class I enterprise number');
        $this->same('72004-0000002', $this->scalar($target, "SELECT dad_number FROM officer_class WHERE system_key='CLASS_II'"), 'Class II enterprise number');
        $this->same('72004-0000003', $this->scalar($target, "SELECT dad_number FROM officer_class WHERE system_key='CLASS_III'"), 'Class III enterprise number');
        $this->same(3, $this->scalar($target, 'SELECT COUNT(*) FROM number_allocation'), 'class numbers are audited through number allocation');
        $this->same(4, $this->scalar($target, "SELECT next_value FROM number_category WHERE category_key='OFFICER_CLASS'"), 'Officer Class sequence advances exactly three times');
        $idsBefore = $this->scalar($target, 'SELECT GROUP_CONCAT(id ORDER BY system_key) FROM officer_class');
        $target->exec($sql);
        $this->same(3, $this->scalar($target, 'SELECT COUNT(*) FROM officer_class'), 'master migration rerun creates no duplicate classes');
        $this->same(3, $this->scalar($target, 'SELECT COUNT(*) FROM number_allocation'), 'master migration rerun allocates no new numbers');
        $this->same(4, $this->scalar($target, "SELECT next_value FROM number_category WHERE category_key='OFFICER_CLASS'"), 'master migration rerun does not advance sequence');
        $this->same($idsBefore, $this->scalar($target, 'SELECT GROUP_CONCAT(id ORDER BY system_key) FROM officer_class'), 'class IDs are stable on rerun');
    }

    private function testGradeMapper(): void
    {
        $this->same('CLASS_I', LegacyOfficerGradeMapper::classKey('Grade1'), 'Grade1 maps to Class I');
        $this->same('CLASS_I', LegacyOfficerGradeMapper::classKey(' Grade 1 '), 'Grade 1 maps to Class I after trim');
        $this->same('CLASS_II', LegacyOfficerGradeMapper::classKey('Grade2'), 'Grade2 maps to Class II');
        $this->same('CLASS_II', LegacyOfficerGradeMapper::classKey(' Grade 2 '), 'Grade 2 maps to Class II after trim');
        $this->same('CLASS_III', LegacyOfficerGradeMapper::classKey('Grade3'), 'Grade3 maps to Class III');
        $this->same('CLASS_III', LegacyOfficerGradeMapper::classKey(' Grade 3 '), 'Grade 3 maps to Class III after trim');
        $this->same(null, LegacyOfficerGradeMapper::classKey('Select'), 'Select maps to NULL');
        $this->same(true, LegacyOfficerGradeMapper::isUnknown('Unexpected'), 'unknown grade is identified');
    }

    private function testBackfill(): void
    {
        $source = $this->pdo($this->sourceDatabase);
        $target = $this->pdo($this->targetDatabase);
        $source->exec("INSERT INTO tbl_officer VALUES
            (1,'Grade1'),(2,' Grade 1 '),(3,'Grade2'),(4,'Grade 2'),
            (5,'Grade3'),(6,' Grade 3 '),(7,'Select'),(8,'Unexpected')");
        for ($id = 1; $id <= 8; $id++) {
            $target->prepare('INSERT INTO officer(id,dad_number) VALUES (?,?)')->execute(['officer-' . $id, sprintf('70045-%07d', $id)]);
            $target->prepare("INSERT INTO legacy_officer_reference(source_system,source_table,legacy_officer_id,officer_id) VALUES ('AGRARIANADMIN_HR','tbl_officer',?,?)")
                ->execute([(string)$id, 'officer-' . $id]);
        }
        $target->exec("INSERT INTO officer(id,dad_number,primary_designation_id,class_id) VALUES ('unrelated','70045-9999999','unrelated-designation','unrelated-class')");

        $before = $this->state($target);
        $dry = (new LegacyArpaOfficerDesignationClassBackfillService($source, $target, true, 3))->run();
        $this->reports[] = $dry['report_path'];
        $this->same(8, $dry['legacy_references_found'], 'authoritative legacy references define population');
        $this->same(8, $dry['officers_found'], 'all referenced Officers are found');
        $this->same(8, $dry['would_set_designation'], 'all migrated Officers need designation');
        $this->same(2, $dry['class_i'], 'two Class I grade spellings');
        $this->same(2, $dry['class_ii'], 'two Class II grade spellings');
        $this->same(2, $dry['class_iii'], 'two Class III grade spellings');
        $this->same(1, $dry['class_null_select'], 'Select remains NULL');
        $this->same(1, $dry['unknown_grades'], 'unknown grade remains NULL with warning');
        $this->same(8, $dry['would_update'], 'all migrated Officers would update');
        $this->same(0, $dry['errors'], 'dry-run has no errors');
        $this->same($before, $this->state($target), 'dry-run performs zero database writes');

        $execute = (new LegacyArpaOfficerDesignationClassBackfillService($source, $target, false, 3))->run();
        $this->reports[] = $execute['report_path'];
        $this->same(8, $execute['updated'], 'execute updates all referenced Officers');
        $this->same(8, $this->scalar($target, "SELECT COUNT(*) FROM officer WHERE id LIKE 'officer-%' AND primary_designation_id='preserved-designation-id'"), 'all migrated Officers get ARPA designation');
        $this->same(2, $this->classCount($target, 'CLASS_I'), 'Class I backfill count');
        $this->same(2, $this->classCount($target, 'CLASS_II'), 'Class II backfill count');
        $this->same(2, $this->classCount($target, 'CLASS_III'), 'Class III backfill count');
        $this->same(2, $this->scalar($target, "SELECT COUNT(*) FROM officer WHERE id IN ('officer-7','officer-8') AND class_id IS NULL"), 'Select and unknown grades remain NULL');
        $this->same('unrelated-designation', $this->scalar($target, "SELECT primary_designation_id FROM officer WHERE id='unrelated'"), 'unrelated Officer is not modified');
        foreach (['system_user','application_role','application_permission','application_role_permission','user_account_role','user_account_scope','officer_appointment','officer_appointment_history','officer_assignment','office_assignment','arpa_assignment','officer_location_assignment'] as $table) {
            $this->same(0, $this->scalar($target, "SELECT COUNT(*) FROM `{$table}`"), "{$table} remains untouched");
        }
        $rerunBefore = $this->state($target);
        $rerun = (new LegacyArpaOfficerDesignationClassBackfillService($source, $target, true, 3))->run();
        $this->reports[] = $rerun['report_path'];
        $this->same(8, $rerun['already_correct_designation'], 'designation is already correct on rerun');
        $this->same(0, $rerun['would_update'], 'execute is idempotent');
        $this->same($rerunBefore, $this->state($target), 'idempotent dry-run writes nothing');
    }

    private function classCount(PDO $pdo, string $key): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM officer o JOIN officer_class c ON c.id=o.class_id WHERE c.system_key=?');
        $stmt->execute([$key]);
        return (int)$stmt->fetchColumn();
    }

    private function state(PDO $pdo): array
    {
        return [
            'officers' => $this->scalar($pdo, 'SELECT COUNT(*) FROM officer'),
            'officer_values' => $this->scalar($pdo, "SELECT GROUP_CONCAT(CONCAT(id,':',COALESCE(primary_designation_id,'NULL'),':',COALESCE(class_id,'NULL')) ORDER BY id) FROM officer"),
            'references' => $this->scalar($pdo, 'SELECT COUNT(*) FROM legacy_officer_reference'),
            'allocations' => $this->scalar($pdo, 'SELECT COUNT(*) FROM number_allocation'),
            'officer_next' => $this->scalar($pdo, "SELECT next_value FROM number_category WHERE category_key='OFFICER'"),
            'class_next' => $this->scalar($pdo, "SELECT next_value FROM number_category WHERE category_key='OFFICER_CLASS'"),
        ];
    }

    private function pdo(string $database, bool $multi = false): PDO
    {
        $config = config('database');
        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $database),
            $config['username'], $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => $multi]
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
    exit((new LegacyArpaOfficerDesignationClassBackfillTest())->run());
} catch (Throwable $e) {
    fwrite(STDERR, 'LegacyArpaOfficerDesignationClassBackfillTest failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
