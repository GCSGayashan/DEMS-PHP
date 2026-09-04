<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

final class LegacyGnIdentifierBackfillMigrationTest
{
    private PDO $server;
    private string $sourceDatabase;
    private string $targetDatabase;
    private int $assertions=0;

    public function run(): int
    {
        $config=config('database');
        $this->sourceDatabase='dems_test_gn_source_'.bin2hex(random_bytes(4));
        $this->targetDatabase='dems_test_gn_target_'.bin2hex(random_bytes(4));
        $this->server=new PDO(
            sprintf('mysql:host=%s;port=%s;charset=%s',$config['host'],$config['port'],$config['charset']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]
        );

        try{
            $this->createFixtures();
            $target=$this->database($this->targetDatabase);
            $sql=(string)file_get_contents(BASE_PATH.'/database/migrations/053_backfill_gn_division_identifiers.sql');
            $this->contains('JOIN dems_legacy_hr.tbl_gnd g ON g.gnd_id = CAST(r.legacy_id AS UNSIGNED)',$sql,'migration uses canonical legacy row ID linkage');
            $this->contains("r.legacy_id REGEXP '^[0-9]+$'",$sql,'canonical legacy IDs must be numeric GN row IDs');
            $this->same(false,str_contains(strtolower($sql),'name_en'), 'migration never matches by GN name');
            $sql=str_replace('dems_legacy_hr.tbl_gnd','`'.$this->sourceDatabase.'`.tbl_gnd',$sql);
            $sql=str_replace('SET @gn_identifier_expected_source_count = 14016;','SET @gn_identifier_expected_source_count = 3;',$sql);

            $before=$this->unchangedFields($target);
            $target->exec($sql);
            $this->same(['GN-A','PLR-A'],array_values($this->identifiers($target,'location-a')),'gnd_ocode and gnd_code populate their canonical target fields');
            $this->same(['KEEP-GN','KEEP-PLR'],array_values($this->identifiers($target,'location-b')),'populated target identifiers are not overwritten');
            $this->same(['GN-C','PLR-C'],array_values($this->identifiers($target,'location-c')),'empty target identifiers are populated');
            $this->same($before,$this->unchangedFields($target),'backfill changes no other Location fields');

            $after=$this->identifierState($target);
            $target->exec($sql);
            $this->same($after,$this->identifierState($target),'immediate rerun is idempotent');
        }finally{
            $this->server->exec('DROP DATABASE IF EXISTS `'.$this->targetDatabase.'`');
            $this->server->exec('DROP DATABASE IF EXISTS `'.$this->sourceDatabase.'`');
        }

        echo "LegacyGnIdentifierBackfillMigrationTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function createFixtures(): void
    {
        $this->server->exec('CREATE DATABASE `'.$this->sourceDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->server->exec('CREATE DATABASE `'.$this->targetDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $source=$this->database($this->sourceDatabase);
        $target=$this->database($this->targetDatabase);
        $source->exec('CREATE TABLE tbl_gnd(gnd_id INT PRIMARY KEY,gnd_ocode VARCHAR(20),gnd_code VARCHAR(11))');
        $source->exec("INSERT INTO tbl_gnd VALUES(1,'GN-A','PLR-A'),(2,'GN-B','PLR-B'),(3,'GN-C','PLR-C')");
        $target->exec('CREATE TABLE location_type(id CHAR(36) PRIMARY KEY,system_key VARCHAR(80) NOT NULL)');
        $target->exec("INSERT INTO location_type VALUES('gn-type','GN_DIVISION')");
        $target->exec('CREATE TABLE location(id CHAR(36) PRIMARY KEY,dad_number VARCHAR(20),location_type_id CHAR(36),official_code VARCHAR(100),gn_code VARCHAR(20),gn_code_for_plr VARCHAR(11),name_en VARCHAR(255),effective_from DATE,operational_status VARCHAR(30),approval_status VARCHAR(30))');
        $target->exec("INSERT INTO location VALUES('location-a','70008-A','gn-type','OFF-A',NULL,NULL,'Same Name','2024-01-05','ACTIVE','APPROVED'),('location-b','70008-B','gn-type','OFF-B','KEEP-GN','KEEP-PLR','Same Name','2024-01-05','ACTIVE','APPROVED'),('location-c','70008-C','gn-type','OFF-C','','','Same Name','2024-01-05','ACTIVE','APPROVED')");
        $target->exec('CREATE TABLE legacy_location_reference(source_system VARCHAR(80),source_table VARCHAR(80),legacy_id VARCHAR(100),location_id CHAR(36))');
        $target->exec("INSERT INTO legacy_location_reference VALUES('AGRARIANADMIN_HR','tbl_gnd','1','location-a'),('AGRARIANADMIN_HR','tbl_gnd','2','location-b'),('AGRARIANADMIN_HR','tbl_gnd','3','location-c')");
    }

    private function database(string $name): PDO
    {
        $config=config('database');
        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',$config['host'],$config['port'],$name,$config['charset']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]
        );
    }

    private function identifiers(PDO $pdo,string $id): array
    {
        $stmt=$pdo->prepare('SELECT gn_code,gn_code_for_plr FROM location WHERE id=?');$stmt->execute([$id]);return $stmt->fetch();
    }

    private function identifierState(PDO $pdo): array
    {
        return $pdo->query('SELECT id,gn_code,gn_code_for_plr FROM location ORDER BY id')->fetchAll();
    }

    private function unchangedFields(PDO $pdo): array
    {
        return $pdo->query('SELECT id,dad_number,official_code,name_en,effective_from,operational_status,approval_status FROM location ORDER BY id')->fetchAll();
    }

    private function same(mixed $expected,mixed $actual,string $message): void
    {
        $this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
    }

    private function contains(string $needle,string $haystack,string $message): void
    {
        $this->assertions++;if(!str_contains($haystack,$needle))throw new RuntimeException($message.': missing '.$needle);
    }
}

exit((new LegacyGnIdentifierBackfillMigrationTest())->run());
