<?php use App\Core\Csrf; $r=$preview['request']; ?>
<div class="page-heading"><div><h1>Exclude Unresolved Legacy Request</h1><p>Administrative exclusion from operational correction workflows. This does not declare a KEEP decision, approve an appointment, or delete imported evidence.</p></div></div>
<?php if(!empty($error)): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card mb-3"><div class="card-body"><dl class="row">
<dt class="col-sm-4">Request ID</dt><dd class="col-sm-8"><?= e($r['id']) ?></dd>
<dt class="col-sm-4">Officer</dt><dd class="col-sm-8"><?= e(($preview['officer']['dad_number']??'').' - '.($preview['officer']['name_with_initials']??'')) ?></dd>
<dt class="col-sm-4">ASC</dt><dd class="col-sm-8"><?= e(($preview['locations']['asc_number']??'').' - '.($preview['locations']['asc_name']??'')) ?></dd>
<dt class="col-sm-4">ARPA Division</dt><dd class="col-sm-8"><?= e(($preview['locations']['division_number']??'').' - '.($preview['locations']['division_name']??'')) ?></dd>
<dt class="col-sm-4">Source period</dt><dd class="col-sm-8"><?= e(($r['requested_effective_from']??'Unknown').' to '.($r['requested_effective_to']??'Open')) ?></dd>
<dt class="col-sm-4">Source status / type</dt><dd class="col-sm-8"><?= e($r['workflow_status'].' / '.$r['appointment_type']) ?></dd>
</dl><h2 class="h5">Dependency preview</h2><ul><?php foreach($preview['dependencies'] as $label=>$values): ?><li><?= e(str_replace('_',' ',$label)) ?>: <?= e((string)(is_array($values)?count($values):$values)) ?></li><?php endforeach; ?></ul>
<p>Preserved source references: <?= count($preview['source_references']) ?>. Preserved location-repair history: <?= count($preview['location_repair_history']) ?>.</p>
<details><summary>Original imported evidence (read-only)</summary><pre class="text-wrap"><?= e(json_encode(['request'=>$r,'source_references'=>$preview['source_references'],'location_repair_history'=>$preview['location_repair_history']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre></details>
<?php foreach($preview['blockers'] as $blocker): ?><div class="alert alert-warning"><?= e($blocker) ?></div><?php endforeach; ?>
</div></div>
<?php if($preview['eligible']): ?><form method="post" action="<?= e(url('hr/arpa-appointments/legacy-requests/'.$r['id'].'/exclude')) ?>" class="card"><div class="card-body">
<?= Csrf::field() ?><input type="hidden" name="preview_hash" value="<?= e($preview['preview_hash']) ?>">
<div class="mb-3"><label class="form-label" for="delete_reason">Removal reason *</label><textarea class="form-control" id="delete_reason" name="delete_reason" maxlength="500" required><?= e($input['delete_reason']??'') ?></textarea></div>
<div class="mb-3"><label class="form-label" for="evidence_reference">Supporting evidence reference *</label><input class="form-control" id="evidence_reference" name="evidence_reference" maxlength="500" value="<?= e($input['evidence_reference']??'') ?>" required></div>
<div class="mb-3"><label class="form-label" for="confirmation_id">Enter the complete request ID shown above *</label><input class="form-control" id="confirmation_id" name="confirmation_id" value="<?= e($input['confirmation_id']??'') ?>" required></div>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="confirm_delete" name="confirm_delete" value="1" required><label class="form-check-label" for="confirm_delete">Evidence confirms that this exact legacy request is unnecessary. Exclude it without deleting its source evidence.</label></div>
<button class="btn btn-danger" type="submit">Exclude Legacy Request</button>
</div></form><?php endif; ?>
<a class="btn btn-outline-secondary mt-3" href="<?= e(url('hr/arpa-appointments/legacy-requests/exclusions')) ?>">Exclusion Audit</a>
<a class="btn btn-outline-secondary mt-3" href="<?= e(url('hr/officers/'.$r['officer_id'])) ?>">Officer Profile</a>
