<?php
use App\Core\{Csrf,DataTableFormat};
use App\Services\ArpaAppointmentIssuePresentation;
require BASE_PATH.'/app/Views/arpa_appointments/tabs.php';
$dash='Not available';
$legacyMeta=function(array $row):array{$m=json_decode((string)($row['origin_metadata_json']??'[]'),true);return is_array($m)?$m:[];};
?>
<div class="page-heading">
  <div><div class="breadcrumb-lite">ARPA Officer Assignments / Data Issues / Review</div><h1><?= e($presentation['title']) ?></h1><p><?= e($presentation['explanation']) ?></p></div>
  <div><?= DataTableFormat::badge(ArpaAppointmentIssuePresentation::severity((string)$issue['severity'])) ?></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8"><div class="form-section h-100">
    <h2 class="h5">Issue</h2><p><?= e($presentation['title']) ?></p>
    <h2 class="h5">Problem</h2><p><?= e($presentation['explanation']) ?></p>
    <h2 class="h5">What to check</h2><p class="mb-0"><?= e($presentation['what_to_check']) ?></p>
  </div></div>
  <div class="col-lg-4"><div class="form-section h-100">
    <h2 class="h5">Appointment</h2><dl class="row mb-0">
      <dt class="col-5">Officer</dt><dd class="col-7"><?php if($issue['officer_id']): ?><a href="<?= e(url('hr/officers/'.$issue['officer_id'])) ?>"><?= e($issue['officer_name']) ?></a><?php else: ?><?= e($issue['officer_name']?:'Multiple Officers') ?><?php endif; ?></dd>
      <dt class="col-5">Officer Number</dt><dd class="col-7"><?= e($issue['officer_number']) ?></dd>
      <dt class="col-5">NIC</dt><dd class="col-7"><?= e($issue['nic']?:$dash) ?></dd>
      <dt class="col-5">ASC</dt><dd class="col-7"><?= e($issue['asc_name']?:$dash) ?></dd>
      <dt class="col-5">ARPA Division</dt><dd class="col-7"><?= e($issue['arpa_divisions']?:$dash) ?></dd>
      <dt class="col-5">Appointment Type</dt><dd class="col-7"><?= e(DataTableFormat::enumLabel($issue['appointment_types']?:null,$dash)) ?></dd>
      <dt class="col-5">Appointment Period</dt><dd class="col-7"><?= e($issue['effective_periods']?:$dash) ?></dd>
    </dl>
  </div></div>
</div>

<?php if($issue['issue_type']==='APPOINTMENT_OUTSIDE_ASC' && $hierarchy_contexts): ?>
<div class="card mb-4"><div class="card-body"><h2 class="h5">Location Check</h2>
  <?php foreach($hierarchy_contexts as $context): ?>
    <?php if($context['is_old_appointment']): ?><div class="alert alert-info">Old appointment: DEMS checked this location using the location structure from 05 January 2024.</div><?php endif; ?>
    <dl class="row mb-3">
      <dt class="col-sm-4">Appointment Start Date</dt><dd class="col-sm-8"><?= DataTableFormat::date($context['appointment_start_date']) ?></dd>
      <dt class="col-sm-4">Location Check Date</dt><dd class="col-sm-8"><?= DataTableFormat::date($context['location_validation_date']) ?></dd>
      <dt class="col-sm-4">Recorded ASC</dt><dd class="col-sm-8"><?= e($context['recorded_asc_name']?:$dash) ?></dd>
      <dt class="col-sm-4">Recorded ARPA Division</dt><dd class="col-sm-8"><?= e($context['recorded_arpa_name']?:$dash) ?></dd>
      <dt class="col-sm-4">Correct ASC from Location Master</dt><dd class="col-sm-8"><?= e($context['correct_ascs']?implode(', ',array_column($context['correct_ascs'],'name_en')):$dash) ?></dd>
      <dt class="col-sm-4">Result</dt><dd class="col-sm-8"><?= e($context['result']) ?></dd>
    </dl>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>

<div class="card mb-4"><div class="card-body"><h2 class="h5">Appointment Records</h2><div class="table-responsive"><table class="table table-bordered align-middle"><thead><tr><th>Officer</th><th>ASC / ARPA Division</th><th>Appointment Type</th><th>Appointment Period</th><th>Status</th><th>History</th></tr></thead><tbody>
<?php if(!$appointments): ?><tr><td colspan="6" class="text-center text-muted">No appointment record is attached to this issue.</td></tr><?php endif; ?>
  <?php foreach($appointments as $a): ?><tr><td><?= e($a['appointment_officer_number'].' - '.$a['appointment_officer_name']) ?></td><td><?= e($a['asc_name_snapshot']) ?><br><strong><?= e($a['arpa_dad_snapshot'].' - '.$a['arpa_name_snapshot']) ?></strong></td><td><?= e(DataTableFormat::enumLabel($a['appointment_type'])) ?></td><td><?= DataTableFormat::date($a['effective_from']) ?> to <?= DataTableFormat::date($a['effective_to'],'Open') ?></td><td><?= (int)$a['legacy_history_only']===1?DataTableFormat::badge('Old Record - History Only'):DataTableFormat::badge('Current Appointment') ?> <?= (int)$a['legacy_exception']===1?DataTableFormat::badge('Old Data Warning'):'' ?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= e(url('hr/arpa-appointments/divisions/'.$a['id'])) ?>">View Appointment</a></td></tr><?php endforeach; ?>
</tbody></table></div></div></div>

<?php if($permanent_appointments): ?><div class="card mb-4"><div class="card-body"><h2 class="h5">Permanent Appointment History</h2><p class="small text-muted">This includes Permanent appointments before 2025. DEMS does not create a missing Permanent appointment from this page.</p><div class="table-responsive"><table class="table table-sm"><thead><tr><th>ARPA Division</th><th>ASC</th><th>Start Date</th><th>End Date</th><th>Status</th></tr></thead><tbody><?php foreach($permanent_appointments as $p): ?><tr><td><?= e($p['arpa_name_snapshot']) ?></td><td><?= e($p['asc_name_snapshot']) ?></td><td><?= DataTableFormat::date($p['effective_from']) ?></td><td><?= DataTableFormat::date($p['effective_to'],'Open') ?></td><td><?= (int)$p['legacy_history_only']===1?DataTableFormat::badge('Old Record - History Only'):DataTableFormat::badge('Current Appointment') ?></td></tr><?php endforeach; ?></tbody></table></div></div></div><?php endif; ?>

<?php if($correctable&&$historical_request): ?>
<div class="card border-warning mb-4"><div class="card-body">
  <div class="alert alert-warning"><strong>No normal approval workflow is required for this evidence-backed historical correction.</strong><br>The original imported source, previous values, correction, and reviewer remain in the audit history.</div>
  <h2 class="h5">Resolve Historical Record into Canonical Assignments</h2>
  <form method="post" action="<?= e(url('hr/arpa-appointments/issues/'.rawurlencode($issue['row_key']).'/correct')) ?>"><?= Csrf::field() ?><div class="row g-3">
    <div class="col-md-6"><label class="form-label">Officer *</label><select class="form-select" name="officer_id" required data-searchable-select="Search Officer"><?php foreach($officers as $officer): ?><option value="<?= e($officer['id']) ?>" <?= $officer['id']===$historical_request['officer_id']?'selected':'' ?>><?= e($officer['dad_number'].' - '.$officer['name_with_initials'].($officer['nic']?' / '.$officer['nic']:'')) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label">ARPA Division *</label><select class="form-select" name="arpa_division_location_id" required data-searchable-select="Search ARPA Division"><?php foreach($arpa_divisions as $division): ?><option value="<?= e($division['id']) ?>" <?= $division['id']===$historical_request['arpa_division_location_id']?'selected':'' ?>><?= e($division['dad_number'].' - '.$division['name_en']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Appointment Type *</label><select class="form-select" name="appointment_type" required><?php foreach(['PERMANENT'=>'Permanent','ACTING'=>'Acting','DUTY_COVERING'=>'Duty Covering','ATTEND_TO_DUTY'=>'Attend to the Duty'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $value===$historical_request['appointment_type']?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Start Date *</label><input class="form-control" type="date" name="effective_from" value="<?= e($historical_request['requested_effective_from']) ?>" required></div>
    <div class="col-md-4"><label class="form-label">Appointment Status *</label><select class="form-select" name="appointment_status" id="historical-appointment-status" required><option value="OPEN" <?= !$historical_request['requested_effective_to']?'selected':'' ?>>Open</option><option value="CLOSED" <?= $historical_request['requested_effective_to']?'selected':'' ?>>Closed</option></select></div>
    <div class="col-md-4"><label class="form-label">End Date</label><input class="form-control" type="date" name="effective_to" value="<?= e($historical_request['requested_effective_to']??'') ?>"><div class="form-text">Leave empty when status is Open.</div></div>
    <div class="col-md-4"><label class="form-label">End Reason</label><select class="form-select" name="end_reason_id"><option value="">Not available / Open</option><?php foreach($end_reasons as $endReason): ?><option value="<?= e($endReason['id']) ?>" <?= $endReason['id']===$historical_request['end_reason_id']?'selected':'' ?>><?= e($endReason['name_en']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Service Permanency</label><select class="form-select" name="service_permanency_snapshot"><option value="">Unknown</option><option value="PERMANENT_IN_SERVICE" <?= ($historical_request['source_service_permanency_snapshot']??null)==='PERMANENT_IN_SERVICE'?'selected':'' ?>>Permanent in Service</option><option value="NOT_PERMANENT_IN_SERVICE" <?= ($historical_request['source_service_permanency_snapshot']??null)==='NOT_PERMANENT_IN_SERVICE'?'selected':'' ?>>Not Permanent in Service</option></select></div>
    <div class="col-12"><label class="form-label">Supporting Record</label><input class="form-control" name="evidence_reference" maxlength="500"></div>
    <div class="col-12"><label class="form-label">Reason for Correction *</label><input class="form-control" name="correction_reason" maxlength="500" required></div>
    <div class="col-12"><label class="form-label">Correction Notes</label><textarea class="form-control" name="remarks" rows="3"><?= e($historical_request['request_remarks']??'') ?></textarea></div>
    <div class="col-12 d-flex gap-2 justify-content-end"><button class="btn btn-outline-secondary" type="submit" name="correction_action" value="KEEP_AS_HISTORICAL_EXCEPTION">Mark Not Applicable / Historical Only</button><button class="btn btn-warning" type="submit" name="correction_action" value="RESOLVE_CANONICAL_ASSIGNMENT">Resolve &amp; Add to Assignments</button></div>
  </div></form>
</div></div>
<?php elseif($correctable): ?><div class="card border-warning mb-4"><div class="card-body"><div class="alert alert-warning"><strong>No approval is needed for this data correction.</strong><br>The correction and the previous values will remain in the history.</div><h2 class="h5">Correct Record</h2><form method="post" action="<?= e(url('hr/arpa-appointments/issues/'.rawurlencode($issue['row_key']).'/correct')) ?>"><?= Csrf::field() ?><div class="row g-3">
  <div class="col-md-6"><label class="form-label">Correction</label><select class="form-select" name="correction_action" required><option value="">Select correction</option><?php foreach(['MARK_HISTORICAL_ONLY','SET_EFFECTIVE_TO','CORRECT_APPOINTMENT_TYPE','CORRECT_ARPA_DIVISION','CORRECT_EFFECTIVE_FROM','CORRECT_END_REASON','SELECT_CURRENT_RECORD','KEEP_AS_HISTORICAL_EXCEPTION'] as $action): ?><option value="<?= e($action) ?>"><?= e(ArpaAppointmentIssuePresentation::action($action)) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label">Appointment to Correct</label><select class="form-select" name="appointment_id"><option value="">Select appointment</option><?php foreach($appointments as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['arpa_dad_snapshot'].' / '.DataTableFormat::enumLabel($a['appointment_type']).' / '.$a['effective_from']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Correct Start Date</label><input class="form-control" type="date" name="effective_from"></div>
  <div class="col-md-4"><label class="form-label">Correct End Date</label><input class="form-control" type="date" name="effective_to"></div>
  <div class="col-md-4"><label class="form-label">Correct Appointment Type</label><select class="form-select" name="appointment_type"><option value="">Select type</option><?php foreach(['PERMANENT'=>'Permanent','ACTING'=>'Acting','DUTY_COVERING'=>'Duty Covering','ATTEND_TO_DUTY'=>'Attend to the Duty'] as $value=>$label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label">Correct ARPA Division</label><select class="form-select" name="arpa_division_location_id" data-searchable-select="Search ARPA Division"><option value="">Select ARPA Division</option><?php foreach($arpa_divisions as $division): ?><option value="<?= e($division['id']) ?>"><?= e($division['dad_number'].' - '.$division['name_en']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label">Correct End Reason</label><select class="form-select" name="end_reason_id"><option value="">Select reason</option><?php foreach($end_reasons as $reason): ?><option value="<?= e($reason['id']) ?>"><?= e($reason['name_en']) ?></option><?php endforeach; ?></select></div>
  <div class="col-12"><label class="form-label">Supporting Record</label><input class="form-control" name="evidence_reference" maxlength="500" placeholder="Required when changing the ARPA Division"></div>
  <div class="col-12"><label class="form-label">Reason for Correction *</label><input class="form-control" name="correction_reason" maxlength="500" required><div class="form-text">Please briefly explain why this record is being corrected.</div></div>
  <div class="col-12"><label class="form-label">Additional Notes</label><textarea class="form-control" name="remarks" rows="3"></textarea></div>
  <div class="col-12 d-flex justify-content-end"><button class="btn btn-warning" type="submit">Correct Record</button></div>
</div></form></div></div><?php else: ?><div class="alert alert-secondary"><strong>Review only.</strong> You cannot correct this record from your current ASC access. Use the normal appointment process when this is a new appointment, transfer, or appointment ending.</div><?php endif; ?>

<?php if($corrections): ?><div class="card mb-4"><div class="card-body"><h2 class="h5">Review and Correction History</h2><div class="table-responsive"><table class="table table-striped"><thead><tr><th>Action</th><th>Result</th><th>Reason</th><th>Reviewed By</th><th>Reviewed At</th></tr></thead><tbody><?php foreach($corrections as $c): ?><tr><td><?= e(ArpaAppointmentIssuePresentation::action($c['correction_action'])) ?></td><td><?= DataTableFormat::badge(ArpaAppointmentIssuePresentation::resolution($c['resolution_status'])) ?></td><td><?= e($c['correction_reason']) ?></td><td><?= e($c['display_name']?:$c['username']) ?></td><td><?= DataTableFormat::dateTime($c['corrected_at']) ?></td></tr><?php endforeach; ?></tbody></table></div></div></div><?php endif; ?>

<?php if($technical_details_allowed): ?><details class="card mb-4"><summary class="card-header fw-semibold">Technical details</summary><div class="card-body"><dl class="row mb-0"><dt class="col-sm-4">Internal issue code</dt><dd class="col-sm-8"><code><?= e($issue['issue_type']) ?></code></dd><dt class="col-sm-4">Issue record</dt><dd class="col-sm-8"><code><?= e($issue['row_key']) ?></code></dd><dt class="col-sm-4">Appointment / request references</dt><dd class="col-sm-8"><code><?= e($issue['related_ids']) ?></code></dd><dt class="col-sm-4">Internal reason</dt><dd class="col-sm-8"><?= e($issue['explanation']) ?></dd><?php if($hierarchy_contexts): ?><dt class="col-sm-4">Validation date</dt><dd class="col-sm-8"><?= e(implode(', ',array_unique(array_column($hierarchy_contexts,'location_validation_date')))) ?></dd><?php endif; ?></dl><?php foreach($appointments as $a):$meta=$legacyMeta($a);if(!$meta)continue; ?><hr><div class="small"><strong>Appointment:</strong> <code><?= e($a['id']) ?></code><br><strong>Legacy source:</strong> <?= e((string)($meta['source_table']??$meta['primary_source_table']??$dash)) ?> / <?= e((string)($meta['source_row_id']??$dash)) ?></div><?php endforeach; ?></div></details><?php endif; ?>

<a class="btn btn-outline-secondary" href="<?= e(url('hr/arpa-appointments/issues')) ?>">Back to Appointment Data Issues</a>
