<?php
declare(strict_types=1);

use App\Core\{DataTableQuery,DataTableRegistry,DataTableRequest,Database,LegacyDatabase};
use App\Services\LegacyAppointment\LegacyArpaReconciliationService;

require dirname(__DIR__).'/bootstrap.php';

final class LegacyArpaReconciliationWorkbenchTest
{
    private PDO $pdo;
    private LegacyArpaReconciliationService $service;
    private int $assertions=0;

    public function run(): int
    {
        $this->pdo=Database::pdo();$this->service=new LegacyArpaReconciliationService(LegacyDatabase::pdo(),$this->pdo);
        $this->testSchemaAndQueues();
        $this->testDataTables();
        $this->testExplicitAuditedStrongDerivedBulkConfirmation();
        $this->testAuditedDecisionsWithoutOperationalWrites();
        echo "LegacyArpaReconciliationWorkbenchTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function testSchemaAndQueues(): void
    {
        foreach(['legacy_arpa_reconciliation_item','legacy_arpa_appointment_resolution','legacy_arpa_appointment_resolution_audit','legacy_arpa_reconciliation_bulk_operation','legacy_arpa_reconciliation_sync'] as $table)$this->same(1,$this->scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='{$table}'"),"{$table} exists");
        $this->same(704,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_reconciliation_item WHERE active=1 AND item_type='SPECIAL_ASC'"),'all special ASC evidence is reviewable');
        $this->same(387,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_reconciliation_item WHERE active=1 AND item_type='SPECIAL_ASC' AND source_confidence='STRONG_DERIVED'"),'strong-derived queue population');
        $this->same(315,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_reconciliation_item WHERE active=1 AND item_type='SPECIAL_ASC' AND source_confidence='CURRENT_STATE_ONLY'"),'current-state-only queue population');
        $this->same(2,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_reconciliation_item WHERE active=1 AND item_type='SPECIAL_ASC' AND source_confidence='UNRESOLVED'"),'unresolved special queue population');
        $this->same(282,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_reconciliation_item WHERE active=1 AND item_type='SPECIAL_ASC' AND diagnostic_blocker=1"),'only current untrusted ASC rows block');
        $this->same(1,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_reconciliation_item WHERE active=1 AND item_type='MISSING_ARPA_LOCATION'"),'missing ARPA location queue population');
        $this->same(20,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_reconciliation_item WHERE active=1 AND item_type='CURRENT_CONFLICT'"),'distinct current conflict rows');
        $beforeDecisions=$this->scalar('SELECT COUNT(*) FROM legacy_arpa_appointment_resolution');$dashboard=$this->service->dashboard();$this->same(false,$dashboard['ready'],'workbench remains not ready while manual decisions remain');$this->same(596,(int)$dashboard['special']['total'],'dashboard covers current special-function records only');$this->same(0,(int)$dashboard['special']['bulk_eligible'],'already confirmed strong-derived records are not eligible again');$this->same(282,(int)($dashboard['byFunction']['AGRARIAN_BANK']['evidence']['CURRENT_STATE_ONLY']??0)+(int)($dashboard['byFunction']['SALES_SHOP']['evidence']['CURRENT_STATE_ONLY']??0)+(int)($dashboard['byFunction']['SITHAMU']['evidence']['CURRENT_STATE_ONLY']??0),'current-state-only records remain manual');$this->same(596,(int)$dashboard['special']['groups'],'Officer/function/ASC groups are counted');$this->same($beforeDecisions,$this->scalar('SELECT COUNT(*) FROM legacy_arpa_appointment_resolution'),'loading the dashboard creates no decisions');$this->same(14779,(int)$dashboard['sync']['reconciled_business_record_count'],'diagnostic business population retained');
    }

    private function testDataTables(): void
    {
        $_SESSION=[];$admin=$this->pdo->query("SELECT su.id FROM system_user su JOIN user_account_role ur ON ur.user_id=su.id JOIN application_role r ON r.id=ur.role_id WHERE r.role_code='SYSTEM_ADMIN' LIMIT 1")->fetchColumn();if(!$admin)throw new RuntimeException('SYSTEM_ADMIN fixture is required.');$_SESSION['user_id']=$admin;
        foreach(['legacy-arpa-special-review','legacy-arpa-special-groups','legacy-arpa-missing-location-review','legacy-arpa-current-conflicts'] as $key){$config=DataTableRegistry::definition($key);$response=(new DataTableQuery($this->pdo,$config,new DataTableRequest(['draw'=>3,'length'=>10,'order'=>[['column'=>1,'dir'=>'desc']]])))->response();$this->same(3,$response['draw'],"{$key} returns draw");$this->same(true,count($response['data'])<=10,"{$key} uses server pagination");$this->same(true,$response['recordsTotal']>=$response['recordsFiltered'],"{$key} count contract");}
        $filtered=(new DataTableQuery($this->pdo,DataTableRegistry::definition('legacy-arpa-special-review'),new DataTableRequest(['length'=>10,'filters'=>['subject_kind'=>'AGRARIAN_BANK','current_classification'=>'CURRENT']])))->response();$this->same(true,$filtered['recordsFiltered']>0,'special filters combine');$this->same(true,$filtered['recordsFiltered']<$filtered['recordsTotal'],'special filters narrow the result');
    }

    private function testExplicitAuditedStrongDerivedBulkConfirmation(): void
    {
        $actor=(string)$this->pdo->query("SELECT id FROM system_user WHERE username='dems.admin' LIMIT 1")->fetchColumn();if($actor==='')$actor=(string)$this->pdo->query('SELECT id FROM system_user ORDER BY id LIMIT 1')->fetchColumn();$before=$this->operationalState();$evidenceBefore=$this->row("SELECT COUNT(*) total,SUM(source_confidence='STRONG_DERIVED') strong_rows,SUM(source_confidence='CURRENT_STATE_ONLY') current_state_rows FROM legacy_arpa_reconciliation_item WHERE active=1 AND item_type='SPECIAL_ASC'");$resolutionBefore=$this->scalar('SELECT COUNT(*) FROM legacy_arpa_appointment_resolution');
        $this->throws(fn()=>$this->service->bulkConfirmStrongDerived($actor,'Reviewed strong evidence',false),'bulk confirmation requires explicit user action');$this->same($resolutionBefore,$this->scalar('SELECT COUNT(*) FROM legacy_arpa_appointment_resolution'),'refused bulk action creates no decisions');
        $this->pdo->beginTransaction();
        try{
            $manual=$this->row("SELECT i.id,i.officer_id,i.subject_kind,i.candidate_asc_id FROM legacy_arpa_reconciliation_item i JOIN legacy_arpa_appointment_preview p ON p.reconciled_business_key=i.reconciled_business_key AND p.active=1 AND p.current_classification='CURRENT' WHERE i.active=1 AND i.item_type='SPECIAL_ASC' AND i.source_confidence='CURRENT_STATE_ONLY' AND i.candidate_asc_id IS NOT NULL ORDER BY i.id LIMIT 1");$group=$this->service->group($manual['officer_id'],$manual['subject_kind'],$manual['candidate_asc_id']);$this->same(1,(int)$group['record_count'],'Officer/function/ASC grouping preserves record-level rows');$this->throws(fn()=>$this->service->confirmSelectedGroup([$manual['id']],$actor,'Reviewed grouped evidence',false),'group confirmation requires explicit user action');$groupResult=$this->service->confirmSelectedGroup([$manual['id']],$actor,'Reviewed grouped current-state evidence',true);$this->same(1,(int)$groupResult['created'],'explicit group confirmation creates its record-level decision');$this->same(1,$this->scalar('SELECT COUNT(*) FROM legacy_arpa_appointment_resolution_audit WHERE bulk_operation_id='.$this->pdo->quote($groupResult['bulk_operation_id'])),'group decision receives its own append-only audit row');
            $result=$this->service->bulkConfirmStrongDerived($actor,'Bulk confirmation of reviewed STRONG_DERIVED legacy evidence',true);$this->same(0,(int)$result['eligible'],'already confirmed strong-derived records are excluded');$this->same(0,(int)$result['created'],'bulk rerun does not duplicate preserved decisions');
            $this->same(314,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_appointment_resolution r JOIN legacy_arpa_reconciliation_item i ON i.id=r.reconciliation_item_id WHERE r.resolution_type='CONFIRM_ASC' AND r.resolution_status='CONFIRMED' AND r.original_evidence_class='STRONG_DERIVED' AND r.selected_target_asc_id IS NOT NULL AND i.current_classification='CURRENT'"),'preserved bulk decisions retain evidence class and selected ASC');
            $this->same(314,$this->scalar("SELECT COUNT(DISTINCT a.reconciliation_item_id) FROM legacy_arpa_appointment_resolution_audit a JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=a.reconciliation_item_id WHERE r.original_evidence_class='STRONG_DERIVED' AND a.audit_action='CREATED'"),'preserved decisions each retain an append-only creation audit');
            $this->same(0,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_appointment_resolution r JOIN legacy_arpa_reconciliation_item i ON i.id=r.reconciliation_item_id WHERE r.original_evidence_class='STRONG_DERIVED' AND i.source_confidence='CURRENT_STATE_ONLY'"),'CURRENT_STATE_ONLY remains excluded from strong-derived bulk confirmation');
            $afterFirst=$this->scalar('SELECT COUNT(*) FROM legacy_arpa_appointment_resolution');$rerun=$this->service->bulkConfirmStrongDerived($actor,'Idempotency verification',true);$this->same(0,(int)$rerun['created'],'rerunning the same bulk eligibility does not duplicate decisions');$this->same($afterFirst,$this->scalar('SELECT COUNT(*) FROM legacy_arpa_appointment_resolution'),'rerun leaves decision count unchanged');
            $this->same($evidenceBefore,$this->row("SELECT COUNT(*) total,SUM(source_confidence='STRONG_DERIVED') strong_rows,SUM(source_confidence='CURRENT_STATE_ONLY') current_state_rows FROM legacy_arpa_reconciliation_item WHERE active=1 AND item_type='SPECIAL_ASC'"),'bulk confirmation never modifies source evidence');$this->same($before,$this->operationalState(),'bulk decisions create no Office assignments or appointments');
        }finally{if($this->pdo->inTransaction())$this->pdo->rollBack();}
        $this->same($resolutionBefore,$this->scalar('SELECT COUNT(*) FROM legacy_arpa_appointment_resolution'),'bulk test rollback leaves no decisions');$this->same($before,$this->operationalState(),'bulk test rollback leaves operational state unchanged');
    }

    private function testAuditedDecisionsWithoutOperationalWrites(): void
    {
        $before=$this->operationalState();$actor=(string)$this->pdo->query("SELECT id FROM system_user WHERE username='dems.admin' LIMIT 1")->fetchColumn();if($actor==='')$actor=(string)$this->pdo->query('SELECT id FROM system_user ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->beginTransaction();
        try{
            $special=$this->row("SELECT i.* FROM legacy_arpa_reconciliation_item i WHERE i.active=1 AND i.item_type='SPECIAL_ASC' AND i.diagnostic_blocker=1 AND i.candidate_asc_id IS NOT NULL ORDER BY i.id LIMIT 1");
            $version=$this->resolutionVersion($special['id']);$beforeBlockers=(int)$this->service->dashboard()['special']['current_blockers'];
            $this->service->decide($special['id'],['version'=>$version,'resolution_type'=>'CONFIRM_CANDIDATE_ASC','decision_reason'=>'Automated policy verification'],$actor);
            $resolution=$this->resolution($special['id']);$this->same('CONFIRMED',$resolution['resolution_status'],'candidate confirmation is explicit');$this->same($special['candidate_asc_id'],$resolution['selected_target_asc_id'],'confirmed candidate ASC is retained');$this->same($beforeBlockers-1,(int)$this->service->dashboard()['special']['current_blockers'],'confirmed candidate clears exactly one ASC blocker');

            $differentAsc=(string)$this->value("SELECT l.id FROM location l JOIN location_type t ON t.id=l.location_type_id WHERE t.system_key='ASC' AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' AND l.id<>".$this->pdo->quote($special['candidate_asc_id']).' ORDER BY l.id LIMIT 1');
            $this->service->decide($special['id'],['resolution_id'=>$resolution['id'],'version'=>(int)$resolution['version'],'resolution_type'=>'SELECT_DIFFERENT_ASC','selected_target_asc_id'=>$differentAsc,'decision_reason'=>'Different ASC supported by reviewed evidence','evidence_notes'=>'Test evidence'],$actor);
            $changed=$this->resolution($special['id']);$this->same($differentAsc,$changed['selected_target_asc_id'],'a different selected ASC is retained');$this->same(2,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_appointment_resolution_audit WHERE reconciliation_item_id=".$this->pdo->quote($special['id'])),'changing a decision preserves both audit events');$this->same('CURRENT_STATE_ONLY',$this->value("SELECT source_confidence FROM legacy_arpa_reconciliation_item WHERE id=".$this->pdo->quote($special['id'])),'human confirmation never relabels source confidence as exact');

            $unresolvedCurrent=$this->row("SELECT i.* FROM legacy_arpa_reconciliation_item i WHERE i.active=1 AND i.item_type='SPECIAL_ASC' AND i.diagnostic_blocker=1 AND i.id<>".$this->pdo->quote($special['id']).' ORDER BY i.id LIMIT 1');
            $this->service->decide($unresolvedCurrent['id'],['version'=>$this->resolutionVersion($unresolvedCurrent['id']),'resolution_type'=>'UNRESOLVED_HISTORICAL','decision_reason'=>'No trustworthy historical ASC'],$actor);
            $this->same(1,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_reconciliation_item i JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id WHERE i.id=".$this->pdo->quote($unresolvedCurrent['id'])." AND i.diagnostic_blocker=1 AND r.resolution_status='UNRESOLVED_HISTORICAL' AND r.activation_decision='DO_NOT_ACTIVATE'"),'an unresolved current special record remains blocked from operational activation');

            $historical=$this->row("SELECT i.* FROM legacy_arpa_reconciliation_item i WHERE i.active=1 AND i.item_type='SPECIAL_ASC' AND i.current_classification='HISTORICAL' AND i.source_confidence='UNRESOLVED' ORDER BY i.id LIMIT 1");
            $this->service->decide($historical['id'],['version'=>$this->resolutionVersion($historical['id']),'resolution_type'=>'UNRESOLVED_HISTORICAL','decision_reason'=>'Truthfully preserve unresolved historical record'],$actor);
            $this->same(1,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_appointment_resolution WHERE reconciliation_item_id=".$this->pdo->quote($historical['id'])." AND resolution_status='UNRESOLVED_HISTORICAL' AND activation_decision='DO_NOT_ACTIVATE'"),'historical unresolved record remains eligible only as history');

            $missing=$this->row("SELECT * FROM legacy_arpa_reconciliation_item WHERE active=1 AND item_type='MISSING_ARPA_LOCATION' LIMIT 1");
            $this->throws(fn()=>$this->service->decide($missing['id'],['version'=>$this->resolutionVersion($missing['id']),'resolution_type'=>'SELECT_ARPA_DIVISION','decision_reason'=>'Missing explicit location'],$actor),'missing ARPA location rejects an empty selection');
            $arpa=$this->row("SELECT l.id,MIN(lr.parent_location_id) asc_id FROM location l JOIN location_type t ON t.id=l.location_type_id JOIN location_relationship lr ON lr.child_location_id=l.id AND lr.relationship_type='ASC_ARPA_DIVISION' AND lr.active=1 AND lr.approval_status='APPROVED' WHERE t.system_key='ARPA_DIVISION' AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' GROUP BY l.id HAVING COUNT(DISTINCT lr.parent_location_id)=1 ORDER BY l.id LIMIT 1");
            $this->service->decide($missing['id'],['version'=>$this->resolutionVersion($missing['id']),'resolution_type'=>'SELECT_ARPA_DIVISION','selected_target_arpa_id'=>$arpa['id'],'decision_reason'=>'Explicitly reviewed ARPA Division'],$actor);
            $missingResolution=$this->resolution($missing['id']);$this->same($arpa['id'],$missingResolution['selected_target_arpa_id'],'explicit ARPA Division is retained');$this->same($arpa['asc_id'],$missingResolution['selected_target_asc_id'],'ARPA selection stores the effective ASC snapshot');

            $conflict=$this->row("SELECT * FROM legacy_arpa_reconciliation_item WHERE active=1 AND item_type='CURRENT_CONFLICT' ORDER BY id LIMIT 1");$dates=[$conflict['effective_from'],$conflict['effective_to']];
            $this->service->decide($conflict['id'],['version'=>$this->resolutionVersion($conflict['id']),'resolution_type'=>'PRESERVE_HISTORY_ONLY','decision_reason'=>'Preserve source history without current activation'],$actor);
            $conflictResolution=$this->resolution($conflict['id']);$this->same('PRESERVE_HISTORY_ONLY',$conflictResolution['activation_decision'],'history-only decision prevents current activation');$afterConflict=$this->row('SELECT effective_from,effective_to FROM legacy_arpa_reconciliation_item WHERE id='.$this->pdo->quote($conflict['id']));$this->same($dates,[$afterConflict['effective_from'],$afterConflict['effective_to']],'conflict decision never rewrites source dates');
            $this->same($before,$this->operationalState(),'decisions never write operational appointment data');
        }finally{if($this->pdo->inTransaction())$this->pdo->rollBack();}
        $this->same($before,$this->operationalState(),'rolled-back decision tests leave operational state unchanged');
    }

    private function resolution(string $itemId): array{return $this->row('SELECT * FROM legacy_arpa_appointment_resolution WHERE reconciliation_item_id='.$this->pdo->quote($itemId));}
    private function resolutionVersion(string $itemId): int{$v=$this->value('SELECT version FROM legacy_arpa_appointment_resolution WHERE reconciliation_item_id='.$this->pdo->quote($itemId));return $v===false?0:(int)$v;}
    private function operationalState(): array{$out=[];foreach(['officer_office_assignment','arpa_division_appointment_request','arpa_division_appointment','arpa_subject_assignment_request','arpa_subject_assignment','arpa_officer_sub_designation_period'] as $table)$out[$table]=$this->scalar("SELECT COUNT(*) FROM {$table}");return $out;}
    private function row(string $sql): array{$row=$this->pdo->query($sql)->fetch();if(!$row)throw new RuntimeException('Expected fixture row was not found for: '.$sql);return $row;}
    private function scalar(string $sql): int{return (int)$this->pdo->query($sql)->fetchColumn();}
    private function value(string $sql): mixed{return $this->pdo->query($sql)->fetchColumn();}
    private function throws(callable $callback,string $message): void{$this->assertions++;try{$callback();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function same(mixed $expected,mixed $actual,string $message): void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
}

exit((new LegacyArpaReconciliationWorkbenchTest())->run());
