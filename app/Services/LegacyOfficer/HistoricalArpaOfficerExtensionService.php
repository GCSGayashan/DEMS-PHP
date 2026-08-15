<?php
declare(strict_types=1);

namespace App\Services\LegacyOfficer;

use App\Core\NicNormalizer;
use PDO;
use RuntimeException;

final class HistoricalArpaOfficerExtensionService
{
    public function __construct(private readonly PDO $source,private readonly PDO $target,private readonly bool $dryRun,private readonly int $batchSize=500,private readonly ?string $fallbackDate=null){}

    public function run(): array
    {
        $population=$this->population();
        $aliases=$this->verifiedSourceAliases($population);
        $preflight=(new LegacyArpaOfficerMigrationService($this->source,$this->target,true,$this->batchSize,$this->fallbackDate,$population,'historical-arpa-officer-extension',$aliases))->run();
        $preflight=$this->decorate($preflight,$population);
        if($this->dryRun)return $preflight;
        if($preflight['true_blockers']>0)throw new RuntimeException("Historical Officer extension execution refused: {$preflight['true_blockers']} true identity blockers remain.");
        $executed=(new LegacyArpaOfficerMigrationService($this->source,$this->target,false,$this->batchSize,$this->fallbackDate,$population,'historical-arpa-officer-extension',$aliases))->run();
        return $this->decorate($executed,$population);
    }

    private function population(): array
    {
        $sql="SELECT r.officer_id FROM (
                SELECT officer_id FROM tbl_officer_apoint WHERE LOWER(TRIM(officer_level)) IN ('arpa division','agrarian bank','sales shop','sithamu')
                UNION
                SELECT officer_id FROM tbl_officer_apoint_2026 WHERE LOWER(TRIM(officer_level)) IN ('arpa division','agrarian bank','sales shop','sithamu')
              ) r
              LEFT JOIN (SELECT DISTINCT officer_id FROM tbl_officer_apoint_2026 WHERE LOWER(TRIM(officer_level))='arpa division') original
                ON original.officer_id=r.officer_id
              WHERE original.officer_id IS NULL ORDER BY r.officer_id";
        return array_map('strval',$this->source->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    private function decorate(array $summary,array $population): array
    {
        $marks=implode(',',array_fill(0,count($population),'?'));
        $mapped=0;if($population!==[]){$stmt=$this->target->prepare("SELECT COUNT(*) FROM legacy_officer_reference WHERE source_system='AGRARIANADMIN_HR' AND source_table='tbl_officer' AND legacy_officer_id IN ({$marks})");$stmt->execute($population);$mapped=(int)$stmt->fetchColumn();}
        $summary['historical_extension_population']=count($population);
        $summary['historical_extension_mapped']=$mapped;
        $summary['historical_extension_unmapped']=count($population)-$mapped;
        $summary['true_blockers']=$summary['errors'];
        return $summary;
    }

    private function verifiedSourceAliases(array $population): array
    {
        if($population===[])return [];$marks=implode(',',array_fill(0,count($population),'?'));
        $stmt=$this->source->prepare("SELECT officer_id,nic,birth_day,gender,name_with_initial FROM tbl_officer WHERE officer_id IN ({$marks}) ORDER BY officer_id");$stmt->execute($population);$groups=[];
        foreach($stmt->fetchAll() as $row){$nic=NicNormalizer::normalize($row['nic']??null);if(!NicNormalizer::isValid($nic))continue;$dob=trim((string)$row['birth_day']);$gender=strtoupper(trim((string)$row['gender']));$initials=preg_replace('/[^A-Z0-9]+/','',strtoupper((string)$row['name_with_initial']));if($dob===''||$gender===''||$initials==='')continue;$groups[implode('|',[$nic,$dob,$gender,$initials])][]=(string)$row['officer_id'];}
        $aliases=[];foreach($groups as $ids){if(count($ids)<2)continue;sort($ids,SORT_NUMERIC);$primary=array_shift($ids);foreach($ids as $alias)$aliases[$alias]=$primary;}return $aliases;
    }
}
