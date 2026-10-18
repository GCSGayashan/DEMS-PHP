<?php
use App\Core\{Csrf,DataTableFormat};
use App\Services\ArpaAppointmentIssuePresentation;
$baseline=$eligibility['baseline']??($request['baseline']??null);$appointment=$baseline['appointment']??[];$closure=$baseline['closure']??null;$source=$baseline['source_correction']??[];$linkedRequest=$baseline['request']??[];
$editing=$mode==='edit';$action=(string)($form['proposed_action']??'');
$actions=['MARK_HISTORICAL_ONLY','SET_EFFECTIVE_TO','CORRECT_APPOINTMENT_TYPE','CORRECT_ARPA_DIVISION','CORRECT_EFFECTIVE_FROM','CORRECT_END_REASON','SELECT_CURRENT_RECORD','KEEP_AS_HISTORICAL_EXCEPTION','RESOLVE_CANONICAL_ASSIGNMENT','CLEAR_EFFECTIVE_TO'];
$actionLabel=static fn(string $value):string=>ArpaAppointmentIssuePresentation::action($value);
?>
<div class="page-heading"><div><div class="breadcrumb-lite">ARPA Officer Assignments / Correction History / Amendment Draft</div><h1><?= $editing?'Edit Correction Proposal':'Request Correction' ?></h1><p>Propose a governed amendment to the existing correction decision.</p></div><?= $request?DataTableFormat::badge((string)$request['workflow_status']):DataTableFormat::badge('DRAFT') ?></div>
<div class="alert alert-warning"><strong>Editing this proposal does not change the appointment.</strong><br>Save the draft, review the complete change set, and submit it from the proposal detail page when ready.</div>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if(!$baseline): ?><div class="alert alert-danger">The original correction information is unavailable.</div><?php else: ?>
<div class="row g-3 mb-4">
  <div class="col-lg-6"><div class="form-section h-100"><h2 class="h5">Original Correction Decision</h2><dl class="row mb-0">
    <dt class="col-sm-5">Issue</dt><dd class="col-sm-7"><?= e(DataTableFormat::enumLabel($source['issue_type']??null,'—')) ?></dd>
    <dt class="col-sm-5">Decision</dt><dd class="col-sm-7"><?= e($actionLabel((string)($source['correction_action']??''))) ?></dd>
    <dt class="col-sm-5">Resolution</dt><dd class="col-sm-7"><?= e(DataTableFormat::enumLabel($source['resolution_status']??null,'—')) ?></dd>
    <dt class="col-sm-5">Reason</dt><dd class="col-sm-7"><?= e($source['correction_reason']??'—') ?></dd>
    <dt class="col-sm-5">Evidence</dt><dd class="col-sm-7"><?= e($source['evidence_reference']??'—') ?></dd>
  </dl></div></div>
  <div class="col-lg-6"><div class="form-section h-100"><h2 class="h5">Current Appointment Values</h2><dl class="row mb-0">
    <dt class="col-sm-5">Appointment Type</dt><dd class="col-sm-7"><?= e(DataTableFormat::enumLabel($appointment['appointment_type']??null,'—')) ?></dd>
    <dt class="col-sm-5">ASC</dt><dd class="col-sm-7"><?= e(($appointment['hierarchy_snapshot']['asc']['name_en']??null)?:'—') ?></dd>
    <dt class="col-sm-5">ARPA Division</dt><dd class="col-sm-7"><?= e(($appointment['hierarchy_snapshot']['arpa']['name_en']??null)?:'—') ?></dd>
    <dt class="col-sm-5">Start Date</dt><dd class="col-sm-7"><?= DataTableFormat::date($appointment['effective_from']??null,'—') ?></dd>
    <dt class="col-sm-5">End Date</dt><dd class="col-sm-7"><?= DataTableFormat::date($closure['effective_to']??null,'Open') ?></dd>
    <dt class="col-sm-5">Workflow Status</dt><dd class="col-sm-7"><?= e(DataTableFormat::enumLabel($linkedRequest['workflow_status']??null,'—')) ?></dd>
  </dl></div></div>
</div>
<form method="post" action="<?= e($editing?url('hr/arpa-appointments/correction-amendments/'.$request['id'].'/edit'):url('hr/arpa-appointments/issues/corrections/'.$sourceCorrectionId.'/amendments')) ?>">
  <?= Csrf::field() ?><?php if($editing): ?><input type="hidden" name="version" value="<?= e((string)$request['version']) ?>"><?php endif; ?>
  <div class="form-section mb-4"><h2 class="h5">Proposed Change</h2><div class="row g-3">
    <div class="col-12"><label class="form-label" for="proposed-action">Proposed Action *</label><select class="form-select" id="proposed-action" name="proposed_action" required><option value="">Select an action</option><?php foreach($actions as $value): ?><option value="<?= e($value) ?>" <?= $action===$value?'selected':'' ?>><?= e($actionLabel($value)) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6 amendment-action-field" data-actions="CORRECT_EFFECTIVE_FROM"><label class="form-label">Proposed Start Date</label><input class="form-control" type="date" name="effective_from" value="<?= e($form['effective_from']??'') ?>"></div>
    <div class="col-md-6 amendment-action-field" data-actions="SET_EFFECTIVE_TO"><label class="form-label">Proposed End Date</label><input class="form-control" type="date" name="effective_to" value="<?= e($form['effective_to']??'') ?>"></div>
    <div class="col-md-6 amendment-action-field" data-actions="CORRECT_APPOINTMENT_TYPE"><label class="form-label">Proposed Appointment Type</label><select class="form-select" name="appointment_type"><option value="">Select type</option><?php foreach(($eligibility['appointment_types']??[]) as $value): ?><option value="<?= e($value) ?>" <?= ($form['appointment_type']??'')===$value?'selected':'' ?>><?= e(DataTableFormat::enumLabel($value)) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6 amendment-action-field" data-actions="CORRECT_ARPA_DIVISION"><label class="form-label">Proposed ARPA Division</label><select class="form-select" name="arpa_division_location_id" data-searchable-select="Search ARPA Division"><option value="">Select ARPA Division</option><?php foreach(($eligibility['arpa_divisions']??[]) as $division): ?><option value="<?= e($division['id']) ?>" <?= ($form['arpa_division_location_id']??'')===$division['id']?'selected':'' ?>><?= e($division['dad_number'].' - '.$division['name_en']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6 amendment-action-field" data-actions="SET_EFFECTIVE_TO CORRECT_END_REASON"><label class="form-label">Proposed End Reason</label><select class="form-select" name="end_reason_id"><option value="">No reason / unchanged</option><?php foreach(($eligibility['end_reasons']??[]) as $reason): ?><option value="<?= e($reason['id']) ?>" <?= ($form['end_reason_id']??'')===$reason['id']?'selected':'' ?>><?= e($reason['name_en']) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 amendment-action-field" data-actions="SELECT_CURRENT_RECORD"><div class="alert alert-light border mb-0">The linked canonical appointment shown above will be proposed as the current record. Its identity is derived by DEMS and cannot be edited here.</div></div>
    <div class="col-12"><label class="form-label">Reason for Correction *</label><input class="form-control" name="correction_reason" maxlength="500" value="<?= e($form['correction_reason']??'') ?>" required></div>
    <div class="col-12"><label class="form-label">Remarks</label><textarea class="form-control" name="remarks" rows="3"><?= e($form['remarks']??'') ?></textarea></div>
    <div class="col-12"><label class="form-label">Evidence Reference</label><input class="form-control" name="evidence_reference" maxlength="500" value="<?= e($form['evidence_reference']??'') ?>"><div class="form-text">Required when proposing an ARPA Division correction.</div></div>
    <div class="col-12 d-flex justify-content-end gap-2"><a class="btn btn-outline-secondary" href="<?= e($request?url('hr/arpa-appointments/correction-amendments/'.$request['id']):url('hr/arpa-appointments/issues/corrections/'.$sourceCorrectionId)) ?>">Cancel</a><button class="btn btn-primary" type="submit">Save Draft</button></div>
  </div></div>
</form>
<script data-cfasync="false" nonce="<?= e(\App\Core\SecurityHeaders::nonce()) ?>">
(()=>{const select=document.getElementById('proposed-action');const fields=[...document.querySelectorAll('.amendment-action-field')];const refresh=()=>fields.forEach(field=>{const visible=field.dataset.actions.split(' ').includes(select.value);field.hidden=!visible;field.querySelectorAll('input,select').forEach(input=>{input.disabled=!visible;});});select.addEventListener('change',refresh);refresh();})();
</script>
<?php endif; ?>
