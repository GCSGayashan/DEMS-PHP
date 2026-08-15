<?php
declare(strict_types=1);

use App\Core\{Database,LegacyDatabase};
use App\Services\LegacyAppointment\{LegacyArpaAppointmentMigrationService,LegacyArpaMigrationPolicy};

require dirname(__DIR__).'/bootstrap.php';
ini_set('memory_limit','512M');

final class LegacyArpaAppointmentMigrationTest
{
    private PDO $pdo; private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();
        $before=$this->targetState();$legacyBefore=$this->legacyState();
        $service=new LegacyArpaAppointmentMigrationService($this->pdo);$report=$service->dryRun();$s=$report['summary'];
        $this->same(14779,$s['total']['reconciled_business_records'],'uses reconciled business records');
        $this->same('2025-01-01',$s['policy']['baseline_date'],'legacy operational baseline is explicit');
        $this->same(5167,$s['baseline_periods']['PRE_BASELINE_CARRIED_FORWARD'],'pre-baseline appointments carried into baseline');
        $this->same(9608,$s['baseline_periods']['LEGACY_PERIOD'],'appointments starting in the legacy period');
        $this->same(3,$s['baseline_periods']['PRE_BASELINE_HISTORY'],'ended pre-baseline history');
        $this->same(1,$s['baseline_periods']['DATE_REVIEW_REQUIRED'],'missing appointment date remains reviewable');
        $this->same(14775,$s['total']['eligible_migration_records'],'only genuine manual-review records remain outside migration');
        $this->same(6093,$s['migration_classification']['MIGRATABLE_OPERATIONAL'],'operationally valid records are classified independently');
        $this->same(1264,$s['migration_classification']['MIGRATABLE_HISTORY'],'valid ended and non-operational history remains migratable');
        $this->same(7418,$s['migration_classification']['MIGRATABLE_HISTORICAL_EXCEPTION'],'legacy business-rule and invalid-date exceptions remain migratable without activation');
        $this->same(4,$s['migration_classification']['MANUAL_REVIEW_REQUIRED'],'manual review is record isolated');
        $this->same(14775,array_sum(array_intersect_key($s['migration_classification'],array_flip(['MIGRATABLE_OPERATIONAL','MIGRATABLE_HISTORY','MIGRATABLE_HISTORICAL_EXCEPTION']))),'all other records are migratable');
        $already=$s['reconciliation']['existing_already_migrated'];
        $this->same(true,in_array($already,[0,14775],true),'migration is either pristine or fully imported');
        $this->same($already===0?14073:0,$s['target_projection']['division_requests_to_create'],'Division creation projection is idempotent');
        $this->same($already===0?702:0,$s['target_projection']['subject_requests_to_create'],'special-function creation projection is idempotent');
        $this->same(0,$s['reconciliation']['would_update'],'legacy importer never silently updates imported records');
        $this->same(702,$s['special_resolution']['automatically_resolvable'],'single-candidate special-function evidence resolves automatically');
        $this->same(387,$s['special_resolution']['strong_derived_automatic'],'STRONG_DERIVED evidence stays identifiable');
        $this->same(315,$s['special_resolution']['current_state_only_automatic'],'CURRENT_STATE_ONLY evidence stays identifiable');
        $this->same(388,$s['reconciliation']['deterministic_without_manual_decision'],'deterministic unconfirmed records no longer require a decision');
        $this->same(2,$s['special_resolution']['ambiguous_manual_review'],'multiple-ASC records still require manual review');
        $this->same(0,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_appointment_preview WHERE assignment_category='ASC_FUNCTION' AND appointment_type IS NOT NULL"),'special functions have no appointment type');
        $this->same(0,$s['workflow']['actor_mappings_unresolved'],'all workflow identities resolve');
        $this->same($s['workflow']['actor_mappings_resolved'],$s['workflow']['unavailable_timestamps'],'every legacy actor timestamp stays unavailable');
        $this->same(5091,$s['reconciliation']['exact_duplicates'],'old/2026 exact duplicates reconcile once');
        $this->same(33,$s['reconciliation']['continuation_matches'],'continuations reconcile once');
        $this->same(19903,$s['reconciliation']['source_references_expected'],'all source references retained');
        $this->same(4,$s['reconciliation']['unresolved_migration_workbench_decisions'],'only genuinely ambiguous or impossible records require review');
        $this->same(0,$s['current_activation']['blocked_unresolved_asc'],'deterministic current ASC evidence is not blocked by a missing decision');
        $this->same(true,$s['history']['historical_rule_exception_records']>=6253,'legacy exceptions preserved');
        $this->same(5,count(array_filter($report['records'],fn(array $r):bool=>in_array('INVALID_DATE_RANGE',$r['historical_exception_types_json'],true)&&!$r['operational_projection'])),'invalid legacy date ranges are preserved as non-operational exceptions');
        $this->same(4,$report['true_execution_blockers'],'only genuine record-level review cases remain outside migration');
        $this->same(0,$s['policy']['legacy_letters_generated'],'migration generates no letters');
        $this->same(3,count($s['profile_example']),'profile example projection found');
        $this->same($before,$this->targetState(),'dry-run performs zero target database writes');
        $this->same($legacyBefore,$this->legacyState(),'dry-run does not modify legacy source');
        $carried=current(array_filter($report['records'],fn(array $r):bool=>$r['operational_projection']&&$r['assignment_category']==='ARPA_DIVISION'&&$r['appointment_type']==='PERMANENT'&&$r['baseline_classification']==='PRE_BASELINE_CARRIED_FORWARD'&&$r['effective_from']!=='2025-01-01'));
        $this->same(true,is_array($carried),'pre-baseline Permanent projection found');$originalFrom=$carried['effective_from'];
        if($already===0){$insert=new ReflectionMethod($service,'insertRecord');$this->pdo->beginTransaction();try{$insert->invoke($service,$carried);$saved=(string)$this->pdo->query("SELECT effective_from FROM arpa_division_appointment WHERE request_id=(SELECT target_appointment_request_id FROM legacy_arpa_appointment_source_reference WHERE source_table='".explode(':',$carried['source_references_json'][0],2)[0]."' AND legacy_appointment_id='".explode(':',$carried['source_references_json'][0],2)[1]."' LIMIT 1)")->fetchColumn();}finally{$this->pdo->rollBack();}}else{$reference=explode(':',$carried['source_references_json'][0],2);$statement=$this->pdo->prepare('SELECT a.effective_from FROM legacy_arpa_appointment_source_reference sr JOIN arpa_division_appointment a ON a.id=sr.target_appointment_id WHERE sr.source_table=? AND sr.legacy_appointment_id=? LIMIT 1');$statement->execute($reference);$saved=(string)$statement->fetchColumn();}
        $this->same($originalFrom,$saved,'original pre-baseline effective_from is preserved');$this->same(false,$saved==='2025-01-01','baseline never replaces the appointment date');$this->same(0,$this->scalar('SELECT COUNT(*) FROM arpa_appointment_workflow_action WHERE action_at IS NOT NULL AND record_origin=\'LEGACY_IMPORT\''),'missing workflow timestamps remain NULL');
        $this->same(true,LegacyArpaMigrationPolicy::permanentCoversDate(['effective_from'=>'2015-03-01','effective_to'=>null],'2025-04-01'),'pre-2025 Permanent satisfies a 2025 Acting dependency');
        $this->same(true,LegacyArpaMigrationPolicy::permanentCoversDate(['effective_from'=>'2019-07-15','effective_to'=>'2026-12-31'],'2025-06-01'),'pre-2025 Permanent satisfies Duty Covering dependency');
        $this->same('PRE_BASELINE_CARRIED_FORWARD',LegacyArpaMigrationPolicy::periodClassification('2024-12-01',null),'pre-baseline open appointment classification');
        $this->same('asc-1',LegacyArpaMigrationPolicy::deterministicAsc(['special_candidate_asc'=>'asc-1','special_candidate_count'=>1,'special_evidence_class'=>'STRONG_DERIVED']),'STRONG_DERIVED needs no manual decision');
        $this->same('asc-2',LegacyArpaMigrationPolicy::deterministicAsc(['special_candidate_asc'=>'asc-2','special_candidate_count'=>1,'special_evidence_class'=>'CURRENT_STATE_ONLY']),'CURRENT_STATE_ONLY needs no manual decision');
        $this->same('AMBIGUOUS',LegacyArpaMigrationPolicy::specialResolutionState(['special_candidate_asc'=>'asc-1','special_candidate_count'=>2,'special_evidence_class'=>'STRONG_DERIVED']),'multiple ASC candidates remain manual');
        $this->same('NO_CANDIDATE',LegacyArpaMigrationPolicy::specialResolutionState(['special_candidate_count'=>0,'special_evidence_class'=>'UNRESOLVED']),'no ASC candidate is not guessed');
        $this->same($before,$this->targetState(),'all execution-shape checks roll back without target writes');
        $service=file_get_contents(dirname(__DIR__).'/app/Services/LegacyAppointment/LegacyArpaAppointmentMigrationService.php');
        $this->same(false,str_contains($service,'LegacyDatabase'),'importer never independently reads raw legacy tables');
        $this->same(true,str_contains($service,'if(!$record[\'would_create_business_record\'])continue'),'manual records are excluded individually');
        $this->same(true,str_contains($service,'foreach($report[\'records\'] as $record)'),'execution is record isolated');
        $this->same(false,str_contains($service,'LetterService'),'legacy migration does not generate letters');
        echo "LegacyArpaAppointmentMigrationTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function targetState():array{$tables=['legacy_arpa_appointment_migration_run','legacy_arpa_appointment_business_record','legacy_arpa_appointment_source_reference','arpa_division_appointment_request','arpa_division_appointment','arpa_appointment_workflow_action','arpa_subject_assignment_request','arpa_subject_assignment','arpa_subject_workflow_action','arpa_officer_sub_designation_period'];$out=[];foreach($tables as $table)$out[$table]=$this->scalar("SELECT COUNT(*) FROM {$table}");return $out;}
    private function legacyState():array{$pdo=LegacyDatabase::pdo();return [(int)$pdo->query('SELECT COUNT(*) FROM tbl_officer_apoint')->fetchColumn(),(int)$pdo->query('SELECT COUNT(*) FROM tbl_officer_apoint_2026')->fetchColumn()];}
    private function scalar(string $sql):int{return (int)$this->pdo->query($sql)->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
}

exit((new LegacyArpaAppointmentMigrationTest())->run());
