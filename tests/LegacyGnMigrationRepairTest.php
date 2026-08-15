<?php

declare(strict_types=1);

use App\Services\LegacyLocation\LegacyGnMigrationRepairService;
use App\Services\LegacyLocation\LegacyLocationRules;

require dirname(__DIR__) . '/bootstrap.php';

final class LegacyGnMigrationRepairTest
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
            $this->createFixture();
            $this->testDryRunAndExecute();
        } finally {
            $this->dropFixture();
            foreach ($this->reports as $report) {
                if (is_string($report) && is_file($report)) {
                    unlink($report);
                }
            }
        }

        echo "LegacyGnMigrationRepairTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function connectServer(): void
    {
        $config = config('database');
        $this->server = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $suffix = strtolower(bin2hex(random_bytes(5)));
        $this->sourceDatabase = 'dems_test_gn_source_' . $suffix;
        $this->targetDatabase = 'dems_test_gn_target_' . $suffix;
    }

    private function createFixture(): void
    {
        $this->server->exec("CREATE DATABASE `{$this->sourceDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->server->exec("CREATE DATABASE `{$this->targetDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $source = $this->pdo($this->sourceDatabase);
        $target = $this->pdo($this->targetDatabase);

        $source->exec('CREATE TABLE tbl_province (auto_id INT PRIMARY KEY)');
        $source->exec('CREATE TABLE tbl_district (auto_id INT PRIMARY KEY, pro_id INT)');
        $source->exec('CREATE TABLE tbl_asc (auto_id INT PRIMARY KEY, dis_id INT)');
        $source->exec('CREATE TABLE tbl_arpa (auto_id INT PRIMARY KEY, asc_id INT)');
        $source->exec('CREATE TABLE tbl_gnd (
            gnd_id INT PRIMARY KEY, arpa_id INT, dis_code VARCHAR(20), asc_code VARCHAR(20),
            gnd_ocode VARCHAR(20), gnd_code VARCHAR(20), gnd_lcode VARCHAR(20),
            gnd_name VARCHAR(100), gnd_sname VARCHAR(100), gnd_tname VARCHAR(100), gnd_status VARCHAR(20)
        )');
        $source->exec("INSERT INTO tbl_province VALUES (1)");
        $source->exec("INSERT INTO tbl_district VALUES (10,1)");
        $source->exec("INSERT INTO tbl_asc VALUES (100,10)");
        $source->exec("INSERT INTO tbl_arpa VALUES (1000,100)");
        $source->exec("INSERT INTO tbl_gnd VALUES
            (1,1000,'01','0101','OLD1','DUP','LOCAL1','Alpha','A-SI','A-TA','1'),
            (2,1000,'01','0101','OLD2','DUP','LOCAL2','Beta','B-SI','B-TA','1'),
            (3,1000,'01','0101','OLD3','UNIQUE','LOCAL3','Gamma','C-SI','C-TA','1')");

        $target->exec('CREATE TABLE location_type (id CHAR(36) PRIMARY KEY, system_key VARCHAR(80) UNIQUE, active TINYINT NOT NULL)');
        $target->exec('CREATE TABLE location (
            id CHAR(36) PRIMARY KEY, dad_number VARCHAR(20) UNIQUE, location_type_id CHAR(36) NOT NULL,
            official_code VARCHAR(100), name_en VARCHAR(255), name_si VARCHAR(255), name_ta VARCHAR(255),
            effective_from DATE, effective_to DATE, operational_status VARCHAR(30), approval_status VARCHAR(30),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, version BIGINT DEFAULT 0
        )');
        $target->exec('CREATE TABLE legacy_location_reference (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, location_id CHAR(36), source_system VARCHAR(80),
            source_table VARCHAR(80), legacy_id VARCHAR(100), legacy_code VARCHAR(255),
            legacy_payload_json JSON, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_reference(source_system, source_table, legacy_id)
        ) ENGINE=InnoDB');
        $target->exec('CREATE TABLE number_category (
            id CHAR(36) PRIMARY KEY, category_key VARCHAR(80) UNIQUE, category_code VARCHAR(5),
            next_value BIGINT NOT NULL, active TINYINT NOT NULL
        ) ENGINE=InnoDB');
        $target->exec('CREATE TABLE number_allocation (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, category_id CHAR(36), allocated_number VARCHAR(20) UNIQUE,
            allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
        $target->exec('CREATE TABLE location_relationship (id CHAR(36) PRIMARY KEY, relationship_type VARCHAR(80))');

        $typeId = LegacyLocationRules::uuid();
        $categoryId = LegacyLocationRules::uuid();
        $firstLocation = LegacyLocationRules::uuid();
        $secondLocation = LegacyLocationRules::uuid();
        $target->prepare('INSERT INTO location_type VALUES (?, ?, 1)')->execute([$typeId, 'GN_DIVISION']);
        $target->prepare("INSERT INTO number_category VALUES (?, 'LOCATION_GN_DIVISION', '70008', 100, 1)")->execute([$categoryId]);
        $insertLocation = $target->prepare(
            "INSERT INTO location (id,dad_number,location_type_id,official_code,name_en,effective_from,operational_status,approval_status)
             VALUES (?,?,?,?,?,'2020-01-01','ACTIVE','APPROVED')"
        );
        $insertLocation->execute([$firstLocation, '70008-0000001', $typeId, 'DUP', 'Alpha']);
        $insertLocation->execute([$secondLocation, '70008-0000002', $typeId, 'UNIQUE', 'Gamma']);
        $insertReference = $target->prepare(
            "INSERT INTO legacy_location_reference (location_id,source_system,source_table,legacy_id,legacy_code,legacy_payload_json)
             VALUES (?,'AGRARIANADMIN_HR','tbl_gnd',?,?,JSON_OBJECT('gnd_id',?))"
        );
        $insertReference->execute([$firstLocation, '1', 'DUP', 1]);
        $insertReference->execute([$firstLocation, '2', 'DUP', 2]);
        $insertReference->execute([$secondLocation, '3', 'UNIQUE', 3]);
        $target->prepare("INSERT INTO location_relationship VALUES (?, 'SENTINEL')")->execute([LegacyLocationRules::uuid()]);
    }

    private function testDryRunAndExecute(): void
    {
        $source = $this->pdo($this->sourceDatabase);
        $target = $this->pdo($this->targetDatabase);
        $before = $this->counts($target);
        $dry = (new LegacyGnMigrationRepairService($source, $target, true, 100, '2026-08-10'))->run();
        $this->reports[] = $dry['report_path'];
        $this->same($before, $this->counts($target), 'dry-run performs no operational writes');
        $this->same(3, $dry['source_gn_count'], 'source GN count');
        $this->same(2, $dry['current_gn_count'], 'current GN count');
        $this->same(1, $dry['duplicate_target_gn_count'], 'duplicate target count');
        $this->same(1, $dry['new_gn_locations_required'], 'one GN needs separation');
        $this->same(0, $dry['errors'], 'dry-run has no errors');

        $execute = (new LegacyGnMigrationRepairService($source, $target, false, 100, '2026-08-10'))->run();
        $this->reports[] = $execute['report_path'];
        $this->same(1, $execute['new_gn_locations_created'], 'one GN created');
        $this->same(3, $execute['final_gn_count'], 'final GN count');
        $this->same(3, $execute['final_distinct_referenced_gn_count'], 'one target per source GN');
        $this->same(0, $execute['remaining_duplicate_references'], 'no shared GN target remains');
        $this->same(1, $this->scalar($target, 'SELECT COUNT(*) FROM number_allocation'), 'one DAD number allocated');
        $this->same(1, $this->scalar($target, 'SELECT COUNT(*) FROM location_relationship'), 'relationships untouched');
        $this->same('1000', (string)$this->scalar($target, "SELECT JSON_UNQUOTE(JSON_EXTRACT(legacy_payload_json,'$.arpa_id')) FROM legacy_location_reference WHERE legacy_id='2'"), 'ARPA retained as metadata');
        $this->same('LOCAL2', $this->scalar($target, "SELECT legacy_code FROM legacy_location_reference WHERE legacy_id='2'"), 'local GN code retained');

        $rerun = (new LegacyGnMigrationRepairService($source, $target, true, 100, '2026-08-10'))->run();
        $this->reports[] = $rerun['report_path'];
        $this->same(0, $rerun['new_gn_locations_required'], 'repair is idempotent');
    }

    private function counts(PDO $pdo): array
    {
        return [
            'locations' => $this->scalar($pdo, 'SELECT COUNT(*) FROM location'),
            'references' => $this->scalar($pdo, 'SELECT COUNT(*) FROM legacy_location_reference'),
            'allocations' => $this->scalar($pdo, 'SELECT COUNT(*) FROM number_allocation'),
            'relationships' => $this->scalar($pdo, 'SELECT COUNT(*) FROM location_relationship'),
        ];
    }

    private function pdo(string $database): PDO
    {
        $config = config('database');
        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $database),
            $config['username'],
            $config['password'],
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
        foreach ([$this->sourceDatabase ?? null, $this->targetDatabase ?? null] as $database) {
            if (is_string($database) && preg_match('/^dems_test_gn_(source|target)_[a-f0-9]{10}$/', $database)) {
                $this->server->exec("DROP DATABASE IF EXISTS `{$database}`");
            }
        }
    }
}

exit((new LegacyGnMigrationRepairTest())->run());
