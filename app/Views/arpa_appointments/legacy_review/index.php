<?php
use App\Core\{Auth,Csrf};
$s=$dashboard['special'];$m=$dashboard['missing'];$c=$dashboard['conflicts'];
?>
<div class="page-heading">
  <div><div class="breadcrumb-lite">ARPA Officer Appointments / Legacy Migration Review</div><h1>Legacy ARPA Appointment Reconciliation</h1><p>Human decisions recorded here affect migration readiness only. Legacy source rows and current operational appointments are never changed.</p></div>
  <span class="badge fs-6 bg-<?= $dashboard['ready']?'success':'danger' ?>"><?= $dashboard['ready']?'READY':'NOT READY' ?></span>
</div>
<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="stat-card h-100"><div class="stat-label">Current Special-Function Records</div><div class="stat-value"><?= (int)($s['total']??0) ?></div><div class="small text-muted">Confirmed ASC <?= (int)($s['confirmed']??0) ?> &middot; manual confirmation <?= (int)($s['needs_manual_confirmation']??0) ?> &middot; no candidate <?= (int)($s['no_candidate']??0) ?></div></div></div>
  <div class="col-md-4"><div class="stat-card h-100"><div class="stat-label">Missing ARPA Location</div><div class="stat-value"><?= (int)($m['unresolved']??0) ?></div><div class="small text-muted">Resolved <?= (int)($m['resolved']??0) ?> of <?= (int)($m['total']??0) ?></div></div></div>
  <div class="col-md-4"><div class="stat-card h-100"><div class="stat-label">Current Conflicts</div><div class="stat-value"><?= (int)($c['further_review']??0) ?></div><div class="small text-muted">Activate <?= (int)($c['activate_current']??0) ?> &middot; history only <?= (int)($c['preserve_history_only']??0) ?> &middot; total <?= (int)($c['total']??0) ?></div></div></div>
</div>
<div class="row g-3 mb-4">
  <div class="col-md-3"><div class="stat-card h-100"><div class="stat-label">Reviewed Strong-Derived Eligible</div><div class="stat-value"><?= (int)($s['bulk_eligible']??0) ?></div></div></div>
  <div class="col-md-3"><div class="stat-card h-100"><div class="stat-label">Preserve History Only</div><div class="stat-value"><?= (int)($s['preserve_history_only']??0) ?></div></div></div>
  <div class="col-md-3"><div class="stat-card h-100"><div class="stat-label">Officer / Function / ASC Groups</div><div class="stat-value"><?= (int)($s['groups']??0) ?></div></div></div>
  <div class="col-md-3"><div class="stat-card h-100"><div class="stat-label">Remaining Decision Blockers</div><div class="stat-value"><?= (int)$dashboard['remainingBlockers'] ?></div></div></div>
</div>
<div class="form-section mb-4"><h2 class="h5">Current records by function and evidence class</h2><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Function</th><th>Total</th><th>Strong Derived</th><th>Current State Only</th><th>Unresolved</th><th>Confirmed ASC</th><th>Preserve History</th><th>Remaining</th></tr></thead><tbody>
<?php foreach($dashboard['byFunction'] as $function=>$row): ?><tr><td><?= e(str_replace('_',' ',$function)) ?></td><td><?= (int)$row['total'] ?></td><td><?= (int)($row['evidence']['STRONG_DERIVED']??0) ?></td><td><?= (int)($row['evidence']['CURRENT_STATE_ONLY']??0) ?></td><td><?= (int)($row['evidence']['UNRESOLVED']??0) ?></td><td><?= (int)$row['confirmed'] ?></td><td><?= (int)$row['preserve_history_only'] ?></td><td><?= (int)$row['unresolved'] ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php if(Auth::can('arpa.legacy-reconciliation.decide')): ?>
<div class="form-section mb-4"><div class="row g-3 align-items-end">
  <div class="col-lg-7"><form method="post" action="<?= e(url('hr/arpa-appointments/legacy-review/bulk-confirm-strong')) ?>"><?= Csrf::field() ?><label class="form-label" for="bulk-reason">Bulk Confirm Strong-Derived ASC</label><input id="bulk-reason" class="form-control mb-2" name="decision_reason" required maxlength="2000" value="Bulk confirmation of reviewed STRONG_DERIVED legacy evidence"><div class="form-check mb-2"><input id="confirm-bulk" class="form-check-input" type="checkbox" name="confirm_bulk" value="1" required><label class="form-check-label" for="confirm-bulk">I reviewed the eligibility rules and explicitly approve creating <?= (int)($s['bulk_eligible']??0) ?> record-level decisions.</label></div><button class="btn btn-outline-primary">Bulk Confirm Strong-Derived ASC</button><div class="form-text">Only current, single-candidate, exact Officer/ASC matches with an active approved ASC Office are included. CURRENT_STATE_ONLY is excluded. Evidence remains unchanged.</div></form></div>
  <div class="col-lg-5 text-lg-end"><form method="post" action="<?= e(url('hr/arpa-appointments/legacy-review/refresh')) ?>"><?= Csrf::field() ?><button class="btn btn-outline-secondary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Refresh Diagnostic Queues</button></form><div class="form-text">Last synchronized <?= e((string)($dashboard['sync']['synced_at']??'Never')) ?></div></div>
</div></div>
<?php endif; ?>
<ul class="nav nav-tabs" role="tablist">
  <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#special-review" type="button" role="tab">Current Special ASC Review</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#group-review" type="button" role="tab">Officer / Function / ASC Groups</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#missing-review" type="button" role="tab">Missing ARPA Location</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#conflict-review" type="button" role="tab">Current Conflicts</button></li>
</ul>
<div class="tab-content pt-3">
  <div id="special-review" class="tab-pane fade show active" role="tabpanel"><div class="alert alert-secondary">This queue opens on current records. Use Evidence Class to isolate CURRENT_STATE_ONLY or STRONG_DERIVED, and use Resolution Status to find remaining decisions. A candidate is evidence, not approval.</div><?php $dataTable=$specialTable;require BASE_PATH.'/app/Views/components/datatable.php'; ?></div>
  <div id="group-review" class="tab-pane fade" role="tabpanel"><div class="alert alert-secondary">Groups are Officer + function + candidate ASC. Each selected record still receives its own decision and audit entry.</div><?php $dataTable=$groupTable;require BASE_PATH.'/app/Views/components/datatable.php'; ?></div>
  <div id="missing-review" class="tab-pane fade" role="tabpanel"><?php $dataTable=$missingTable;require BASE_PATH.'/app/Views/components/datatable.php'; ?></div>
  <div id="conflict-review" class="tab-pane fade" role="tabpanel"><?php $dataTable=$conflictTable;require BASE_PATH.'/app/Views/components/datatable.php'; ?></div>
</div>
