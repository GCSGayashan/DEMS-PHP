<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\LegacyDatabase;
use App\Services\LegacyAppointment\HistoricalArpaAppointmentDiagnosticService;

require dirname(__DIR__).'/bootstrap.php';

final class HistoricalArpaAppointmentDiagnosticTest
{
    private int $assertions=0;
    public function run(): int
    {
        $this->same('PERMANENT',HistoricalArpaAppointmentDiagnosticService::appointmentType(1),'duty 1');
        $this->same('ACTING',HistoricalArpaAppointmentDiagnosticService::appointmentType(2),'duty 2');
        $this->same('DUTY_COVERING',HistoricalArpaAppointmentDiagnosticService::appointmentType(3),'duty 3');
        $this->same('ATTEND_TO_DUTY',HistoricalArpaAppointmentDiagnosticService::appointmentType(4),'duty 4');
        $this->same(null,HistoricalArpaAppointmentDiagnosticService::appointmentType(5),'unsupported duty');
        $this->same('DISTRICT_APPROVED',HistoricalArpaAppointmentDiagnosticService::workflowState(['asc_approve'=>2,'district_approve'=>2,'national_approve'=>0,'asc_varify_by'=>1]),'legacy district approval state');
        $this->same('NATIONAL_VERIFIED',HistoricalArpaAppointmentDiagnosticService::workflowState(['asc_approve'=>2,'district_approve'=>2,'national_approve'=>1,'asc_varify_by'=>1]),'legacy national verification state');
        $this->same('REJECTED_OR_RESET',HistoricalArpaAppointmentDiagnosticService::workflowState(['asc_approve'=>0,'district_approve'=>0,'national_approve'=>0,'asc_varify_by'=>1]),'zero flags with actor remains truthful');
        $target=Database::pdo();$source=LegacyDatabase::pdo();$before=$this->state($target);
        $summary=(new HistoricalArpaAppointmentDiagnosticService($source,$target))->run();
        $this->same(10128,$summary['source']['tbl_officer_apoint_rows'],'verified old source rows');
        $this->same(9775,$summary['source']['tbl_officer_apoint_2026_rows'],'verified 2026 source rows');
        $this->same(1014,$summary['workflow']['distinct_actors'],'workflow actors discovered');
        $this->same(1014,$summary['workflow']['resolved_actors'],'all workflow actors resolve');
        $this->same(0,$summary['workflow']['unresolved_actors'],'no unresolved workflow actor');
        $this->same(100.0,$summary['workflow']['coverage_percent'],'workflow coverage');
        $this->same(6431,$summary['officer_coverage']['ALL_RELEVANT']['union_distinct'],'appointment-related Officer population');
        $this->same(6431,$summary['officer_coverage']['ALL_RELEVANT']['mapped_target'],'historical Officer extension completed');
        $this->same(0,$summary['officer_coverage']['ALL_RELEVANT']['missing_target'],'no missing target Officer');
        $this->same(14779,array_sum($summary['deduplication']),'all source rows reconcile to business records');
        $this->same(0,$summary['deduplication']['CONFLICT'],'no reconciliation conflicts');
        $this->same(0,$summary['deduplication']['AMBIGUOUS'],'no reconciliation ambiguities');
        $this->same(0,$summary['global_schema_blockers'],'legacy-origin schema is compatible');
        $this->same(4,$summary['history_and_projection']['true_execution_blocker_records'],'only ambiguous or impossible records require manual review');
        $this->same(2,$summary['history_and_projection']['true_blocker_reasons']['AMBIGUOUS_ASC_IDENTITY'],'multiple ASC candidates remain blocked');
        $this->same(1,$summary['history_and_projection']['true_blocker_reasons']['ARPA_LOCATION_NOT_EXACT'],'single missing ARPA location');
        $this->same(1,$summary['history_and_projection']['true_blocker_reasons']['MISSING_EFFECTIVE_FROM'],'single missing appointment date');
        $this->same('NO_ADDITIONAL_APPOINTMENT_SPECIFIC_ASC_SOURCE_FOUND',$summary['asc_evidence_search']['result'],'additional ASC evidence search result');
        $this->same(1,count($summary['missing_arpa_location_evidence']),'missing ARPA evidence retained');
        $workbench=$summary['workbench_items'];
        $this->same(725,count($workbench),'all reviewable records are exposed to the workbench');
        $this->same(704,count(array_filter($workbench,fn($r)=>$r['item_type']==='SPECIAL_ASC')),'special review includes strong-derived and untrusted evidence');
        $this->same(1,count(array_filter($workbench,fn($r)=>$r['item_type']==='MISSING_ARPA_LOCATION')),'missing ARPA review item exposed');
        $this->same(20,count(array_filter($workbench,fn($r)=>$r['item_type']==='CURRENT_CONFLICT')),'current conflicts are de-duplicated by business record');
        $this->same(3,count(array_filter($workbench,fn($r)=>$r['diagnostic_blocker'])),'workbench flags multi-ASC and missing-location review items; date review remains in preview');
        foreach(['csv_report_path','json_report_path','special_asc_review_report_path','current_conflict_report_path','missing_arpa_location_report_path'] as $path)$this->same(true,is_file($summary[$path]),"report exists: {$path}");
        $this->same(true,$this->nullable($target,'arpa_division_appointment_request','created_by'),'legacy request creator nullable');
        $this->same(true,$this->nullable($target,'arpa_appointment_workflow_action','action_at'),'legacy workflow timestamp nullable');
        $this->same(true,$this->nullable($target,'arpa_division_appointment','approved_at'),'legacy approval timestamp nullable');
        $this->same(true,$this->nullable($target,'arpa_division_appointment','service_permanency_snapshot'),'legacy service snapshot nullable');
        $this->same(true,$summary['zero_write_verification']['source_unchanged'],'source is read-only');
        $this->same(true,$summary['zero_write_verification']['target_unchanged'],'target is unchanged');
        $this->same($before,$this->state($target),'diagnostic makes zero operational writes');
        echo "HistoricalArpaAppointmentDiagnosticTest: {$this->assertions} assertions passed.\n";return 0;
    }
    private function state(PDO $pdo): array{$out=[];foreach(['officer','system_user','location','arpa_division_appointment_request','arpa_division_appointment','arpa_subject_assignment_request','arpa_subject_assignment','arpa_officer_sub_designation_period','arpa_appointment_workflow_action','arpa_subject_workflow_action'] as $t)$out[$t]=(int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();return $out;}
    private function nullable(PDO $pdo,string $table,string $column): bool{$s=$pdo->prepare('SELECT is_nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$s->execute([$table,$column]);return $s->fetchColumn()==='YES';}
    private function same(mixed $e,mixed $a,string $m): void{$this->assertions++;if($e!==$a)throw new RuntimeException("{$m}: expected ".var_export($e,true).', got '.var_export($a,true));}
}
exit((new HistoricalArpaAppointmentDiagnosticTest())->run());
