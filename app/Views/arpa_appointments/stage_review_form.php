<?php use App\Core\Csrf; ?>
<div class="page-heading"><div><div class="breadcrumb-lite">ARPA Officer Appointments / <?= e(ucfirst(strtolower($stage))) ?> Review</div><h1><?= e(ucfirst(strtolower($stage))) ?> Review Information</h1><p>Enter only the information and remarks belonging to this review level. Original ASC request data remains read-only.</p></div></div>
<div class="row"><div class="col-xl-8"><div class="form-section">
<dl class="row"><dt class="col-sm-4">Workflow Status</dt><dd class="col-sm-8"><?= e($request['workflow_status']) ?></dd><dt class="col-sm-4">Appointment Type</dt><dd class="col-sm-8"><?= e($request['appointment_type']??$request['request_type']) ?></dd><dt class="col-sm-4">Effective From</dt><dd class="col-sm-8"><?= e($request['requested_effective_from']?:'—') ?></dd></dl>
<form method="post" action="<?= e(url('hr/arpa-appointments/requests/'.$entity.'/'.$request['id'].'/review/'.strtolower($stage))) ?>"><?= Csrf::field() ?>
<div class="mb-3"><label class="form-label" for="review-information">Review information</label><textarea class="form-control" id="review-information" name="review_information" rows="5"><?= e($review['review_information']??'') ?></textarea></div>
<div class="mb-3"><label class="form-label" for="review-remarks">Review remarks</label><textarea class="form-control" id="review-remarks" name="remarks" rows="3"><?= e($review['remarks']??'') ?></textarea></div>
<button class="btn btn-primary">Save <?= e(ucfirst(strtolower($stage))) ?> Review</button> <a class="btn btn-outline-secondary" href="<?= e(url('hr/arpa-appointments/requests/'.$entity.'/'.$request['id'])) ?>">Cancel</a>
</form></div></div></div>
