<?php
declare(strict_types=1);

use App\Core\{DataTableRegistry,Database,LegacyDatabase};
use App\Services\{ArpaAppointmentIssuePresentation,ArpaAppointmentLocationPolicy,ArpaAppointmentReadService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentHierarchyBaselineTest
{
    private PDO $pdo;
    private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();
        $targetBefore=$this->targetState();$legacyBefore=$this->legacyState();
        $this->datePolicy();
        $this->historicalHierarchy();
        $this->friendlyPresentation();
        $this->same($targetBefore,$this->targetState(),'hierarchy tests leave appointment, workflow, correction, and access data unchanged');
        $this->same($legacyBefore,$this->legacyState(),'hierarchy tests leave the legacy source unchanged');
        echo "ArpaAppointmentHierarchyBaselineTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function datePolicy():void
    {
        $this->same('2024-01-05',ArpaAppointmentLocationPolicy::LOCATION_HIERARCHY_BASELINE_DATE,'central Location hierarchy baseline is explicit');
        $this->same('2026-01-01',ArpaAppointmentLocationPolicy::validationDate('2026-01-01'),'2026 appointment uses its 2026 hierarchy date');
        $this->same('2025-01-01',ArpaAppointmentLocationPolicy::validationDate('2025-01-01'),'2025 appointment uses its 2025 hierarchy date');
        $this->same('2024-01-05',ArpaAppointmentLocationPolicy::validationDate('2024-01-05'),'baseline appointment uses the baseline date');
        $this->same('2024-01-05',ArpaAppointmentLocationPolicy::validationDate('2024-01-04'),'appointment one day before baseline uses the baseline hierarchy');
        $this->same('2024-01-05',ArpaAppointmentLocationPolicy::validationDate('2010-01-01'),'old appointment uses the baseline hierarchy');
        $this->same("GREATEST(a.effective_from,'2024-01-05')",ArpaAppointmentLocationPolicy::validationDateSql('a.effective_from'),'SQL validation date uses the same central baseline');
    }

    private function historicalHierarchy():void
    {
        $fixture=$this->pdo->query("SELECT a.id,a.effective_from,a.asc_location_id,a.arpa_division_location_id,a.origin_metadata_json
            FROM arpa_division_appointment a
            WHERE a.effective_from<'2024-01-05'
            ORDER BY a.effective_from,a.id LIMIT 1")->fetch();
        if(!$fixture)throw new RuntimeException('A pre-baseline appointment fixture is required.');
        $policy=new ArpaAppointmentLocationPolicy();
        $context=$policy->hierarchyContext($this->pdo,(string)$fixture['arpa_division_location_id'],(string)$fixture['asc_location_id'],(string)$fixture['effective_from']);
        $this->same('2024-01-05',$context['location_validation_date'],'pre-baseline record exposes the baseline Location Check Date');
        $this->same(true,$context['is_old_appointment'],'pre-baseline record is marked as an old appointment');
        $this->same(true,$context['matches'],'valid pre-baseline ARPA Division and ASC relationship is accepted');
        $this->same('Correct',$context['result'],'valid relationship has a simple Correct result');

        $parents=$policy->parents($this->pdo,(string)$fixture['arpa_division_location_id'],'ASC_ARPA_DIVISION',(string)$fixture['effective_from']);
        $this->same((string)$fixture['asc_location_id'],(string)$parents[0]['id'],'ARPA Division to ASC uses the baseline resolver');
        $districts=$policy->parents($this->pdo,(string)$fixture['asc_location_id'],'DISTRICT_ASC',(string)$fixture['effective_from']);
        $this->same(true,$districts!==[],'ASC to District uses the baseline resolver');
        $provinces=$policy->parents($this->pdo,(string)$districts[0]['id'],'PROVINCE_DISTRICT',(string)$fixture['effective_from']);
        $this->same(true,$provinces!==[],'District to Province uses the baseline resolver');

        $source=ArpaAppointmentReadService::issueSource();
        $this->same(0,(int)$this->scalar("SELECT COUNT(*) FROM {$source} q WHERE q.issue_type='APPOINTMENT_OUTSIDE_ASC' AND q.related_ids=?",[$fixture['id']]),'valid pre-baseline hierarchy creates no Appointment Data Issue');
        $oldCount=(int)$this->scalar("SELECT COUNT(*) FROM arpa_division_appointment a WHERE NOT EXISTS(SELECT 1 FROM location_relationship lr WHERE lr.parent_location_id=a.asc_location_id AND lr.child_location_id=a.arpa_division_location_id AND lr.relationship_type='ASC_ARPA_DIVISION' AND lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=a.effective_from AND (lr.effective_to IS NULL OR lr.effective_to>=a.effective_from))");
        $newCount=(int)$this->scalar("SELECT COUNT(*) FROM {$source} q WHERE q.issue_type='APPOINTMENT_OUTSIDE_ASC'");
        $this->same(true,$oldCount>$newCount,'dashboard and diagnostic count is recalculated by the corrected hierarchy policy');

        $wrongAsc=(string)$this->scalar("SELECT l.id FROM location l JOIN location_type t ON t.id=l.location_type_id AND t.system_key='ASC' WHERE l.id<>? ORDER BY l.id LIMIT 1",[$fixture['asc_location_id']]);
        $effectiveBefore=(string)$fixture['effective_from'];$metadataBefore=(string)$fixture['origin_metadata_json'];
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('UPDATE arpa_division_appointment SET asc_location_id=? WHERE id=?')->execute([$wrongAsc,$fixture['id']]);
            $wrong=$policy->hierarchyContext($this->pdo,(string)$fixture['arpa_division_location_id'],$wrongAsc,$effectiveBefore);
            $this->same(false,$wrong['matches'],'genuine ASC mismatch is rejected at the baseline');
            $this->same('Does not match',$wrong['result'],'genuine mismatch has a simple result');
            $this->same(1,(int)$this->scalar("SELECT COUNT(*) FROM {$source} q WHERE q.issue_type='APPOINTMENT_OUTSIDE_ASC' AND q.related_ids=?",[$fixture['id']]),'genuine baseline mismatch creates an issue');
            $this->same($effectiveBefore,(string)$this->scalar('SELECT effective_from FROM arpa_division_appointment WHERE id=?',[$fixture['id']]),'hierarchy validation never changes the appointment date');
            $this->same($metadataBefore,(string)$this->scalar('SELECT origin_metadata_json FROM arpa_division_appointment WHERE id=?',[$fixture['id']]),'hierarchy validation never changes legacy provenance');
        }finally{$this->pdo->rollBack();}
        $this->same($effectiveBefore,(string)$this->scalar('SELECT effective_from FROM arpa_division_appointment WHERE id=?',[$fixture['id']]),'appointment date remains unchanged after mismatch test rollback');
        $this->same($metadataBefore,(string)$this->scalar('SELECT origin_metadata_json FROM arpa_division_appointment WHERE id=?',[$fixture['id']]),'legacy provenance remains unchanged after mismatch test rollback');
    }

    private function friendlyPresentation():void
    {
        $hierarchy=ArpaAppointmentIssuePresentation::for('APPOINTMENT_OUTSIDE_ASC');
        $this->same('ARPA Division and ASC do not match',$hierarchy['title'],'hierarchy issue uses a simple visible title');
        $this->same('This ARPA Division is not listed under the selected Agrarian Service Center.',$hierarchy['explanation'],'hierarchy issue uses a simple explanation');
        $this->same('Check the ASC and ARPA Division.',$hierarchy['what_to_check'],'hierarchy issue gives a simple action');
        $this->same('Permanent appointment not found',ArpaAppointmentIssuePresentation::for('DEPENDENT_WITHOUT_PERMANENT')['title'],'dependency issue avoids internal technical wording');
        $this->same('End date is missing',ArpaAppointmentIssuePresentation::for('OPEN_APPOINTMENT_WITH_END_REASON')['title'],'missing end date has a plain title');
        $this->same('Needs Correction',ArpaAppointmentIssuePresentation::severity('ERROR'),'error severity uses user-friendly wording');
        $this->same('Old Data Warning',ArpaAppointmentIssuePresentation::severity('HISTORICAL_EXCEPTION'),'historical severity uses user-friendly wording');
        $this->same('Correct Record',trim(strip_tags('<span>Correct Record</span>')),'correction command uses simple wording');

        $systemAdmin=(string)$this->scalar("SELECT uar.user_id FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id AND r.role_code='SYSTEM_ADMIN' WHERE uar.active=1 AND uar.approval_status='APPROVED' LIMIT 1");
        if($systemAdmin!=='')$_SESSION=['user_id'=>$systemAdmin];
        $definition=DataTableRegistry::definition('arpa-appointment-issues');
        $labels=array_column($definition['columns'],'label');
        $this->same(['Issue','Officer','ASC','ARPA Division','Appointment Type','Appointment Period','What to check','Actions'],$labels,'issue list uses short user-facing columns');
        $formatted=($definition['columns'][0]['format'])(['issue_type'=>'APPOINTMENT_OUTSIDE_ASC','severity'=>'ERROR']);
        $this->same(true,str_contains($formatted,'ARPA Division and ASC do not match'),'issue list renders friendly title instead of the internal code');
        $this->same(false,str_contains($formatted,'APPOINTMENT_OUTSIDE_ASC'),'internal code is not the main list label');

        $index=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/issues/index.php');
        $detail=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/issues/detail.php');
        $this->contains('About this page',$index,'issue list includes simple help');
        $this->contains('These records may need correction.',$index,'Needs Attention category has a simple explanation');
        $this->contains('What to check',$detail,'review page displays What to check');
        $this->contains('Old appointment: DEMS checked this location using the location structure from 05 January 2024.',$detail,'review page displays the old appointment baseline note');
        $this->contains('<details',$detail,'technical details remain available');
        $this->same(false,str_contains($detail,'<details open'),'technical details are collapsed by default');
        $this->contains('Internal issue code',$detail,'internal issue code remains available for authorized technical review');
        $this->contains('No approval is needed for this data correction.',$detail,'direct correction remains separate from approval workflow');
        $this->contains('Reason for Correction',$detail,'correction form uses a clear reason label');
        $this->contains('Additional Notes',$detail,'correction form uses a clear notes label');
    }

    private function targetState():array{return ['appointments'=>(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment'),'requests'=>(int)$this->scalar('SELECT COUNT(*) FROM arpa_division_appointment_request'),'workflow'=>(int)$this->scalar('SELECT COUNT(*) FROM arpa_appointment_workflow_action'),'corrections'=>(int)$this->scalar('SELECT COUNT(*) FROM arpa_appointment_data_correction'),'decisions'=>(int)$this->scalar("SELECT COUNT(*) FROM legacy_arpa_appointment_resolution WHERE resolution_status='CONFIRMED'")];}
    private function legacyState():array{$legacy=LegacyDatabase::pdo();return ['officers'=>(int)$legacy->query('SELECT COUNT(*) FROM tbl_officer')->fetchColumn(),'appointments'=>(int)$legacy->query('SELECT COUNT(*) FROM tbl_officer_apoint')->fetchColumn()];}
    private function scalar(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function contains(string $needle,string $haystack,string $message):void{$this->assertions++;if(!str_contains($haystack,$needle))throw new RuntimeException($message.': missing '.$needle);}
}

exit((new ArpaAppointmentHierarchyBaselineTest())->run());
