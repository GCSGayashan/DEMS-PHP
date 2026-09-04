<?php
declare(strict_types=1);

use App\Services\LegacyLocation\LegacyLocationMigrationService;
use App\Services\LegacyLocation\LegacyLocationRules;

require dirname(__DIR__) . '/bootstrap.php';

final class LegacyLocationMigrationTest
{
    private PDO $server;
    private string $sourceDatabase;
    private string $targetDatabase;
    private int $assertions = 0;

    public function run(): int
    {
        $this->testPureRules();
        $this->connectServer();
        try {
            $this->createFixtureDatabases();
            $this->testDryRunAndExecute();
        } finally {
            $this->dropFixtureDatabases();
        }
        echo "LegacyLocationMigrationTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function testPureRules(): void
    {
        $this->same('ACTIVE', LegacyLocationRules::normalizeStatus('1'), 'numeric active status');
        $this->same('ACTIVE', LegacyLocationRules::normalizeStatus(' active '), 'text active status');
        $this->same('INACTIVE', LegacyLocationRules::normalizeStatus('0'), 'numeric inactive status');
        $this->same(null, LegacyLocationRules::normalizeStatus('unknown'), 'unknown status');
        $this->same(null, LegacyLocationRules::clean('  '), 'empty multilingual value');
        $this->same('සිංහල', LegacyLocationRules::clean(' සිංහල '), 'multilingual trimming');
        $this->same('AB12', LegacyLocationRules::normalizeCode(' ab 12 '), 'code normalization');
        $this->same('2026-08-10', LegacyLocationRules::effectiveDate('0000-00-00 00:00:00', '2026-08-10'), 'zero-date fallback');
        $this->same('2020-01-02', LegacyLocationRules::effectiveDate('2020-01-02 12:13:14', '2026-08-10'), 'valid legacy date');
    }

    private function connectServer(): void
    {
        $cfg = config('database');
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['host'], $cfg['port']);
        $this->server = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $suffix = strtolower(bin2hex(random_bytes(5)));
        $this->sourceDatabase = 'dems_test_legacy_' . $suffix;
        $this->targetDatabase = 'dems_test_target_' . $suffix;
    }

    private function createFixtureDatabases(): void
    {
        $this->server->exec("CREATE DATABASE `{$this->sourceDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->server->exec("CREATE DATABASE `{$this->targetDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $source = $this->pdo($this->sourceDatabase);
        $target = $this->pdo($this->targetDatabase);
        $this->createSourceSchema($source);
        $this->seedSource($source);
        $this->createTargetSchema($target);
        $this->seedTarget($target);
    }

    private function createSourceSchema(PDO $pdo): void
    {
        $ddl = [
            'CREATE TABLE tbl_province (auto_id INT PRIMARY KEY, pro_id VARCHAR(20), pro_code VARCHAR(20), pro_name VARCHAR(100), pro_sname VARCHAR(100), pro_tname VARCHAR(100), pro_status VARCHAR(20), created_at VARCHAR(30), updated_at VARCHAR(30))',
            'CREATE TABLE tbl_district (auto_id INT PRIMARY KEY, dis_id VARCHAR(20), pro_id INT, dis_code VARCHAR(20), rdd_ro_id VARCHAR(20), dis_name VARCHAR(100), dis_sname VARCHAR(100), dis_tname VARCHAR(100), bank_code VARCHAR(20), branch_code VARCHAR(20), acc_no VARCHAR(30), dis_status VARCHAR(20), created_at VARCHAR(30), updated_at VARCHAR(30))',
            'CREATE TABLE tbl_ds (auto_id INT PRIMARY KEY, ds_code VARCHAR(20), pro_id INT, dis_id INT, ds_name VARCHAR(100), ds_sname VARCHAR(100), ds_tname VARCHAR(100), ds_status VARCHAR(20))',
            'CREATE TABLE tbl_asc (auto_id INT PRIMARY KEY, asc_id VARCHAR(20), dis_id INT, asc_code VARCHAR(20), asc_name VARCHAR(100), asc_sname VARCHAR(100), asc_tname VARCHAR(100), asc_latitude VARCHAR(30), asc_longitude VARCHAR(30), asc_status VARCHAR(20), created_at VARCHAR(30), updated_at VARCHAR(30))',
            'CREATE TABLE tbl_arpa (auto_id INT PRIMARY KEY, asc_id INT, arpa_code VARCHAR(20), arpa_name VARCHAR(100), arpa_sname VARCHAR(100), arpa_tname VARCHAR(100), arpa_status VARCHAR(20), created_at VARCHAR(30), updated_at VARCHAR(30))',
            'CREATE TABLE tbl_gnd (gnd_id INT PRIMARY KEY, arpa_id INT, dis_code VARCHAR(20), asc_code VARCHAR(20), gnd_ocode VARCHAR(20), gnd_code VARCHAR(20), gnd_lcode VARCHAR(20), gnd_name VARCHAR(100), gnd_sname VARCHAR(100), gnd_tname VARCHAR(100), gnd_status VARCHAR(20))',
            'CREATE TABLE tbl_ds_asc_join (auto_id INT PRIMARY KEY, ds_id INT, asc_id INT)',
        ];
        foreach ($ddl as $sql) {
            $pdo->exec($sql);
        }
    }

    private function seedSource(PDO $pdo): void
    {
        $sql = [
            "INSERT INTO tbl_province VALUES (1,'P01','01','North','උතුර','வடக்கு','1','2020-01-01 08:00:00',NULL)",
            "INSERT INTO tbl_district VALUES (10,'D10',1,'10',NULL,'Alpha District','ඇල්ෆා','ஆல்பா',NULL,NULL,NULL,'1','2020-02-01 08:00:00',NULL),(11,'D11',1,'11',NULL,'Beta District','බීටා','பீட்டா',NULL,NULL,NULL,'1','2020-02-02 08:00:00',NULL)",
            "INSERT INTO tbl_ds VALUES (100,'DS100',1,10,'Single DS','තනි','ஒற்றை','1'),(101,'DS101',1,10,'Second DS','දෙවන','இரண்டு','0'),(102,'DS102',1,11,'Cross DS','හරස්','குறுக்கு','1')",
            "INSERT INTO tbl_asc VALUES (200,'A200',10,'ASC1','Single ASC','එක','ஒன்று',NULL,NULL,'1','2020-03-01 00:00:00',NULL),(201,'A201',10,'ASC2','Ambiguous ASC','දෙක','இரண்டு',NULL,NULL,'1','2020-03-02 00:00:00',NULL),(202,'A202',10,'ASC3','No Map ASC','තුන','மூன்று',NULL,NULL,'1','2020-03-03 00:00:00',NULL)",
            "INSERT INTO tbl_arpa VALUES (300,200,'AR300','ARPA One','අ','அ','1','0000-00-00 00:00:00',NULL),(301,201,'AR301','ARPA Two','ආ','ஆ','1','2020-04-02 00:00:00',NULL),(302,202,'AR302','ARPA Three','ඇ','இ','1','2020-04-03 00:00:00',NULL)",
            "INSERT INTO tbl_gnd VALUES (400,300,'10','ASC1','OLD400','GN400','LOCAL400','GN Auto','ජීඑන්','ஜிஎன்','1'),(401,301,'10','ASC2','OLD401','GN400','LOCAL401','GN Same Code','ජීඑන්2','ஜிஎன்2','1'),(402,302,'10','ASC3','OLD402','GN402','LOCAL402','GN Independent','ජීඑන්3','ஜிஎன்3','1'),(403,300,'10','WRONG','OLD403','GN403','LOCAL403','GN Metadata','ජීඑන්4','ஜிஎன்4','1')",
            'INSERT INTO tbl_ds_asc_join VALUES (1,100,200),(2,100,201),(3,101,201),(4,102,202)',
        ];
        foreach ($sql as $statement) {
            $pdo->exec($statement);
        }
    }

    private function createTargetSchema(PDO $pdo): void
    {
        $ddl = [
            'CREATE TABLE number_category (id CHAR(36) PRIMARY KEY, category_key VARCHAR(80) UNIQUE, category_code VARCHAR(5) UNIQUE, name_en VARCHAR(150), next_value BIGINT NOT NULL DEFAULT 1, active TINYINT NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE number_allocation (id BIGINT AUTO_INCREMENT PRIMARY KEY, category_id CHAR(36) NOT NULL, allocated_number VARCHAR(20) NOT NULL UNIQUE, allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE location_type (id CHAR(36) PRIMARY KEY, dad_number VARCHAR(20) UNIQUE, system_key VARCHAR(80) UNIQUE, name_en VARCHAR(150), active TINYINT NOT NULL DEFAULT 1)',
            "CREATE TABLE location (id CHAR(36) PRIMARY KEY, dad_number VARCHAR(20) UNIQUE, location_type_id CHAR(36) NOT NULL, official_code VARCHAR(100), gn_code VARCHAR(20), gn_code_for_plr VARCHAR(11), name_en VARCHAR(255) NOT NULL, name_si VARCHAR(255), name_ta VARCHAR(255), effective_from DATE NOT NULL, effective_to DATE, operational_status VARCHAR(30), approval_status VARCHAR(30), created_by CHAR(36), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_by CHAR(36), updated_at TIMESTAMP NULL, version BIGINT DEFAULT 0)",
            "CREATE TABLE location_relationship (id CHAR(36) PRIMARY KEY, parent_location_id CHAR(36) NOT NULL, child_location_id CHAR(36) NOT NULL, relationship_type VARCHAR(80) NOT NULL, effective_from DATE NOT NULL, effective_to DATE, approval_status VARCHAR(30) DEFAULT 'APPROVED', active TINYINT DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE legacy_migration_run (id CHAR(36) PRIMARY KEY, source_system VARCHAR(80), started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, completed_at TIMESTAMP NULL, status VARCHAR(40), dry_run TINYINT, selected_type VARCHAR(30), batch_size INT, province_source_count INT DEFAULT 0, province_migrated_count INT DEFAULT 0, district_source_count INT DEFAULT 0, district_migrated_count INT DEFAULT 0, ds_source_count INT DEFAULT 0, ds_migrated_count INT DEFAULT 0, asc_source_count INT DEFAULT 0, asc_migrated_count INT DEFAULT 0, arpa_source_count INT DEFAULT 0, arpa_migrated_count INT DEFAULT 0, gn_source_count INT DEFAULT 0, gn_migrated_count INT DEFAULT 0, relationship_count INT DEFAULT 0, warning_count INT DEFAULT 0, error_count INT DEFAULT 0, created_by CHAR(36), report_path VARCHAR(500), summary_json JSON)",
            'CREATE TABLE legacy_location_reference (id BIGINT AUTO_INCREMENT PRIMARY KEY, location_id CHAR(36), source_system VARCHAR(80), source_table VARCHAR(80), legacy_id VARCHAR(100), legacy_code VARCHAR(255), legacy_payload_json JSON, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_ref(source_system,source_table,legacy_id)) ENGINE=InnoDB',
            'CREATE TABLE legacy_migration_issue (id BIGINT AUTO_INCREMENT PRIMARY KEY, migration_run_id CHAR(36), source_table VARCHAR(80), legacy_id VARCHAR(100), issue_type VARCHAR(80), severity VARCHAR(20), message TEXT, source_payload_json JSON, resolved TINYINT DEFAULT 0, resolution_note TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
        ];
        foreach ($ddl as $sql) {
            $pdo->exec($sql);
        }
    }

    private function seedTarget(PDO $pdo): void
    {
        $types = ['PROVINCE' => '70001', 'DISTRICT' => '70002', 'DS_DIVISION' => '70003', 'ASC' => '70004', 'ARPA_DIVISION' => '70007', 'GN_DIVISION' => '70008'];
        $insertType = $pdo->prepare('INSERT INTO location_type (id,dad_number,system_key,name_en,active) VALUES (?,?,?,?,1)');
        $insertCategory = $pdo->prepare('INSERT INTO number_category (id,category_key,category_code,name_en,next_value,active) VALUES (?,?,?,?,1,1)');
        foreach ($types as $type => $code) {
            $typeId = LegacyLocationRules::uuid();
            $insertType->execute([$typeId, $code . '-0000000', $type, $type]);
            $insertCategory->execute([LegacyLocationRules::uuid(), 'LOCATION_' . $type, $code, $type]);
            if ($type === 'PROVINCE') {
                $pdo->prepare("INSERT INTO location (id,dad_number,location_type_id,official_code,name_en,name_si,name_ta,effective_from,operational_status,approval_status) VALUES (?,?,?,?,?,?,?,'2019-01-01','ACTIVE','APPROVED')")
                    ->execute([LegacyLocationRules::uuid(), '70001-0000099', $typeId, '01', 'Existing North', null, null]);
            }
        }
    }

    private function testDryRunAndExecute(): void
    {
        $source = $this->pdo($this->sourceDatabase);
        $target = $this->pdo($this->targetDatabase);
        $before = $this->operationalCounts($target);
        $dry = (new LegacyLocationMigrationService($source, $target, true, null, 2, '2026-08-10'))->run();
        $this->same($before, $this->operationalCounts($target), 'dry-run performs no operational writes');
        $this->same(13, $dry['source_records'], 'only current-scope source types counted');
        $this->same(1, $dry['matched_existing'], 'existing target official-code match');
        $this->same(12, $dry['would_create'], 'dry-run planned creates');
        $this->same(8, $dry['relationships_would_create'], 'only current hierarchy relationships planned');
        foreach (['ASC_WITH_NO_VALID_DS', 'DS_WITH_NO_VALID_ASC', 'ASC_DS_DIFFERENT_DISTRICT', 'GN_DS_AMBIGUOUS', 'GN_DS_NO_VALID_MAPPING', 'LEGACY_GN_ASC_CODE_MISMATCH'] as $deferredIssue) {
            $this->same(false, array_key_exists($deferredIssue, $dry['issue_counts']), $deferredIssue . ' is deferred');
        }

        $execute = (new LegacyLocationMigrationService($source, $target, false, null, 2, '2026-08-10'))->run();
        $this->same(12, $execute['created_new'], 'execute creates non-matched current-scope Locations');
        $this->same(8, $execute['relationships_created'], 'execute creates only current hierarchy relationships');
        $this->same(13, $this->scalar($target, 'SELECT COUNT(*) FROM location'), 'target Location total');
        $this->same(13, $this->scalar($target, 'SELECT COUNT(*) FROM legacy_location_reference'), 'legacy references attached');
        $this->same(12, $this->scalar($target, 'SELECT COUNT(*) FROM number_allocation'), 'DAD allocations only for new Locations');
        $this->same(8, $this->scalar($target, 'SELECT COUNT(*) FROM location_relationship'), 'relationship total');
        $this->same('70001-0000099', $this->scalar($target, "SELECT l.dad_number FROM legacy_location_reference r JOIN location l ON l.id=r.location_id WHERE r.source_table='tbl_province' AND r.legacy_id='1'"), 'existing DAD number preserved');
        $this->same('2026-08-10', $this->scalar($target, "SELECT l.effective_from FROM legacy_location_reference r JOIN location l ON l.id=r.location_id WHERE r.source_table='tbl_arpa' AND r.legacy_id='300'"), 'zero date used configured fallback');
        $this->same('ඇල්ෆා', $this->scalar($target, "SELECT l.name_si FROM legacy_location_reference r JOIN location l ON l.id=r.location_id WHERE r.source_table='tbl_district' AND r.legacy_id='10'"), 'Sinhala name migrated');
        $this->same('ஆல்பா', $this->scalar($target, "SELECT l.name_ta FROM legacy_location_reference r JOIN location l ON l.id=r.location_id WHERE r.source_table='tbl_district' AND r.legacy_id='10'"), 'Tamil name migrated');
        $this->same('WRONG', $this->scalar($target, "SELECT JSON_UNQUOTE(JSON_EXTRACT(legacy_payload_json,'$.asc_code')) FROM legacy_location_reference WHERE source_table='tbl_gnd' AND legacy_id='403'"), 'original GN ASC code retained as metadata');
        $this->same('300', (string)$this->scalar($target, "SELECT JSON_UNQUOTE(JSON_EXTRACT(legacy_payload_json,'$.arpa_id')) FROM legacy_location_reference WHERE source_table='tbl_gnd' AND legacy_id='400'"), 'original GN ARPA id retained as metadata');
        $this->same(4, $this->scalar($target, "SELECT COUNT(DISTINCT location_id) FROM legacy_location_reference WHERE source_table='tbl_gnd'"), 'each gnd_id maps to a distinct Location');
        $this->same(0, $this->scalar($target, "SELECT COUNT(*) FROM legacy_location_reference WHERE source_table='tbl_ds'"), 'DS source rows are untouched');
        $this->same('LOCAL401', $this->scalar($target, "SELECT l.official_code FROM legacy_location_reference r JOIN location l ON l.id=r.location_id WHERE r.source_table='tbl_gnd' AND r.legacy_id='401'"), 'GN local code is preserved');
        $this->same('OLD401', $this->scalar($target, "SELECT l.gn_code FROM legacy_location_reference r JOIN location l ON l.id=r.location_id WHERE r.source_table='tbl_gnd' AND r.legacy_id='401'"), 'GN Code is preserved independently from Official Code');
        $this->same('GN400', $this->scalar($target, "SELECT l.gn_code_for_plr FROM legacy_location_reference r JOIN location l ON l.id=r.location_id WHERE r.source_table='tbl_gnd' AND r.legacy_id='401'"), 'GN Code for PLR is preserved independently');

        $this->assertRelationship($target, 'tbl_district', '10', 'tbl_province', '1', 'PROVINCE_DISTRICT');
        $this->assertRelationship($target, 'tbl_asc', '200', 'tbl_district', '10', 'DISTRICT_ASC');
        $this->assertRelationship($target, 'tbl_arpa', '300', 'tbl_asc', '200', 'ASC_ARPA_DIVISION');
        $this->same(0, $this->scalar($target, "SELECT COUNT(*) FROM location_relationship WHERE relationship_type IN ('DISTRICT_DS_DIVISION','DS_DIVISION_ASC','ARPA_GN_DIVISION','DS_DIVISION_GN_DIVISION')"), 'deferred relationships are not created');

        $rerun = (new LegacyLocationMigrationService($source, $target, false, null, 3, '2026-08-10'))->run();
        $this->same(13, $rerun['matched_existing'], 'idempotent rerun matches all current-scope Locations');
        $this->same(0, $rerun['created_new'], 'idempotent rerun creates no Locations');
        $this->same(0, $rerun['relationships_created'], 'idempotent rerun creates no relationships');
        $this->same(12, $this->scalar($target, 'SELECT COUNT(*) FROM number_allocation'), 'idempotent rerun allocates no DAD numbers');
        $this->same(13, $this->scalar($target, 'SELECT COUNT(*) FROM legacy_location_reference'), 'idempotent rerun keeps one reference per current source');
    }

    private function assertRelationship(PDO $pdo, string $childTable, string $childLegacy, string $parentTable, string $parentLegacy, string $type): void
    {
        $this->same(1, $this->relationshipExists($pdo, $childTable, $childLegacy, $parentTable, $parentLegacy, $type), $type . ' relationship');
    }

    private function relationshipExists(PDO $pdo, string $childTable, string $childLegacy, string $parentTable, string $parentLegacy, string $type): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM location_relationship lr
             JOIN legacy_location_reference c ON c.location_id=lr.child_location_id
             JOIN legacy_location_reference p ON p.location_id=lr.parent_location_id
             WHERE c.source_table=? AND c.legacy_id=? AND p.source_table=? AND p.legacy_id=? AND lr.relationship_type=?'
        );
        $stmt->execute([$childTable, $childLegacy, $parentTable, $parentLegacy, $type]);
        return (int)$stmt->fetchColumn();
    }

    private function operationalCounts(PDO $pdo): array
    {
        return [
            'location' => $this->scalar($pdo, 'SELECT COUNT(*) FROM location'),
            'relationship' => $this->scalar($pdo, 'SELECT COUNT(*) FROM location_relationship'),
            'allocation' => $this->scalar($pdo, 'SELECT COUNT(*) FROM number_allocation'),
            'reference' => $this->scalar($pdo, 'SELECT COUNT(*) FROM legacy_location_reference'),
            'issue' => $this->scalar($pdo, 'SELECT COUNT(*) FROM legacy_migration_issue'),
        ];
    }

    private function pdo(string $database): PDO
    {
        $cfg = config('database');
        return new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $database), $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
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

    private function dropFixtureDatabases(): void
    {
        foreach ([$this->sourceDatabase ?? null, $this->targetDatabase ?? null] as $database) {
            if (is_string($database) && preg_match('/^dems_test_(legacy|target)_[a-f0-9]{10}$/', $database)) {
                $this->server->exec("DROP DATABASE IF EXISTS `{$database}`");
            }
        }
    }
}

exit((new LegacyLocationMigrationTest())->run());
