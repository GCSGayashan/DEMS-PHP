<?php
declare(strict_types=1);

namespace App\Services\LegacyAppointment;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

final class LegacyArpaReconciliationService
{
    public function __construct(private readonly PDO $source,private readonly PDO $target){}

    public function refresh(?string $actorId=null): array
    {
        $summary=(new HistoricalArpaAppointmentDiagnosticService($this->source,$this->target))->run();$items=$summary['workbench_items']??[];
        $this->target->beginTransaction();
        try{
            $this->target->exec('UPDATE legacy_arpa_reconciliation_item SET active=0');
            $sql="INSERT INTO legacy_arpa_reconciliation_item
                (id,source_system,reconciled_business_key,item_type,issue_types_json,source_references_json,primary_source_table,primary_source_record_id,legacy_officer_id,officer_id,subject_kind,appointment_type,effective_from,effective_to,current_classification,source_confidence,candidate_asc_id,candidate_arpa_id,candidate_evidence_json,context_snapshot_json,diagnostic_blocker,active,first_seen_at,last_seen_at,version)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW(),0)
                ON DUPLICATE KEY UPDATE issue_types_json=VALUES(issue_types_json),source_references_json=VALUES(source_references_json),primary_source_table=VALUES(primary_source_table),primary_source_record_id=VALUES(primary_source_record_id),legacy_officer_id=VALUES(legacy_officer_id),officer_id=VALUES(officer_id),subject_kind=VALUES(subject_kind),appointment_type=VALUES(appointment_type),effective_from=VALUES(effective_from),effective_to=VALUES(effective_to),current_classification=VALUES(current_classification),source_confidence=VALUES(source_confidence),candidate_asc_id=VALUES(candidate_asc_id),candidate_arpa_id=VALUES(candidate_arpa_id),candidate_evidence_json=VALUES(candidate_evidence_json),context_snapshot_json=VALUES(context_snapshot_json),diagnostic_blocker=VALUES(diagnostic_blocker),active=1,last_seen_at=NOW(),version=version+1";
            $stmt=$this->target->prepare($sql);foreach($items as $item)$stmt->execute([$this->uuid(),$item['source_system'],$item['reconciled_business_key'],$item['item_type'],$this->json($item['issue_types']),$this->json($item['source_references']),$item['primary_source_table'],$item['primary_source_record_id'],$item['legacy_officer_id'],$item['officer_id'],$item['subject_kind'],$item['appointment_type'],$item['effective_from'],$item['effective_to'],$item['current_classification'],$item['source_confidence'],$item['candidate_asc_id'],$item['candidate_arpa_id'],$this->json($item['candidate_evidence']),$this->json($item['context_snapshot']),$item['diagnostic_blocker']?1:0]);
            $officerBlockers=(int)$summary['officer_coverage']['ALL_RELEVANT']['missing_target'];$workflowBlockers=(int)$summary['workflow']['unresolved_actors'];
            $sync=$this->target->prepare("INSERT INTO legacy_arpa_reconciliation_sync(singleton_id,diagnostic_generated_at,reconciled_business_record_count,officer_blockers,workflow_blockers,schema_blockers,reconciliation_issue_rows,source_summary_json,synced_at,synced_by) VALUES(1,?,?,?,?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE diagnostic_generated_at=VALUES(diagnostic_generated_at),reconciled_business_record_count=VALUES(reconciled_business_record_count),officer_blockers=VALUES(officer_blockers),workflow_blockers=VALUES(workflow_blockers),schema_blockers=VALUES(schema_blockers),reconciliation_issue_rows=VALUES(reconciliation_issue_rows),source_summary_json=VALUES(source_summary_json),synced_at=NOW(),synced_by=VALUES(synced_by)");
            $sync->execute([date('Y-m-d H:i:s',strtotime((string)$summary['generated_at'])),array_sum($summary['deduplication']),$officerBlockers,$workflowBlockers,$summary['global_schema_blockers'],$summary['reconciliation_issue_rows'],$this->json(['source'=>$summary['source'],'deduplication'=>$summary['deduplication'],'workflow'=>$summary['workflow'],'officer_coverage'=>$summary['officer_coverage']['ALL_RELEVANT']]),$actorId]);
            $this->target->commit();
        }catch(Throwable $e){if($this->target->inTransaction())$this->target->rollBack();throw $e;}
        return ['items'=>count($items),'special'=>count(array_filter($items,fn($i)=>$i['item_type']==='SPECIAL_ASC')),'missing_arpa'=>count(array_filter($items,fn($i)=>$i['item_type']==='MISSING_ARPA_LOCATION')),'conflicts'=>count(array_filter($items,fn($i)=>$i['item_type']==='CURRENT_CONFLICT')),'diagnostic'=>$summary];
    }

    public function dashboard(): array
    {
        $special=$this->one("SELECT COUNT(*) total,
            COALESCE(SUM(r.resolution_status='CONFIRMED' AND r.selected_target_asc_id IS NOT NULL),0) confirmed,
            COALESCE(SUM(r.resolution_status='CONFIRMED' AND r.activation_decision='PRESERVE_HISTORY_ONLY'),0) preserve_history_only,
            COALESCE(SUM(r.id IS NULL OR r.resolution_status<>'CONFIRMED'),0) unresolved,
            COALESCE(SUM(i.candidate_asc_id IS NULL),0) no_candidate
            FROM legacy_arpa_reconciliation_item i
            JOIN legacy_arpa_appointment_preview p ON p.reconciled_business_key=i.reconciled_business_key
              AND p.active=1 AND p.assignment_category='ASC_FUNCTION' AND p.current_classification='CURRENT'
            LEFT JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id
            WHERE i.active=1 AND i.item_type='SPECIAL_ASC'");
        $special['current_blockers']=(int)$special['unresolved'];
        $special['needs_manual_confirmation']=(int)$special['unresolved']-(int)$special['no_candidate'];
        $special['bulk_eligible']=count($this->bulkEligibleStrongRows(false));
        $special['groups']=(int)$this->target->query("SELECT COUNT(*) FROM (
            SELECT i.officer_id,i.subject_kind,i.candidate_asc_id
            FROM legacy_arpa_reconciliation_item i
            JOIN legacy_arpa_appointment_preview p ON p.reconciled_business_key=i.reconciled_business_key
              AND p.active=1 AND p.assignment_category='ASC_FUNCTION' AND p.current_classification='CURRENT'
            WHERE i.active=1 AND i.item_type='SPECIAL_ASC'
            GROUP BY i.officer_id,i.subject_kind,i.candidate_asc_id
        ) special_groups")->fetchColumn();
        $byFunction=[];$rows=$this->target->query("SELECT i.subject_kind,i.source_confidence,COUNT(*) total,
            COALESCE(SUM(r.resolution_status='CONFIRMED' AND r.selected_target_asc_id IS NOT NULL),0) confirmed,
            COALESCE(SUM(r.resolution_status='CONFIRMED' AND r.activation_decision='PRESERVE_HISTORY_ONLY'),0) preserve_history_only,
            COALESCE(SUM(r.id IS NULL OR r.resolution_status<>'CONFIRMED'),0) unresolved,
            COALESCE(SUM(i.candidate_asc_id IS NULL),0) no_candidate
            FROM legacy_arpa_reconciliation_item i
            JOIN legacy_arpa_appointment_preview p ON p.reconciled_business_key=i.reconciled_business_key
              AND p.active=1 AND p.assignment_category='ASC_FUNCTION' AND p.current_classification='CURRENT'
            LEFT JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id
            WHERE i.active=1 AND i.item_type='SPECIAL_ASC'
            GROUP BY i.subject_kind,i.source_confidence ORDER BY i.subject_kind,i.source_confidence")->fetchAll();
        foreach($rows as $row){$function=(string)$row['subject_kind'];$confidence=(string)$row['source_confidence'];$byFunction[$function]??=['total'=>0,'confirmed'=>0,'unresolved'=>0,'preserve_history_only'=>0,'no_candidate'=>0,'evidence'=>[]];foreach(['total','confirmed','unresolved','preserve_history_only','no_candidate'] as $key)$byFunction[$function][$key]+=(int)$row[$key];$byFunction[$function]['evidence'][$confidence]=(int)$row['total'];}
        $missing=$this->one("SELECT COUNT(*) total,SUM(r.resolution_status='CONFIRMED') resolved,SUM(COALESCE(r.resolution_status,'')<>'CONFIRMED') unresolved FROM legacy_arpa_reconciliation_item i LEFT JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id WHERE i.active=1 AND i.item_type='MISSING_ARPA_LOCATION'");
        $conflicts=$this->one("SELECT COUNT(*) total,SUM(r.activation_decision='ACTIVATE_CURRENT' AND r.resolution_status='CONFIRMED') activate_current,SUM(r.activation_decision='PRESERVE_HISTORY_ONLY' AND r.resolution_status='CONFIRMED') preserve_history_only,SUM(COALESCE(r.resolution_status,'')<>'CONFIRMED') further_review FROM legacy_arpa_reconciliation_item i LEFT JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id WHERE i.active=1 AND i.item_type='CURRENT_CONFLICT'");
        $sync=$this->one('SELECT * FROM legacy_arpa_reconciliation_sync WHERE singleton_id=1');$remainingBlockers=(int)$special['unresolved']+(int)($missing['unresolved']??0)+(int)($conflicts['further_review']??0);$ready=$remainingBlockers===0&&(int)($sync['officer_blockers']??1)===0&&(int)($sync['workflow_blockers']??1)===0&&(int)($sync['schema_blockers']??1)===0&&(int)($sync['reconciliation_issue_rows']??1)===0;
        return compact('special','byFunction','missing','conflicts','sync','remainingBlockers','ready');
    }

    public function item(string $id): array
    {
        $stmt=$this->target->prepare("SELECT i.*,o.dad_number officer_number,o.nic,o.name_with_initials,o.full_name_en,o.primary_office_id,ca.dad_number candidate_asc_number,ca.name_en candidate_asc_name,cp.dad_number candidate_arpa_number,cp.name_en candidate_arpa_name,co.id candidate_office_id,co.dad_number candidate_office_number,co.name_en candidate_office_name,r.id resolution_id,r.resolution_type,r.resolution_status,r.selected_target_asc_id,r.selected_target_arpa_id,r.activation_decision,r.original_evidence_class,r.evidence_summary,r.bulk_operation_id,r.supporting_reconciled_business_key,r.decision_reason,r.evidence_notes,r.decided_by,r.decided_at,r.version resolution_version,u.username decided_by_username FROM legacy_arpa_reconciliation_item i JOIN officer o ON o.id=i.officer_id LEFT JOIN location ca ON ca.id=i.candidate_asc_id LEFT JOIN location cp ON cp.id=i.candidate_arpa_id LEFT JOIN office co ON co.linked_location_id=i.candidate_asc_id LEFT JOIN office_type cot ON cot.id=co.office_type_id AND cot.system_key='ASC_OFFICE' LEFT JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id LEFT JOIN system_user u ON u.id=r.decided_by WHERE i.id=? AND i.active=1");$stmt->execute([$id]);$item=$stmt->fetch();if(!$item)throw new DomainException('Reconciliation item was not found.');$item['issues']=$this->decode($item['issue_types_json']);$item['sources']=$this->decode($item['source_references_json']);$item['context']=$this->decode($item['context_snapshot_json']);$item['current_office_assignments']=$this->currentOfficeAssignments((string)$item['officer_id']);return $item;
    }

    public function auditHistory(string $itemId): array{$s=$this->target->prepare('SELECT a.*,u.username FROM legacy_arpa_appointment_resolution_audit a JOIN system_user u ON u.id=a.changed_by WHERE a.reconciliation_item_id=? ORDER BY a.id DESC');$s->execute([$itemId]);return $s->fetchAll();}
    public function locations(string $type): array{$s=$this->target->prepare("SELECT l.id,l.dad_number,l.name_en,l.official_code FROM location l JOIN location_type t ON t.id=l.location_type_id WHERE t.system_key=? AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' ORDER BY l.name_en");$s->execute([$type]);return $s->fetchAll();}

    public function group(string $officerId,string $function,?string $candidateAscId): array
    {
        if(preg_match('/^[0-9a-f-]{36}$/i',$officerId)!==1||!in_array($function,['AGRARIAN_BANK','SALES_SHOP','SITHAMU'],true))throw new DomainException('Invalid reconciliation group.');
        if($candidateAscId!==null&&preg_match('/^[0-9a-f-]{36}$/i',$candidateAscId)!==1)throw new DomainException('Invalid candidate ASC.');
        $candidateClause=$candidateAscId===null?'i.candidate_asc_id IS NULL':'i.candidate_asc_id=?';$params=[$officerId,$function];if($candidateAscId!==null)$params[]=$candidateAscId;
        $stmt=$this->target->prepare("SELECT i.id,i.reconciled_business_key,i.primary_source_table,i.primary_source_record_id,i.source_references_json,i.source_confidence,i.candidate_evidence_json,i.effective_from,i.effective_to,o.dad_number officer_number,o.nic,o.name_with_initials,o.full_name_en,ca.dad_number candidate_asc_number,ca.name_en candidate_asc_name,co.dad_number candidate_office_number,co.name_en candidate_office_name,r.resolution_status,r.selected_target_asc_id,r.activation_decision FROM legacy_arpa_reconciliation_item i JOIN legacy_arpa_appointment_preview p ON p.reconciled_business_key=i.reconciled_business_key AND p.active=1 AND p.assignment_category='ASC_FUNCTION' AND p.current_classification='CURRENT' JOIN officer o ON o.id=i.officer_id LEFT JOIN location ca ON ca.id=i.candidate_asc_id LEFT JOIN office co ON co.linked_location_id=i.candidate_asc_id LEFT JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id WHERE i.active=1 AND i.item_type='SPECIAL_ASC' AND i.officer_id=? AND i.subject_kind=? AND {$candidateClause} ORDER BY i.primary_source_table,i.primary_source_record_id");$stmt->execute($params);$rows=$stmt->fetchAll();if($rows===[])throw new DomainException('The reconciliation group was not found.');foreach($rows as &$row){$row['sources']=$this->decode($row['source_references_json']);$row['evidence_summary']=$this->evidenceSummary($row);}unset($row);
        return ['officer_id'=>$officerId,'function'=>$function,'candidate_asc_id'=>$candidateAscId,'records'=>$rows,'record_count'=>count($rows),'officer_number'=>$rows[0]['officer_number'],'officer_name'=>$rows[0]['name_with_initials']?:$rows[0]['full_name_en'],'nic'=>$rows[0]['nic'],'candidate_asc_number'=>$rows[0]['candidate_asc_number'],'candidate_asc_name'=>$rows[0]['candidate_asc_name'],'candidate_office_number'=>$rows[0]['candidate_office_number'],'candidate_office_name'=>$rows[0]['candidate_office_name'],'current_office_assignments'=>$this->currentOfficeAssignments($officerId)];
    }

    public function decide(string $itemId,array $data,string $actorId,?string $bulkOperationId=null): void
    {
        $ownsTransaction=!$this->target->inTransaction();
        if($ownsTransaction)$this->target->beginTransaction();else $this->target->exec('SAVEPOINT legacy_arpa_reconciliation_decision');
        try{$item=$this->lockedItem($itemId);$existing=$this->lockedResolution($itemId);$expected=(int)($data['version']??0);$expectedId=trim((string)($data['resolution_id']??''));if($existing!==null&&($expectedId===''||$expectedId!==$existing['id']||(int)$existing['version']!==$expected))throw new DomainException('This decision changed after the page was loaded. Reload and review it again.');if($existing===null&&($expected!==0||$expectedId!==''))throw new DomainException('This decision changed after the page was loaded. Reload and review it again.');$decision=$this->validatedDecision($item,$data);$id=$existing['id']??$this->uuid();$previous=$existing!==null?$this->decisionSnapshot($existing):null;
            if($existing===null){$stmt=$this->target->prepare('INSERT INTO legacy_arpa_appointment_resolution(id,reconciliation_item_id,reconciled_business_key,resolution_type,resolution_status,selected_target_location_id,selected_target_asc_id,selected_target_arpa_id,activation_decision,original_evidence_class,evidence_summary,bulk_operation_id,supporting_reconciled_business_key,decision_reason,evidence_notes,decided_by,decided_at,version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),0)');$stmt->execute([$id,$itemId,$item['reconciled_business_key'],$decision['resolution_type'],$decision['resolution_status'],$decision['selected_location'],$decision['selected_asc'],$decision['selected_arpa'],$decision['activation_decision'],$item['source_confidence'],$this->evidenceSummary($item),$bulkOperationId,$decision['supporting_key'],$decision['reason'],$decision['evidence'],$actorId]);$action='CREATED';}
            else{$stmt=$this->target->prepare('UPDATE legacy_arpa_appointment_resolution SET resolution_type=?,resolution_status=?,selected_target_location_id=?,selected_target_asc_id=?,selected_target_arpa_id=?,activation_decision=?,original_evidence_class=?,evidence_summary=?,bulk_operation_id=COALESCE(bulk_operation_id,?),supporting_reconciled_business_key=?,decision_reason=?,evidence_notes=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND version=?');$stmt->execute([$decision['resolution_type'],$decision['resolution_status'],$decision['selected_location'],$decision['selected_asc'],$decision['selected_arpa'],$decision['activation_decision'],$item['source_confidence'],$this->evidenceSummary($item),$bulkOperationId,$decision['supporting_key'],$decision['reason'],$decision['evidence'],$actorId,$id,$expected]);if($stmt->rowCount()!==1)throw new DomainException('Concurrent decision update detected.');$action='CHANGED';}
            $current=$this->lockedResolution($itemId);$this->audit($id,$itemId,$action,$previous,$this->decisionSnapshot($current),$actorId,$bulkOperationId??($current['bulk_operation_id']??null));if($ownsTransaction)$this->target->commit();else $this->target->exec('RELEASE SAVEPOINT legacy_arpa_reconciliation_decision');
        }catch(Throwable $e){if($ownsTransaction&&$this->target->inTransaction())$this->target->rollBack();elseif($this->target->inTransaction())$this->target->exec('ROLLBACK TO SAVEPOINT legacy_arpa_reconciliation_decision');throw $e;}
    }

    public function bulkConfirmStrongDerived(string $actorId,string $reason,bool $explicitlyConfirmed): array
    {
        if(!$explicitlyConfirmed)throw new DomainException('Explicit confirmation is required before bulk decisions are created.');
        $reason=trim($reason);if($reason==='')throw new DomainException('A bulk confirmation reason is required.');
        return $this->executeConfirmationBatch('BULK_CONFIRM_STRONG_DERIVED_ASC',$actorId,$reason,fn(bool $lock):array=>$this->bulkEligibleStrongRows($lock));
    }

    public function confirmSelectedGroup(array $itemIds,string $actorId,string $reason,bool $explicitlyConfirmed): array
    {
        if(!$explicitlyConfirmed)throw new DomainException('Explicit confirmation is required for the selected group.');
        $reason=trim($reason);if($reason==='')throw new DomainException('A group confirmation reason is required.');
        $ids=array_values(array_unique(array_filter(array_map('strval',$itemIds))));if($ids===[])throw new DomainException('Select at least one current record.');
        foreach($ids as $id)if(preg_match('/^[0-9a-f-]{36}$/i',$id)!==1)throw new DomainException('Invalid selected reconciliation item.');
        return $this->executeConfirmationBatch('GROUP_CONFIRM_ASC',$actorId,$reason,function(bool $lock)use($ids):array{
            $sql=$this->confirmableCurrentSpecialSql().' AND i.id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY i.id'.($lock?' FOR UPDATE':'');
            $stmt=$this->target->prepare($sql);$stmt->execute($ids);$rows=$stmt->fetchAll();$groups=[];
            foreach($rows as $row)$groups[$row['officer_id'].'|'.$row['subject_kind'].'|'.$row['candidate_asc_id']]=true;
            if(count($groups)>1)throw new DomainException('Selected records must belong to one Officer, function, and candidate ASC group.');
            return $rows;
        });
    }

    private function executeConfirmationBatch(string $operationType,string $actorId,string $reason,callable $rowsProvider): array
    {
        $ownsTransaction=!$this->target->inTransaction();
        if($ownsTransaction)$this->target->beginTransaction();else $this->target->exec('SAVEPOINT legacy_arpa_reconciliation_batch');
        try{
            $rows=$rowsProvider(true);$operationId=$this->uuid();$criteria=['current_special_function_only'=>true,'requires_candidate_asc'=>true,'requires_exact_target_officer'=>true,'requires_active_approved_asc'=>true,'requires_active_approved_asc_office'=>true,'excludes_existing_decisions'=>true,'excludes_conflicting_candidate_asc'=>true,'source_evidence_is_not_modified'=>true];if($operationType==='BULK_CONFIRM_STRONG_DERIVED_ASC')$criteria['source_confidence']='STRONG_DERIVED';
            $stmt=$this->target->prepare("INSERT INTO legacy_arpa_reconciliation_bulk_operation(id,operation_type,operation_status,decision_reason,selection_criteria_json,eligible_record_count,decision_record_count,initiated_by) VALUES(?,?,'RUNNING',?,?,?,0,?)");$stmt->execute([$operationId,$operationType,$reason,$this->json($criteria),count($rows),$actorId]);
            $created=0;foreach($rows as $row){$this->decide((string)$row['id'],['version'=>0,'resolution_type'=>'CONFIRM_ASC','decision_reason'=>$reason,'evidence_notes'=>'Reviewed evidence: '.$this->evidenceSummary($row)],$actorId,$operationId);$created++;}
            $status=$created===0?'COMPLETED_NO_CHANGES':'COMPLETED';$stmt=$this->target->prepare('UPDATE legacy_arpa_reconciliation_bulk_operation SET operation_status=?,decision_record_count=?,completed_at=NOW() WHERE id=?');$stmt->execute([$status,$created,$operationId]);
            if($ownsTransaction)$this->target->commit();else $this->target->exec('RELEASE SAVEPOINT legacy_arpa_reconciliation_batch');
            return ['bulk_operation_id'=>$operationId,'eligible'=>count($rows),'created'=>$created];
        }catch(Throwable $e){if($ownsTransaction&&$this->target->inTransaction())$this->target->rollBack();elseif($this->target->inTransaction())$this->target->exec('ROLLBACK TO SAVEPOINT legacy_arpa_reconciliation_batch');throw $e;}
    }

    private function bulkEligibleStrongRows(bool $lock): array
    {
        $sql=$this->confirmableCurrentSpecialSql()." AND i.source_confidence='STRONG_DERIVED' AND NOT EXISTS(
            SELECT 1 FROM legacy_arpa_reconciliation_item conflicting
            JOIN legacy_arpa_appointment_preview conflicting_preview ON conflicting_preview.reconciled_business_key=conflicting.reconciled_business_key AND conflicting_preview.active=1 AND conflicting_preview.current_classification='CURRENT'
            WHERE conflicting.active=1 AND conflicting.item_type='SPECIAL_ASC' AND conflicting.officer_id=i.officer_id AND conflicting.subject_kind=i.subject_kind AND conflicting.candidate_asc_id<>i.candidate_asc_id
        ) ORDER BY i.id".($lock?' FOR UPDATE':'');
        return $this->target->query($sql)->fetchAll();
    }

    private function confirmableCurrentSpecialSql(): string
    {
        return "SELECT i.*,JSON_UNQUOTE(JSON_EXTRACT(i.candidate_evidence_json,'$.method')) evidence_method
            FROM legacy_arpa_reconciliation_item i
            JOIN legacy_arpa_appointment_preview p ON p.reconciled_business_key=i.reconciled_business_key AND p.active=1 AND p.assignment_category='ASC_FUNCTION' AND p.current_classification='CURRENT'
            JOIN officer o ON o.id=i.officer_id
            JOIN location candidate_asc ON candidate_asc.id=i.candidate_asc_id AND candidate_asc.operational_status='ACTIVE' AND candidate_asc.approval_status='APPROVED' AND candidate_asc.effective_from<=CURRENT_DATE() AND (candidate_asc.effective_to IS NULL OR candidate_asc.effective_to>=CURRENT_DATE())
            JOIN location_type candidate_type ON candidate_type.id=candidate_asc.location_type_id AND candidate_type.system_key='ASC'
            JOIN office candidate_office ON candidate_office.linked_location_id=candidate_asc.id AND candidate_office.operational_status='ACTIVE' AND candidate_office.approval_status='APPROVED' AND candidate_office.effective_from<=CURRENT_DATE() AND (candidate_office.effective_to IS NULL OR candidate_office.effective_to>=CURRENT_DATE())
            JOIN office_type candidate_office_type ON candidate_office_type.id=candidate_office.office_type_id AND candidate_office_type.system_key='ASC_OFFICE'
            LEFT JOIN legacy_arpa_appointment_resolution existing ON existing.reconciliation_item_id=i.id
            WHERE i.active=1 AND i.item_type='SPECIAL_ASC' AND i.current_classification='CURRENT' AND i.candidate_asc_id IS NOT NULL AND existing.id IS NULL";
    }

    private function validatedDecision(array $item,array $data): array
    {
        $type=strtoupper(trim((string)($data['resolution_type']??'')));$reason=trim((string)($data['decision_reason']??''));$evidence=$this->nullText($data['evidence_notes']??null);if($reason==='')throw new DomainException('Decision reason is required.');$selectedAsc=null;$selectedArpa=null;$selectedLocation=null;$activation=null;$supporting=null;
        if($item['item_type']==='SPECIAL_ASC'){
            if(!in_array($type,['CONFIRM_ASC','CONFIRM_CANDIDATE_ASC','SELECT_DIFFERENT_ASC','PRESERVE_HISTORY_ONLY','UNRESOLVED_HISTORICAL'],true))throw new DomainException('Invalid special ASC decision.');
            if(in_array($type,['CONFIRM_ASC','CONFIRM_CANDIDATE_ASC'],true)){$selectedAsc=(string)$item['candidate_asc_id'];if($selectedAsc==='')throw new DomainException('This item has no candidate ASC to confirm.');$selectedAsc=$this->location($selectedAsc,'ASC')['id'];$status='CONFIRMED';}
            elseif($type==='SELECT_DIFFERENT_ASC'){$selectedAsc=$this->location((string)($data['selected_target_asc_id']??''),'ASC')['id'];$status='CONFIRMED';}
            elseif($type==='PRESERVE_HISTORY_ONLY'){$status='CONFIRMED';$activation='PRESERVE_HISTORY_ONLY';}
            else{$status='UNRESOLVED_HISTORICAL';$activation='DO_NOT_ACTIVATE';}
            $selectedLocation=$selectedAsc;
        }elseif($item['item_type']==='MISSING_ARPA_LOCATION'){
            if(!in_array($type,['SELECT_ARPA_DIVISION','PRESERVE_HISTORY_ONLY'],true))throw new DomainException('Select an ARPA Division or explicitly preserve this record as history only.');
            if($type==='PRESERVE_HISTORY_ONLY'){$status='CONFIRMED';$activation='PRESERVE_HISTORY_ONLY';}
            else{$arpa=$this->location((string)($data['selected_target_arpa_id']??''),'ARPA_DIVISION');$selectedArpa=$arpa['id'];$selectedAsc=$this->arpaAsc($selectedArpa,(string)$item['effective_from']);$selectedLocation=$selectedArpa;$status='CONFIRMED';}
        }else{
            if(!in_array($type,['ACTIVATE_CURRENT','PRESERVE_HISTORY_ONLY','REQUIRES_FURTHER_REVIEW'],true))throw new DomainException('Invalid conflict decision.');$issues=$this->decode($item['issue_types_json']);
            if($type==='ACTIVATE_CURRENT'){$this->validateConflictActivation($item,$issues,$data);$activation='ACTIVATE_CURRENT';$status='CONFIRMED';$supporting=$this->nullText($data['supporting_reconciled_business_key']??null);}
            elseif($type==='PRESERVE_HISTORY_ONLY'){$activation='PRESERVE_HISTORY_ONLY';$status='CONFIRMED';}
            else{$status='REQUIRES_FURTHER_REVIEW';}
        }
        return ['resolution_type'=>$type,'resolution_status'=>$status,'selected_location'=>$selectedLocation,'selected_asc'=>$selectedAsc,'selected_arpa'=>$selectedArpa,'activation_decision'=>$activation,'supporting_key'=>$supporting,'reason'=>$reason,'evidence'=>$evidence];
    }

    private function validateConflictActivation(array $item,array $issues,array $data): void
    {
        if(in_array('PERMANENT_SERVICE_WITH_ATTEND_TO_DUTY',$issues,true))throw new DomainException('Attend to Duty cannot be activated for an Officer proven Permanent in Service.');
        if(array_intersect($issues,['OFFICER_MULTIPLE_ACTING','OFFICER_MULTIPLE_ATTEND_TO_DUTY'])){$lock=$this->target->prepare("SELECT id FROM legacy_arpa_reconciliation_item WHERE active=1 AND item_type='CURRENT_CONFLICT' AND officer_id=? ORDER BY id FOR UPDATE");$lock->execute([$item['officer_id']]);$lock->fetchAll();$s=$this->target->prepare("SELECT COUNT(*) FROM legacy_arpa_reconciliation_item i JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id WHERE i.active=1 AND i.item_type='CURRENT_CONFLICT' AND i.officer_id=? AND i.id<>? AND r.resolution_status='CONFIRMED' AND r.activation_decision='ACTIVATE_CURRENT'");$s->execute([$item['officer_id'],$item['id']]);if((int)$s->fetchColumn()>0)throw new DomainException('Another conflicting current record is already selected for activation.');}
        if(in_array('DEPENDENT_WITHOUT_PERMANENT',$issues,true)){$support=trim((string)($data['supporting_reconciled_business_key']??''));if($support==='')throw new DomainException('Select the supporting active Permanent appointment evidence.');$context=$this->decode($item['context_snapshot_json']);$valid=false;foreach($context['officer_appointment_context']??[] as $row)if(($row['business_key']??'')===$support&&($row['appointment_type']??'')==='PERMANENT'&&!empty($row['current'])){$valid=true;break;}if(!$valid)throw new DomainException('The selected evidence is not a current Permanent appointment for this Officer.');}
    }

    private function arpaAsc(string $arpaId,string $date): string
    {
        $s=$this->target->prepare("SELECT parent_location_id FROM location_relationship WHERE child_location_id=? AND relationship_type='ASC_ARPA_DIVISION' AND active=1 AND approval_status='APPROVED' AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?)");$s->execute([$arpaId,$date,$date]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);
        if($ids===[]){$s=$this->target->prepare("SELECT parent_location_id FROM location_relationship WHERE child_location_id=? AND relationship_type='ASC_ARPA_DIVISION' AND active=1 AND approval_status='APPROVED'");$s->execute([$arpaId]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);}
        if(count(array_unique($ids))!==1)throw new DomainException('Selected ARPA Division does not have exactly one approved ASC parent to snapshot.');return (string)$ids[0];
    }
    private function location(string $id,string $type): array{$s=$this->target->prepare('SELECT l.id FROM location l JOIN location_type t ON t.id=l.location_type_id WHERE l.id=? AND t.system_key=? AND l.operational_status=\'ACTIVE\' AND l.approval_status=\'APPROVED\'');$s->execute([$id,$type]);$r=$s->fetch();if(!$r)throw new DomainException("Select a valid approved {$type} location.");return $r;}
    private function lockedItem(string $id): array{$s=$this->target->prepare('SELECT * FROM legacy_arpa_reconciliation_item WHERE id=? AND active=1 FOR UPDATE');$s->execute([$id]);$r=$s->fetch();if(!$r)throw new DomainException('Reconciliation item was not found.');return $r;}
    private function lockedResolution(string $itemId): ?array{$s=$this->target->prepare('SELECT * FROM legacy_arpa_appointment_resolution WHERE reconciliation_item_id=? FOR UPDATE');$s->execute([$itemId]);return $s->fetch()?:null;}
    private function currentOfficeAssignments(string $officerId): array{$s=$this->target->prepare("SELECT a.id,a.office_id,a.effective_from,a.effective_to,a.is_primary,a.approval_status,o.dad_number office_number,o.name_en office_name,ot.system_key office_type FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id WHERE a.officer_id=? AND a.active=1 AND a.approval_status='APPROVED' AND a.effective_from<=CURRENT_DATE() AND (a.effective_to IS NULL OR a.effective_to>=CURRENT_DATE()) ORDER BY a.is_primary DESC,o.name_en");$s->execute([$officerId]);return $s->fetchAll();}
    private function evidenceSummary(array $item): string{$evidence=$this->decode($item['candidate_evidence_json']??null);$parts=[];foreach(['method','reason','summary','candidate_evidence'] as $key)if(isset($evidence[$key])&&is_scalar($evidence[$key])&&trim((string)$evidence[$key])!=='')$parts[]=trim((string)$evidence[$key]);if(isset($item['evidence_method'])&&trim((string)$item['evidence_method'])!=='')$parts[]=trim((string)$item['evidence_method']);return $parts===[]?'No additional evidence summary was recorded.':implode('; ',array_values(array_unique($parts)));}
    private function decisionSnapshot(array $r): array{return array_intersect_key($r,array_flip(['id','reconciliation_item_id','reconciled_business_key','resolution_type','resolution_status','selected_target_location_id','selected_target_asc_id','selected_target_arpa_id','activation_decision','original_evidence_class','evidence_summary','bulk_operation_id','supporting_reconciled_business_key','decision_reason','evidence_notes','decided_by','decided_at','updated_by','updated_at','version']));}
    private function audit(string $resolutionId,string $itemId,string $action,?array $previous,array $new,string $actor,?string $bulkOperationId=null): void{$s=$this->target->prepare('INSERT INTO legacy_arpa_appointment_resolution_audit(resolution_id,reconciliation_item_id,bulk_operation_id,audit_action,previous_decision_json,new_decision_json,changed_by) VALUES(?,?,?,?,?,?,?)');$s->execute([$resolutionId,$itemId,$bulkOperationId,$action,$previous===null?null:$this->json($previous),$this->json($new),$actor]);}
    private function one(string $sql): array{return $this->target->query($sql)->fetch()?:[];}
    private function decode(mixed $v): array{$a=json_decode((string)$v,true);return is_array($a)?$a:[];}
    private function json(mixed $v): string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);}
    private function nullText(mixed $v): ?string{$v=trim((string)$v);return $v===''?null:$v;}
    private function uuid(): string{$h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-'.dechex((hexdec($h[16])&3)|8).substr($h,17,3).'-'.substr($h,20,12);}
}
