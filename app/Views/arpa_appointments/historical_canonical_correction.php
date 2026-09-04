<?php
use App\Core\{Csrf,DataTableFormat};
require BASE_PATH.'/app/Views/arpa_appointments/tabs.php';
$closed=$record['effective_to']!==null;
?>
<div class="page-heading"><div><div class="breadcrumb-lite">ARPA Officer Assignments / Corrected Historical Assignment</div><h1>Correct Assignment End Details</h1><p>Update the canonical assignment created through Appointment Data Issue resolution. The change is audited and the source record is retained.</p></div><?= DataTableFormat::badge($closed?'CLOSED':'OPEN') ?></div>
<div class="row g-3 mb-4"><div class="col-lg-7"><div class="form-section h-100"><h2 class="h5">Assignment</h2><dl class="row mb-0">
  <dt class="col-sm-4">Officer</dt><dd class="col-sm-8"><?= e($record['officer_name']) ?></dd>
  <dt class="col-sm-4">ARPA Division</dt><dd class="col-sm-8"><?= e($record['arpa_dad_snapshot'].' - '.$record['arpa_name_snapshot']) ?></dd>
  <dt class="col-sm-4">Appointment Type</dt><dd class="col-sm-8"><?= DataTableFormat::badge($record['appointment_type']) ?></dd>
  <dt class="col-sm-4">Start Date</dt><dd class="col-sm-8"><?= DataTableFormat::date($record['effective_from']) ?></dd>
  <dt class="col-sm-4">Current End Date</dt><dd class="col-sm-8"><?= DataTableFormat::date($record['effective_to'],'Open') ?></dd>
</dl></div></div>
<div class="col-lg-5"><div class="alert alert-info h-100 mb-0"><strong>Timeline protection</strong><br>DEMS will reject an end date that creates a gap or overlap with a following assignment or reservation. Reopening is blocked when a later assignment exists.</div></div></div>
<div class="card"><div class="card-body"><form method="post" action="<?= e(url('hr/arpa-appointments/divisions/'.$record['id'].'/correct-historical')) ?>"><?= Csrf::field() ?><div class="row g-3">
  <div class="col-md-4"><label class="form-label">Appointment Status *</label><select class="form-select" name="appointment_status" required><option value="OPEN" <?= !$closed?'selected':'' ?>>Open</option><option value="CLOSED" <?= $closed?'selected':'' ?>>Closed</option></select></div>
  <div class="col-md-4"><label class="form-label">End Date</label><input class="form-control" type="date" name="effective_to" value="<?= e($record['effective_to']??'') ?>"><div class="form-text">Ignored and stored as NULL when status is Open.</div></div>
  <div class="col-md-4"><label class="form-label">End Reason</label><select class="form-select" name="end_reason_id"><option value="">Not available / Open</option><?php foreach($end_reasons as $reason): ?><option value="<?= e($reason['id']) ?>" <?= $reason['id']===$record['end_reason_id']?'selected':'' ?>><?= e($reason['name_en']) ?></option><?php endforeach; ?></select></div>
  <div class="col-12"><label class="form-label">Reason for Correction *</label><input class="form-control" name="correction_reason" maxlength="500" required></div>
  <div class="col-12"><label class="form-label">Supporting Record</label><input class="form-control" name="evidence_reference" maxlength="500"></div>
  <div class="col-12"><label class="form-label">Additional Notes</label><textarea class="form-control" name="remarks" rows="3"></textarea></div>
  <div class="col-12 d-flex gap-2 justify-content-end"><a class="btn btn-outline-secondary" href="<?= e(url('hr/arpa-appointments/divisions/'.$record['id'])) ?>">Cancel</a><button class="btn btn-warning" type="submit">Save Correction</button></div>
</div></form></div></div>
