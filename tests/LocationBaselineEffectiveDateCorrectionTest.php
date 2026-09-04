<?php
declare(strict_types=1);

use App\Core\{DataTableRegistry,Database,LegacyDatabase};
use App\Services\{ArpaAppointmentIssuePresentation,LocationHierarchyEffectiveDatePolicy};
use App\Services\LegacyLocation\{LocationBaselineEffectiveDateCorrectionService,LocationBaselineVersionPolicy};

require dirname(__DIR__).'/bootstrap.php';
ini_set('memory_limit','512M');

final class LocationBaselineEffectiveDateCorrectionTest
{
    private PDO $pdo;
    private PDO $legacy;
    private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->legacy=LegacyDatabase::pdo();
        $targetBefore=$this->targetState();$legacyBefore=$this->legacyState();
        $this->versionPolicy();
        $this->dryRunPlan();
        $this->guardsAndPresentation();
        $this->same($targetBefore,$this->targetState(),'dry-run and tests leave every protected target value unchanged');
        $this->same($legacyBefore,$this->legacyState(),'dry-run and tests leave the legacy Location source unchanged');
        echo "LocationBaselineEffectiveDateCorrectionTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function versionPolicy():void
    {
        $versions=[
            ['id'=>'later','effective_from'=>'2026-09-01','effective_to'=>null,'created_at'=>'2026-09-01 09:00:00'],
            ['id'=>'baseline','effective_from'=>'2026-08-10','effective_to'=>'2026-08-31','created_at'=>'2026-08-10 09:00:00'],
        ];
        $classification=LocationBaselineVersionPolicy::classify($versions);
        $this->same('baseline',$classification['first']['id'],'first approved baseline version is selected');
        $this->same('later',$classification['later'][0]['id'],'genuine later Location or hierarchy revision is preserved');
        $this->same(false,$classification['ambiguous'],'sequential effective-dated versions are not ambiguous');
        $overlap=LocationBaselineVersionPolicy::classify([
            ['id'=>'one','effective_from'=>'2026-08-10','effective_to'=>null,'created_at'=>'2026-08-10 09:00:00'],
            ['id'=>'two','effective_from'=>'2026-09-01','effective_to'=>null,'created_at'=>'2026-09-01 09:00:00'],
        ]);
        $this->same(true,$overlap['ambiguous'],'overlapping versions block execution');
        $tie=LocationBaselineVersionPolicy::classify([
            ['id'=>'one','effective_from'=>'2026-08-10','effective_to'=>'2026-08-31','created_at'=>'2026-08-10 09:00:00'],
            ['id'=>'two','effective_from'=>'2026-08-10','effective_to'=>null,'created_at'=>'2026-08-11 09:00:00'],
        ]);
        $this->same(true,$tie['ambiguous'],'tied first effective dates block execution');
    }

    private function dryRunPlan():void
    {
        $service=new LocationBaselineEffectiveDateCorrectionService($this->pdo,$this->legacy);
        $first=$service->dryRun(false);$second=$service->dryRun(false);
        $this->same('2024-01-05',LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,'central Location Master baseline is 05 January 2024');
        $this->same(25353,$first['location_master']['total'],'all Location records are counted');
        $this->same(18181,$first['location_master']['baseline_records_examined'],'all imported baseline Locations are examined');
        $this->same(18181,$first['location_master']['already_2024_01_05'],'all imported baseline Locations use the approved baseline date');
        $this->same(7172,$first['location_master']['excluded_nonbaseline_records'],'GN repair-created Locations are excluded from the original import-baseline batch');
        $this->same(0,$first['location_master']['would_change'],'no Location requires another baseline correction');
        $this->same(0,$first['location_master']['later_revisions_preserved'],'no later Location revision exists in the current data');
        $this->same(52027,$first['location_relationships']['total'],'all hierarchy records are counted');
        $this->same(37997,$first['location_relationships']['already_2024_01_05'],'all imported baseline hierarchy relationships use the approved baseline date');
        $this->same(14030,$first['location_relationships']['excluded_nonbaseline_records'],'GN repair-created hierarchy relationships are excluded from the original import-baseline batch');
        $this->same(0,$first['location_relationships']['would_change'],'no hierarchy relationship requires another baseline correction');
        $this->same(0,$first['location_relationships']['later_revisions_preserved'],'no later hierarchy revision exists in the current data');
        $this->same(0,$first['blockers']['total'],'dry-run has no ambiguous or blocking record');
        $this->same(0,$first['hierarchy_validation']['type_compatibility_errors'],'all hierarchy relationships have compatible Location Types');
        $this->same(0,$first['hierarchy_validation']['missing_required_parents'],'required hierarchy parents are complete');
        $this->same(0,$first['hierarchy_validation']['ambiguous_required_parents'],'required one-parent hierarchy is unambiguous');

        $this->same(14016,$first['location_relationships']['by_type']['ASC_GN_DIVISION']['total'],'all repaired ASC to GN relationships are counted');
        $this->same(0,$first['location_relationships']['by_type']['ASC_GN_DIVISION']['examined'],'repair-created ASC to GN relationships are outside the original import-baseline batch');

        $this->same(14016,$first['location_relationships']['by_type']['ARPA_GN_DIVISION']['total'],'all repaired ARPA to GN relationships are counted');
        $this->same(14002,$first['location_relationships']['by_type']['ARPA_GN_DIVISION']['examined'],'original imported ARPA to GN relationships remain in the baseline batch');

        $this->same(14016,$first['hierarchy_validation']['checks']['ASC_GN_DIVISION']['children'],'all GN Locations participate in ASC hierarchy validation');
        $this->same(0,$first['hierarchy_validation']['checks']['ASC_GN_DIVISION']['missing'],'no GN is missing its direct ASC relationship');
        $this->same(14016,$first['hierarchy_validation']['checks']['ASC_GN_DIVISION']['one_parent'],'every GN has exactly one direct ASC');

        $this->same(14016,$first['hierarchy_validation']['checks']['ARPA_GN_DIVISION']['children'],'all GN Locations participate in ARPA hierarchy validation');
        $this->same(0,$first['hierarchy_validation']['checks']['ARPA_GN_DIVISION']['missing'],'no GN is missing its ARPA relationship');
        $this->same(14016,$first['hierarchy_validation']['checks']['ARPA_GN_DIVISION']['one_parent'],'every GN has exactly one ARPA relationship');
        $this->same(592,$first['offices']['total'],'all baseline Offices are counted');
        $this->same(592,$first['offices']['baseline_records_examined'],'all approved active baseline Offices are examined');
        $this->same(592,$first['offices']['already_2024_01_05'],'all baseline Offices use the approved baseline date');
        $this->same(0,$first['offices']['would_change'],'no Office requires another baseline correction');
        $this->same(0,$first['offices']['blockers'],'Office correction has no blocker');
        $this->same(1,$first['offices']['by_type']['HEAD_OFFICE']['already_2024_01_05'],'Head Office uses 2024-01-05');
        $this->same(25,$first['offices']['by_type']['DISTRICT_OFFICE']['already_2024_01_05'],'all District Offices use 2024-01-05');
        $this->same(566,$first['offices']['by_type']['ASC_OFFICE']['already_2024_01_05'],'all ASC Offices use 2024-01-05');
        $this->same([],$first['offices']['old_to_new_examples'],'corrected Offices produce no old-to-new proposals');
        $appointmentCount=(int)$this->pdo->query('SELECT COUNT(*) FROM arpa_division_appointment')->fetchColumn();
        $this->same($appointmentCount,$first['appointment_hierarchy_issues']['appointments_examined'],'every current canonical appointment row is checked read-only');
        $this->same(0,$first['appointment_hierarchy_issues']['projected_issues_after'],'corrected baseline hierarchy resolves projected appointment checks');
        $this->same(0,$first['appointment_hierarchy_issues']['appointment_rows_would_change'],'no appointment row is proposed for update');
        $this->same(true,$first['integrity']['dry_run_target_and_legacy_unchanged'],'dry-run integrity fingerprint is unchanged');
        $this->same($first['location_master'],$second['location_master'],'repeat dry-run produces the same Location plan');
        $this->same($first['location_relationships'],$second['location_relationships'],'repeat dry-run produces the same hierarchy plan');
        $this->same($first['offices'],$second['offices'],'repeat dry-run produces the same Office plan');
        $this->same(10396,$first['location_master']['by_type']['ARPA_DIVISION']['already_2024_01_05'],'ARPA Division baseline count is reconciled');
        $this->same(14016,$first['location_master']['by_type']['GN_DIVISION']['total'],'all repaired GN Division Locations are counted');
        $this->same(6844,$first['location_master']['by_type']['GN_DIVISION']['examined'],'original imported GN Division baseline records are examined');
        $this->same(6844,$first['location_master']['by_type']['GN_DIVISION']['already_2024_01_05'],'original imported GN Division baseline count is reconciled');
        $this->same(566,$first['location_master']['by_type']['ASC']['already_2024_01_05'],'ASC baseline count is reconciled');
        $this->same(25,$first['location_master']['by_type']['DISTRICT']['already_2024_01_05'],'District baseline count is reconciled');
        $this->same(9,$first['location_master']['by_type']['PROVINCE']['already_2024_01_05'],'Province baseline count is reconciled');
        $this->same([],$first['_location_proposals'],'corrected Locations produce no update proposals');
        $this->same([],$first['_relationship_proposals'],'corrected hierarchy relationships produce no update proposals');
        $this->same([],$first['_office_proposals'],'corrected Offices produce no update proposals');
        $this->same(0,$first['safety']['appointment_rows_changed'],'dry-run changes no appointment row');
        $this->same(0,$first['safety']['officer_rows_changed'],'dry-run changes no Officer row');
        $this->same(0,$first['safety']['user_rows_changed'],'dry-run changes no user row');
        $this->same(0,$first['safety']['role_scope_rows_changed'],'dry-run changes no role or scope row');
        $this->same(0,$first['safety']['legacy_source_rows_changed'],'dry-run changes no legacy source row');
    }

    private function guardsAndPresentation():void
    {
        $service=new LocationBaselineEffectiveDateCorrectionService($this->pdo,$this->legacy);
        try{$service->execute('00000000-0000-0000-0000-000000000000','missing-backup.sql');throw new RuntimeException('Missing backup should have been rejected.');}
        catch(DomainException $expected){$this->contains('backup',$expected->getMessage(),'execution refuses to run without a fresh external backup');}
        $cli=(string)file_get_contents(BASE_PATH.'/bin/correct-location-baseline-effective-dates.php');
        $this->contains("['dry-run','execute','executor:','backup-file:']",$cli,'CLI exposes explicit dry-run and guarded execution modes');
        $this->contains('--backup-file=<fresh-external-backup.sql>',$cli,'CLI documents the required backup gate');
        $this->contains('DRY RUN ONLY',$cli,'normal requested command clearly labels dry-run output');
        $this->contains('OFFICES',$cli,'dry-run CLI prints a distinct Office section');
        $this->contains('Appointment rows changed',$cli,'dry-run CLI prints explicit protected-module safety counts');

        $locations=DataTableRegistry::definition('locations');$hierarchy=DataTableRegistry::definition('location-hierarchy');
        $this->same(true,in_array('l.effective_from',$locations['select'],true),'Location UI reads the stored business-effective date');
        $this->same(true,in_array('lr.effective_from',$hierarchy['select'],true),'Hierarchy UI reads the stored relationship-effective date');
        $locationDateColumn=array_values(array_filter($locations['columns'],fn(array $column):bool=>$column['key']==='effective_from'))[0];
        $hierarchyDateColumn=array_values(array_filter($hierarchy['columns'],fn(array $column):bool=>$column['key']==='effective_from'))[0];
        $this->contains('2024-01-05',($locationDateColumn['format'])(['effective_from'=>'2024-01-05']),'Location list displays the corrected stored date');
        $this->contains('2024-01-05',($hierarchyDateColumn['format'])(['effective_from'=>'2024-01-05']),'Hierarchy list displays the corrected stored date');
        $presentation=ArpaAppointmentIssuePresentation::for('APPOINTMENT_OUTSIDE_ASC');
        $this->same('ARPA Division and ASC do not match',$presentation['title'],'simple issue title remains active');
        $this->same('Check the ASC and ARPA Division.',$presentation['what_to_check'],'simple issue guidance remains active');
    }

    private function targetState():array
    {
        return [
            'locations'=>$this->row('SELECT COUNT(*) c,COALESCE(BIT_XOR(CRC32(CONCAT_WS("|",id,dad_number,effective_from,COALESCE(effective_to,""),created_at,COALESCE(updated_at,""),COALESCE(created_by,""),COALESCE(updated_by,""),version))),0) h FROM location'),
            'relationships'=>$this->row('SELECT COUNT(*) c,COALESCE(BIT_XOR(CRC32(CONCAT_WS("|",id,parent_location_id,child_location_id,effective_from,COALESCE(effective_to,""),created_at))),0) h FROM location_relationship'),
            'offices'=>$this->row('SELECT COUNT(*) c,COALESCE(BIT_XOR(CRC32(CONCAT_WS("|",id,effective_from,COALESCE(effective_to,""),created_at,COALESCE(approved_at,"")))),0) h FROM office'),
            'appointments'=>$this->row('SELECT COUNT(*) c,COALESCE(BIT_XOR(CRC32(CONCAT_WS("|",id,effective_from,COALESCE(origin_metadata_json,"")))),0) h FROM arpa_division_appointment'),
            'audit'=>(int)$this->pdo->query('SELECT COUNT(*) FROM audit_event')->fetchColumn(),
        ];
    }

    private function legacyState():array{return ['province'=>$this->countLegacy('tbl_province'),'district'=>$this->countLegacy('tbl_district'),'ds'=>$this->countLegacy('tbl_ds'),'asc'=>$this->countLegacy('tbl_asc'),'arpa'=>$this->countLegacy('tbl_arpa'),'gnd'=>$this->countLegacy('tbl_gnd')];}
    private function countLegacy(string $table):int{return (int)$this->legacy->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();}
    private function row(string $sql):array{return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function contains(string $needle,string $haystack,string $message):void{$this->assertions++;if(!str_contains($haystack,$needle))throw new RuntimeException($message.': missing '.$needle);}
}

exit((new LocationBaselineEffectiveDateCorrectionTest())->run());
