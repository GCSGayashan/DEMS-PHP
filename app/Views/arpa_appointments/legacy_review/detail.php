<?php
use App\Core\{Auth,Csrf,DataTableFormat};
$context=$item['context'];$canDecide=Auth::can('arpa.legacy-reconciliation.decide');
$permanents=array_values(array_filter($context['officer_appointment_context']??[],fn($r)=>($r['appointment_type']??'')==='PERMANENT'));
$selectedType=(string)($item['resolution_type']??'');$selectedAsc=(string)($item['selected_target_asc_id']??'');$selectedArpa=(string)($item['selected_target_arpa_id']??'');
$isSelected=static fn(string $expected,string $actual):string=>$expected===$actual?' selected':'';
?>
<div class="page-heading">
  <div><div class="breadcrumb-lite">ARPA Officer Appointments / Legacy Migration Review / Item</div><h1><?= e($item['officer_number'].' - '.($item['name_with_initials']?:$item['full_name_en'])) ?></h1><p><code><?= e($item['primary_source_table'].':'.$item['primary_source_record_id']) ?></code> &middot; <?= e(implode(', ',$item['issues'])) ?></p></div>
  <div><?= DataTableFormat::badge($item['resolution_status']?:'PENDING') ?></div>
</div>
<div class="row g-3 mb-4">
  <div class="col-lg-7"><div class="form-section h-100"><h2 class="h5">Record context</h2><dl class="row mb-0">
    <dt class="col-sm-4">All source references</dt><dd class="col-sm-8"><?php foreach($item['sources'] as $source): ?><code class="me-2"><?= e($source) ?></code><?php endforeach; ?></dd>
    <dt class="col-sm-4">Officer</dt><dd class="col-sm-8"><?= e($item['officer_number'].' - '.($item['nic']?:'NIC unavailable')) ?></dd>
    <dt class="col-sm-4">Assignment</dt><dd class="col-sm-8"><?= e($item['subject_kind']?:$item['appointment_type']) ?></dd>
    <dt class="col-sm-4">Effective period</dt><dd class="col-sm-8"><?= e((string)$item['effective_from'].' to '.($item['effective_to']?:'Open')) ?></dd>
    <dt class="col-sm-4">Classification</dt><dd class="col-sm-8"><?= DataTableFormat::badge($item['current_classification']) ?></dd>
    <dt class="col-sm-4">Confidence</dt><dd class="col-sm-8"><?= DataTableFormat::badge($item['source_confidence']) ?></dd>
    <dt class="col-sm-4">Workflow state</dt><dd class="col-sm-8"><?= DataTableFormat::badge($context['workflow_state']??'') ?></dd>
    <dt class="col-sm-4">Service permanency</dt><dd class="col-sm-8"><?= e(($context['service_permanency']??'Unknown').' / '.($context['service_permanency_source']??'Unknown')) ?></dd>
  </dl></div></div>
  <div class="col-lg-5"><div class="form-section h-100"><h2 class="h5">Candidate and evidence</h2>
    <p><strong><?= e(trim((string)($item['candidate_asc_number']??'').' '.(string)($item['candidate_asc_name']??''))?:'No candidate ASC') ?></strong></p>
    <p class="small mb-2"><strong>Candidate ASC Office:</strong> <?= e(trim((string)($item['candidate_office_number']??'').' '.(string)($item['candidate_office_name']??''))?:'Missing') ?></p>
    <p class="small text-muted mb-2"><?= e((string)($context['candidate_evidence']??'No deterministic evidence')) ?></p>
    <div class="small"><strong>Workflow actors</strong><ul><?php foreach($context['workflow_actors']??[] as $stage=>$actor): ?><li><?= e($stage.': '.($actor['target_user']['display_name']??$actor['target_user']['username']??('legacy user '.$actor['legacy_user_id']))) ?></li><?php endforeach; ?></ul></div>
    <?php if(!empty($context['candidate_legacy_context'])): ?><details><summary class="small">Legacy candidate payload</summary><pre class="small text-wrap mb-0"><?= e(json_encode($context['candidate_legacy_context'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
  </div></div>
</div>
<?php if($item['item_type']==='SPECIAL_ASC'): ?><div class="form-section mb-4"><h2 class="h5">Current Office context</h2>
<?php if($item['current_office_assignments']===[]): ?><p class="text-muted mb-0">No current approved Office assignment.</p><?php else: ?><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Office</th><th>Type</th><th>Period</th><th>Primary</th></tr></thead><tbody><?php foreach($item['current_office_assignments'] as $assignment): ?><tr><td><?= e($assignment['office_number'].' - '.$assignment['office_name']) ?></td><td><?= e($assignment['office_type']) ?></td><td><?= e($assignment['effective_from'].' to '.($assignment['effective_to']?:'Open')) ?></td><td><?= $assignment['is_primary']?'Yes':'No' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div><?php endif; ?>
<?php if($item['item_type']==='MISSING_ARPA_LOCATION'): ?>
<div class="alert alert-warning"><strong>No ARPA Division is preselected.</strong> Related appointments mention Hidagahawewa, Midiyala and Hammaliya, but none proves this record's location. Review the evidence and make an explicit selection.</div>
<?php if(!empty($context['missing_location_evidence'])): ?><div class="form-section mb-4"><h2 class="h5">Related historical evidence</h2><pre class="small text-wrap mb-0"><?= e(json_encode($context['missing_location_evidence'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre></div><?php endif; ?>
<?php endif; ?>
<?php if($item['item_type']==='SPECIAL_ASC'&&$item['current_classification']==='CURRENT'): ?><div class="alert alert-info">Leaving this current record unresolved preserves it for historical review but does not clear its operational-activation blocker. A confirmed ASC is required for migration readiness.</div><?php endif; ?>
<?php if($item['item_type']==='CURRENT_CONFLICT'): ?>
<div class="form-section mb-4"><h2 class="h5">Complete Officer appointment context</h2><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Source</th><th>Type</th><th>Location</th><th>From</th><th>To</th><th>State</th><th>Permanency</th></tr></thead><tbody>
<?php foreach($context['officer_appointment_context']??[] as $row): ?><tr><td><code><?= e(implode('|',$row['source_references'])) ?></code></td><td><?= e($row['appointment_type']?:$row['level']) ?></td><td><?= e((string)($row['legacy_context']['arpa_name']??$row['legacy_context']['asc_name']??'Unknown')) ?></td><td><?= e((string)$row['effective_from']) ?></td><td><?= e((string)($row['effective_to']??'Open')) ?></td><td><?= DataTableFormat::badge($row['current']?'CURRENT':'HISTORICAL') ?></td><td><?= e(($row['service_permanency']??'Unknown').' / '.($row['service_permanency_source']??'Unknown')) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php if($canDecide): ?>
<form method="post" class="form-section mb-4"><?= Csrf::field() ?><input type="hidden" name="resolution_id" value="<?= e((string)($item['resolution_id']??'')) ?>"><input type="hidden" name="version" value="<?= (int)($item['resolution_version']??0) ?>"><h2 class="h5">Record reconciliation decision</h2><div class="row g-3">
  <div class="col-lg-5"><label class="form-label" for="resolution-type">Decision *</label><select id="resolution-type" class="form-select" name="resolution_type" required><option value="">Select explicit decision</option>
  <?php if($item['item_type']==='SPECIAL_ASC'): ?><?php if($selectedType==='CONFIRM_ASC'): ?><option value="CONFIRM_ASC" selected>Confirmed ASC (bulk reviewed)</option><?php endif; ?><?php if(!empty($item['candidate_asc_id'])): ?><option value="CONFIRM_CANDIDATE_ASC"<?= $isSelected('CONFIRM_CANDIDATE_ASC',$selectedType) ?>>Confirm Candidate ASC</option><?php endif; ?><option value="SELECT_DIFFERENT_ASC"<?= $isSelected('SELECT_DIFFERENT_ASC',$selectedType) ?>><?= empty($item['candidate_asc_id'])?'Select ASC':'Select Different ASC' ?></option><option value="PRESERVE_HISTORY_ONLY"<?= $isSelected('PRESERVE_HISTORY_ONLY',$selectedType) ?>>Preserve History Only</option><?php if($item['current_classification']!=='CURRENT'): ?><option value="UNRESOLVED_HISTORICAL"<?= $isSelected('UNRESOLVED_HISTORICAL',$selectedType) ?>>Leave Unresolved - Historical Only</option><?php endif; ?>
  <?php elseif($item['item_type']==='MISSING_ARPA_LOCATION'): ?><option value="SELECT_ARPA_DIVISION"<?= $isSelected('SELECT_ARPA_DIVISION',$selectedType) ?>>Select ARPA Division</option><option value="PRESERVE_HISTORY_ONLY"<?= $isSelected('PRESERVE_HISTORY_ONLY',$selectedType) ?>>Preserve History Only</option>
  <?php else: ?><option value="ACTIVATE_CURRENT"<?= $isSelected('ACTIVATE_CURRENT',$selectedType) ?>>Activate Current</option><option value="PRESERVE_HISTORY_ONLY"<?= $isSelected('PRESERVE_HISTORY_ONLY',$selectedType) ?>>Preserve History Only</option><option value="REQUIRES_FURTHER_REVIEW"<?= $isSelected('REQUIRES_FURTHER_REVIEW',$selectedType) ?>>Requires Further Review</option><?php endif; ?>
  </select></div>
<?php if($item['item_type']==='SPECIAL_ASC'): ?><div class="col-lg-7"><label class="form-label" for="selected-asc">Different ASC (required only when selecting different)</label><select id="selected-asc" class="form-select" name="selected_target_asc_id" data-searchable-select="Search ASC by DAD number or name"><option value="">Select ASC</option><?php foreach($ascs as $r): ?><option value="<?= e($r['id']) ?>"<?= $isSelected((string)$r['id'],$selectedAsc) ?>><?= e($r['dad_number'].' - '.$r['name_en']) ?></option><?php endforeach; ?></select></div>
<?php elseif($item['item_type']==='MISSING_ARPA_LOCATION'): ?><div class="col-lg-7"><label class="form-label" for="selected-arpa">ARPA Division *</label><select id="selected-arpa" class="form-select" name="selected_target_arpa_id" data-searchable-select="Search ARPA Division by DAD number or name"><option value="">Select ARPA Division</option><?php foreach($arpaDivisions as $r): ?><option value="<?= e($r['id']) ?>"<?= $isSelected((string)$r['id'],$selectedArpa) ?>><?= e($r['dad_number'].' - '.$r['name_en']) ?></option><?php endforeach; ?></select></div>
<?php elseif(in_array('DEPENDENT_WITHOUT_PERMANENT',$item['issues'],true)): ?><div class="col-lg-7"><label class="form-label" for="supporting-permanent">Supporting current Permanent appointment</label><select id="supporting-permanent" class="form-select" name="supporting_reconciled_business_key"><option value="">Select evidence</option><?php foreach($permanents as $r): ?><option value="<?= e($r['business_key']) ?>"<?= $isSelected((string)$r['business_key'],(string)($item['supporting_reconciled_business_key']??'')) ?>><?= e(implode('|',$r['source_references']).' - '.($r['legacy_context']['arpa_name']??'Unknown').' - '.$r['effective_from'].' to '.($r['effective_to']??'Open')) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="col-12"><label class="form-label" for="decision-reason">Decision reason *</label><textarea id="decision-reason" class="form-control" name="decision_reason" rows="2" maxlength="5000" required><?= e((string)($item['decision_reason']??'')) ?></textarea></div>
  <div class="col-12"><label class="form-label" for="evidence-notes">Evidence notes</label><textarea id="evidence-notes" class="form-control" name="evidence_notes" rows="3" maxlength="10000"><?= e((string)($item['evidence_notes']??'')) ?></textarea></div>
</div><button class="btn btn-primary mt-3">Save Audited Decision</button> <a class="btn btn-outline-secondary mt-3" href="<?= e(url('hr/arpa-appointments/legacy-review')) ?>">Back</a></form>
<?php endif; ?>
<div class="form-section"><h2 class="h5">Decision audit history</h2>
<?php if($audit===[]): ?><p class="text-muted mb-0">No decision has been recorded.</p>
<?php else: ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Bulk operation</th><th>Previous</th><th>New</th></tr></thead><tbody><?php foreach($audit as $row): ?><tr><td><?= e($row['changed_at']) ?></td><td><?= e($row['username']) ?></td><td><?= e($row['audit_action']) ?></td><td><code><?= e((string)($row['bulk_operation_id']??'-')) ?></code></td><td><pre class="small mb-0 text-wrap"><?= e((string)($row['previous_decision_json']??'-')) ?></pre></td><td><pre class="small mb-0 text-wrap"><?= e($row['new_decision_json']) ?></pre></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
