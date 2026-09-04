<?php
declare(strict_types=1);

use App\Services\LegacyLocation\LegacyGnIdentifierBackfillService;

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
            $this->contains('FROM {{LEGACY_DATABASE}}.tbl_gnd',$sql,'migration source schema is supplied by the configured legacy database');
            $this->contains('JOIN tmp_gn_identifier_source g ON g.gnd_id = CAST(r.legacy_id AS UNSIGNED)',$sql,'migration uses canonical legacy row ID linkage');
            $this->contains("r.legacy_id REGEXP '^[0-9]+$'",$sql,'canonical legacy IDs must be numeric GN row IDs');
            $this->same(false,str_contains(strtolower($sql),'name_en'), 'migration never matches by GN name');
            $sql=str_replace('{{LEGACY_DATABASE}}','`'.$this->sourceDatabase.'`',$sql);

            $this->same(14029,(int)$this->database($this->sourceDatabase)->query('SELECT COUNT(*) FROM tbl_gnd')->fetchColumn(),'source fixture includes 13 unreferenced rows');
            $this->same(14016,(int)$target->query('SELECT COUNT(*) FROM legacy_location_reference')->fetchColumn(),'only 14,016 canonical references are present');
            $dryRun=(new LegacyGnIdentifierBackfillService($this->database($this->sourceDatabase),$target,true))->run();
            $this->same(13,$dryRun['unmatched_source_records'],'dedicated service ignores 13 unreferenced source rows');
            $this->same(0,$dryRun['true_blockers'],'unreferenced source rows do not block the dedicated service');

            $before=$this->unchangedFields($target);
            $target->exec($sql);
            $this->same(['GN-1','PLR-1'],array_values($this->identifiers($target,$this->locationId(1))),'gnd_ocode and gnd_code populate their canonical target fields');
            $this->same(['KEEP-GN','KEEP-PLR'],array_values($this->identifiers($target,$this->locationId(2))),'populated target identifiers are not overwritten');
            $this->same(['GN-14016','PLR-14016'],array_values($this->identifiers($target,$this->locationId(14016))),'last canonical reference is populated');
            $this->same(14016,(int)$target->query("SELECT COUNT(*) FROM location WHERE NULLIF(TRIM(gn_code),'') IS NOT NULL AND NULLIF(TRIM(gn_code_for_plr),'') IS NOT NULL")->fetchColumn(),'all canonical targets are complete');
            $this->same(0,(int)$target->query("SELECT COUNT(*) FROM location WHERE id='location-14017'")->fetchColumn(),'unreferenced source rows do not create target locations');
            $this->same($before,$this->unchangedFields($target),'backfill changes no other Location fields');

            $after=$this->identifierState($target);
            $target->exec($sql);
            $this->same($after,$this->identifierState($target),'immediate rerun is idempotent');

            $source=$this->database($this->sourceDatabase);
            $source->exec('DELETE FROM tbl_gnd WHERE gnd_id=1');
            $missingSource=(new LegacyGnIdentifierBackfillService($source,$target,true))->run();
            $this->same(1,$missingSource['references_without_source'],'dedicated service detects a missing referenced source row');
            $this->same(true,$missingSource['true_blockers']>0,'missing referenced source is a service blocker');
            $this->throws(fn()=>$this->database($this->targetDatabase)->exec($sql),'missing referenced source blocks execution');
            $this->same($after,$this->identifierState($target),'missing-source guard fails before target updates');
            $source->exec("INSERT INTO tbl_gnd VALUES(1,'GN-1','PLR-1')");

            $target->exec("INSERT INTO legacy_location_reference VALUES('AGRARIANADMIN_HR','tbl_gnd','1','location-00002')");
            $duplicate=(new LegacyGnIdentifierBackfillService($source,$target,true))->run();
            $this->same(1,$duplicate['duplicate_legacy_mappings'],'dedicated service detects a duplicate canonical mapping');
            $this->same(true,$duplicate['true_blockers']>0,'duplicate canonical mapping is a service blocker');
            $this->throws(fn()=>$this->database($this->targetDatabase)->exec($sql),'duplicate canonical mapping blocks execution');
            $this->same($after,$this->identifierState($target),'duplicate-mapping guard fails before target updates');
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
        $target->exec('CREATE TABLE location_type(id CHAR(36) PRIMARY KEY,system_key VARCHAR(80) NOT NULL)');
        $target->exec("INSERT INTO location_type VALUES('gn-type','GN_DIVISION')");
        $target->exec('CREATE TABLE location(id CHAR(36) PRIMARY KEY,dad_number VARCHAR(20),location_type_id CHAR(36),official_code VARCHAR(100),gn_code VARCHAR(20),gn_code_for_plr VARCHAR(11),name_en VARCHAR(255),effective_from DATE,operational_status VARCHAR(30),approval_status VARCHAR(30))');
        $target->exec('CREATE TABLE legacy_location_reference(source_system VARCHAR(80),source_table VARCHAR(80),legacy_id VARCHAR(100),location_id CHAR(36))');

        $sourceInsert=$source->prepare('INSERT INTO tbl_gnd VALUES(?,?,?)');
        $locationInsert=$target->prepare('INSERT INTO location VALUES(?,?,?,?,?,?,?,?,?,?)');
        $referenceInsert=$target->prepare('INSERT INTO legacy_location_reference VALUES(?,?,?,?)');
        $source->beginTransaction();
        $target->beginTransaction();
        for($id=1;$id<=14029;$id++){
            $sourceInsert->execute([$id,'GN-'.$id,'PLR-'.$id]);
            if($id>14016)continue;
            $locationInsert->execute([
                $this->locationId($id),'70008-'.$id,'gn-type','OFF-'.$id,
                $id===2?'KEEP-GN':null,$id===2?'KEEP-PLR':null,
                'GN Division '.$id,'2024-01-05','ACTIVE','APPROVED',
            ]);
            $referenceInsert->execute(['AGRARIANADMIN_HR','tbl_gnd',(string)$id,$this->locationId($id)]);
        }
        $source->commit();
        $target->commit();
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

    private function locationId(int $id): string
    {
        return 'location-'.str_pad((string)$id,5,'0',STR_PAD_LEFT);
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

    private function throws(callable $callback,string $message): void
    {
        $this->assertions++;
        try{$callback();}catch(Throwable){return;}
        throw new RuntimeException($message.': expected migration guard failure');
    }
}

exit((new LegacyGnIdentifierBackfillMigrationTest())->run());
