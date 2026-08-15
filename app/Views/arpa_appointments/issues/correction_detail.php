<?php
use App\Core\DataTableFormat;
use App\Services\ArpaAppointmentIssuePresentation;
require BASE_PATH.'/app/Views/arpa_appointments/tabs.php';
$before=json_decode((string)$correction['before_json'],true)?:[];
$after=json_decode((string)$correction['after_json'],true)?:[];
$presentation=ArpaAppointmentIssuePresentation::for((string)$correction['issue_type']);
?>
<div class="page-heading"><div><div class="breadcrumb-lite">ARPA Officer Appointments / Data Issues / Review History</div><h1><?= e($presentation['title']) ?></h1><p>This record shows what was reviewed or corrected. The previous information is kept for audit.</p></div><?= DataTableFormat::badge(ArpaAppointmentIssuePresentation::resolution((string)$correction['resolution_status'])) ?></div>
<div class="row g-3 mb-4">
  <div class="col-lg-6"><div class="form-section h-100"><h2 class="h5">Review</h2><dl class="row mb-0">
    <dt class="col-5">Action</dt><dd class="col-7"><?= e(ArpaAppointmentIssuePresentation::action((string)$correction['correction_action'])) ?></dd>
    <dt class="col-5">Officer</dt><dd class="col-7"><a href="<?= e(url('hr/officers/'.$correction['officer_id'])) ?>"><?= e($correction['officer_number'].' - '.$correction['officer_name']) ?></a></dd>
    <dt class="col-5">ASC</dt><dd class="col-7"><?= e($correction['asc_number'].' - '.$correction['asc_name']) ?></dd>
    <dt class="col-5">Reviewed By</dt><dd class="col-7"><?= e($correction['display_name']?:$correction['username']) ?></dd>
    <dt class="col-5">Reviewed At</dt><dd class="col-7"><?= DataTableFormat::dateTime($correction['corrected_at']) ?></dd>
  </dl></div></div>
  <div class="col-lg-6"><div class="form-section h-100"><h2 class="h5">Reason and Notes</h2><dl class="row mb-0">
    <dt class="col-5">Reason for Correction</dt><dd class="col-7"><?= e($correction['correction_reason']) ?></dd>
    <dt class="col-5">Additional Notes</dt><dd class="col-7"><?= nl2br(e($correction['remarks']?:'Not provided')) ?></dd>
    <dt class="col-5">Supporting Record</dt><dd class="col-7"><?= e($correction['evidence_reference']?:'Not provided') ?></dd>
  </dl></div></div>
</div>
<?php if($correction['technical_details_allowed']): ?><details class="card mb-4"><summary class="card-header fw-semibold">Technical details</summary><div class="card-body">
  <dl class="row"><dt class="col-sm-3">Internal issue code</dt><dd class="col-sm-9"><code><?= e($correction['issue_type']) ?></code></dd><dt class="col-sm-3">Record origin</dt><dd class="col-sm-9"><code><?= e($correction['record_origin']) ?></code></dd><dt class="col-sm-3">Correction record</dt><dd class="col-sm-9"><code><?= e($correction['id']) ?></code></dd></dl>
  <div class="row g-3"><div class="col-lg-6"><h3 class="h6">Before</h3><pre class="small bg-light border p-3 overflow-auto" style="max-height:30rem"><?= e(json_encode($before,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?></pre></div><div class="col-lg-6"><h3 class="h6">After</h3><pre class="small bg-light border p-3 overflow-auto" style="max-height:30rem"><?= e(json_encode($after,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?></pre></div></div>
</div></details><?php endif; ?>
<a class="btn btn-outline-secondary" href="<?= e(url('hr/arpa-appointments/issues?category=RESOLVED_REVIEWED')) ?>">Back to Reviewed / Corrected</a>
