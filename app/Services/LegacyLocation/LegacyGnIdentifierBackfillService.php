<?php
declare(strict_types=1);

namespace App\Services\LegacyLocation;

use DomainException;
use PDO;
use Throwable;

final class LegacyGnIdentifierBackfillService
{
    private const SOURCE_SYSTEM='AGRARIANADMIN_HR';

    public function __construct(private PDO $source,private PDO $target,private bool $dryRun=true){}

    public function run(): array
    {
        $this->requireSchema();
        $source=[];
        foreach($this->source->query('SELECT gnd_id,gnd_ocode,gnd_code FROM tbl_gnd ORDER BY gnd_id')->fetchAll() as $row){
            $source[(string)$row['gnd_id']]=[
                'gn_code'=>LegacyLocationRules::clean($row['gnd_ocode']??null),
                'gn_code_for_plr'=>LegacyLocationRules::clean($row['gnd_code']??null),
            ];
        }

        $stmt=$this->target->prepare("SELECT r.legacy_id,r.location_id,l.gn_code,l.gn_code_for_plr FROM legacy_location_reference r JOIN location l ON l.id=r.location_id JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='GN_DIVISION' WHERE r.source_system=? AND r.source_table='tbl_gnd' ORDER BY r.legacy_id,r.location_id");
        $stmt->execute([self::SOURCE_SYSTEM]);$references=$stmt->fetchAll();
        $byLegacy=[];$byTarget=[];
        foreach($references as $row){$byLegacy[(string)$row['legacy_id']][]=$row;$byTarget[(string)$row['location_id']][]=$row;}

        $ambiguousLegacy=0;$conflictingAliases=0;$existingConflicts=0;$matched=0;$alreadyComplete=0;$wouldUpdate=0;$wouldSetGn=0;$wouldSetPlr=0;$updates=[];
        foreach($byLegacy as $rows)if(count(array_unique(array_column($rows,'location_id')))>1)$ambiguousLegacy++;
        foreach($byTarget as $locationId=>$rows){
            $values=[];$legacyIds=[];
            foreach($rows as $row){$legacyId=(string)$row['legacy_id'];$legacyIds[]=$legacyId;if(isset($source[$legacyId]))$values[]=$source[$legacyId];}
            if($values===[])continue;$matched+=count($values);
            $gnValues=array_values(array_unique(array_filter(array_column($values,'gn_code'),fn($v)=>$v!==null)));
            $plrValues=array_values(array_unique(array_filter(array_column($values,'gn_code_for_plr'),fn($v)=>$v!==null)));
            if(count($gnValues)>1||count($plrValues)>1){$conflictingAliases++;continue;}
            $gn=$gnValues[0]??null;$plr=$plrValues[0]??null;$currentGn=LegacyLocationRules::clean($rows[0]['gn_code']??null);$currentPlr=LegacyLocationRules::clean($rows[0]['gn_code_for_plr']??null);
            if(($currentGn!==null&&$gn!==null&&$currentGn!==$gn)||($currentPlr!==null&&$plr!==null&&$currentPlr!==$plr))$existingConflicts++;
            $setGn=$currentGn===null&&$gn!==null;$setPlr=$currentPlr===null&&$plr!==null;
            if(!$setGn&&!$setPlr){$alreadyComplete++;continue;}
            $wouldUpdate++;$wouldSetGn+=(int)$setGn;$wouldSetPlr+=(int)$setPlr;
            $updates[]=['location_id'=>$locationId,'gn_code'=>$setGn?$gn:null,'gn_code_for_plr'=>$setPlr?$plr:null,'legacy_ids'=>$legacyIds];
        }

        $summary=[
            'mode'=>$this->dryRun?'DRY-RUN':'EXECUTE','source_records'=>count($source),
            'source_gn_code_available'=>count(array_filter($source,fn($r)=>$r['gn_code']!==null)),
            'source_plr_code_available'=>count(array_filter($source,fn($r)=>$r['gn_code_for_plr']!==null)),
            'legacy_references'=>count($references),'distinct_referenced_locations'=>count($byTarget),
            'matched_source_records'=>$matched,'unmatched_source_records'=>count(array_diff_key($source,$byLegacy)),
            'references_without_source'=>count(array_diff_key($byLegacy,$source)),
            'ambiguous_legacy_references'=>$ambiguousLegacy,'conflicting_target_aliases'=>$conflictingAliases,
            'populated_target_conflicts_preserved'=>$existingConflicts,'already_complete'=>$alreadyComplete,
            'would_update'=>$wouldUpdate,'would_set_gn_code'=>$wouldSetGn,'would_set_gn_code_for_plr'=>$wouldSetPlr,
            'updated'=>0,'true_blockers'=>$ambiguousLegacy+$conflictingAliases,
        ];
        if($this->dryRun)return $summary;
        if($summary['true_blockers']>0)throw new DomainException('GN identifier backfill has unresolved reference or alias conflicts.');

        $update=$this->target->prepare('UPDATE location SET gn_code=COALESCE(gn_code,?),gn_code_for_plr=COALESCE(gn_code_for_plr,?),updated_at=NOW(),version=version+1 WHERE id=? AND ((gn_code IS NULL AND ? IS NOT NULL) OR (gn_code_for_plr IS NULL AND ? IS NOT NULL))');
        $this->target->beginTransaction();
        try{foreach($updates as $row){$update->execute([$row['gn_code'],$row['gn_code_for_plr'],$row['location_id'],$row['gn_code'],$row['gn_code_for_plr']]);$summary['updated']+=$update->rowCount();}$this->target->commit();}
        catch(Throwable $e){if($this->target->inTransaction())$this->target->rollBack();throw $e;}
        return $summary;
    }

    private function requireSchema(): void
    {
        foreach([[$this->source,'tbl_gnd',['gnd_id','gnd_ocode','gnd_code']],[$this->target,'location',['id','gn_code','gn_code_for_plr']],[$this->target,'legacy_location_reference',['source_system','source_table','legacy_id','location_id']]] as [$pdo,$table,$columns]){
            $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();$marks=implode(',',array_fill(0,count($columns),'?'));
            $stmt=$pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME IN ({$marks})");$stmt->execute(array_merge([$database,$table],$columns));
            if(count($stmt->fetchAll(PDO::FETCH_COLUMN))!==count($columns))throw new DomainException("Required GN backfill schema is unavailable for {$table}.");
        }
    }
}
