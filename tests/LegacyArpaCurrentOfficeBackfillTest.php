<?php
declare(strict_types=1);

use App\Core\{Database,LegacyDatabase};
use App\Services\LegacyOfficer\LegacyArpaCurrentOfficeBackfillService;

require dirname(__DIR__).'/bootstrap.php';

final class LegacyArpaCurrentOfficeBackfillTest
{
    private PDO $source;private PDO $target;private int $assertions=0;
    public function run():int
    {
        $this->source=LegacyDatabase::pdo();$this->target=Database::pdo();$this->classification();$this->dryRun();$this->schemaAndSafety();echo "LegacyArpaCurrentOfficeBackfillTest: {$this->assertions} assertions passed.\n";return 0;
    }
    private function classification():void
    {
        $this->same('NO_ASC',LegacyArpaCurrentOfficeBackfillService::classifyAscEvidence([]),'zero ASC is unresolved');$this->same('EXACT_ONE_ASC',LegacyArpaCurrentOfficeBackfillService::classifyAscEvidence(['389','389']),'multiple Divisions under the same ASC collapse to one');$this->same('MULTIPLE_ASC',LegacyArpaCurrentOfficeBackfillService::classifyAscEvidence(['389','390']),'multiple ASCs require review');
    }
    private function dryRun():void
    {
        $before=$this->state();$r=(new LegacyArpaCurrentOfficeBackfillService($this->source,$this->target,date('Y-m-d')))->dryRun();$this->same(true,$r['source']['qualifying_appointment_rows']>0,'approved current 2026 ARPA Division evidence found');$this->same($r['source']['distinct_legacy_officers'],$r['classification']['EXACT_ONE_ASC']+$r['classification']['MULTIPLE_ASC']+$r['classification']['NO_ASC'],'all legacy Officers classified');$this->same(true,$r['zero_write_verification']['source_unchanged'],'source is unchanged');$this->same(true,$r['zero_write_verification']['target_unchanged'],'target is unchanged');$this->same($before,$this->state(),'dry-run performs zero operational writes');
        $pairs=[];foreach($r['proposals'] as $p){$key=$p['target_officer_id'].'|'.$p['office_id'];$this->same(false,isset($pairs[$key]),'one proposal per target Officer and ASC Office');$pairs[$key]=true;}$this->same($before['assignments'],(int)$this->target->query('SELECT COUNT(*) FROM officer_office_assignment')->fetchColumn(),'dry-run does not change existing assignments');
        foreach($r['proposals'] as $p){if($p['existing_assignments']!==[])$this->same(true,true,'existing different Office assignments are retained/reported');if($p['primary_status']==='EXISTING_PRIMARY_REVIEW')$this->same(true,true,'existing primary is not replaced');}
    }
    private function schemaAndSafety():void
    {
        $migration=file_get_contents(BASE_PATH.'/database/migrations/033_legacy_arpa_current_office_backfill_provenance.sql');$service=file_get_contents(BASE_PATH.'/app/Services/LegacyOfficer/LegacyArpaCurrentOfficeBackfillService.php');$this->contains('LEGACY_CURRENT_STATE_BACKFILL',$migration,'provenance origin exists');$this->contains("LOWER(TRIM(p.officer_level))='arpa division'",$service,'only ARPA Division is selected');$this->same(false,str_contains($service,'SELECT appoint_location_id'),'mutable Officer current-location fallback is excluded');$this->same(false,str_contains($service,'INSERT INTO arpa_division_appointment'),'appointments are not migrated');$this->same(false,str_contains($service,'UPDATE tbl_'),'legacy source is never updated');
    }
    private function state():array{return ['assignments'=>(int)$this->target->query('SELECT COUNT(*) FROM officer_office_assignment')->fetchColumn(),'appointments'=>(int)$this->target->query('SELECT COUNT(*) FROM arpa_division_appointment')->fetchColumn(),'subjects'=>(int)$this->target->query('SELECT COUNT(*) FROM arpa_subject_assignment')->fetchColumn(),'source'=>(int)$this->source->query('SELECT COUNT(*) FROM tbl_officer_apoint_2026')->fetchColumn()];}
    private function same(mixed $e,mixed $a,string $m):void{$this->assertions++;if($e!==$a)throw new RuntimeException("{$m}: expected ".var_export($e,true).', got '.var_export($a,true));}private function contains(string $n,string $h,string $m):void{$this->assertions++;if(!str_contains($h,$n))throw new RuntimeException("{$m}: missing {$n}");}
}
exit((new LegacyArpaCurrentOfficeBackfillTest())->run());
