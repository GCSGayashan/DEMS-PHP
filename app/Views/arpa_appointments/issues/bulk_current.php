<?php
use App\Core\Csrf;
require BASE_PATH.'/app/Views/arpa_appointments/tabs.php';
$summary=$preview['summary'];
$normalization=$preview['normalization'];
$labels=[
    'ELIGIBLE'=>'Eligible',
    'SKIPPED_CONFLICTING_CURRENT_APPOINTMENT'=>'Skipped - conflicting current appointment',
    'SKIPPED_ACTIVE_WORKFLOW_RESERVATION'=>'Skipped - active workflow reservation',
    'SKIPPED_GENUINE_HISTORICAL_EXCEPTION'=>'Skipped - genuine historical exception',
    'SKIPPED_INVALID_COMBINATION'=>'Skipped - invalid combination',
    'SKIPPED_OTHER_DATA_ISSUE'=>'Skipped - other data issue',
];
?>
<div class="page-heading"><div><div class="breadcrumb-lite">ARPA Officer Assignments / Appointment Data Issues</div><h1>Bulk Legacy Current Reconciliation</h1><p>Preview and safely promote only imported open appointments that pass canonical conflict validation.</p></div><a class="btn btn-outline-secondary" href="<?= e(url('hr/arpa-appointments/issues')) ?>">Back to Data Issues</a></div>
<div class="alert alert-warning"><strong>Controlled administrative operation.</strong> Preview makes no changes. Execution is restricted to the canonical <code>dems.admin</code> account, revalidates every appointment, and processes at most <?= e((string)$preview['batch_size']) ?> appointments per batch.</div>

<?php if(isset($result)): ?>
<div class="card mb-4"><div class="card-body">
  <h2 class="h5">Execution Result</h2>
  <p class="mb-2"><strong>Batch ID:</strong> <code><?= e((string)$result['batch_id']) ?></code></p>
  <div class="row g-3">
    <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted">Promoted</div><div class="h3 mb-0"><?= e((string)$result['promoted']) ?></div></div></div>
    <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted">Skipped</div><div class="h3 mb-0"><?= e((string)$result['skipped']) ?></div></div></div>
    <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted">Failed</div><div class="h3 mb-0"><?= e((string)$result['failed']) ?></div></div></div>
    <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted">Remaining before recalculation</div><div class="h3 mb-0"><?= e((string)$result['remaining_before_recalculation']) ?></div></div></div>
  </div>
  <?php if($result['skipped']||$result['failed']): ?><details class="mt-3"><summary>View skipped/failed rows</summary><div class="table-responsive mt-2"><table class="table table-sm"><thead><tr><th>Appointment</th><th>Result</th><th>Reason</th></tr></thead><tbody><?php foreach($result['results'] as $row): if($row['result']==='PROMOTED')continue; ?><tr><td><code><?= e((string)$row['appointment_id']) ?></code></td><td><?= e((string)$row['result']) ?></td><td><?= e((string)$row['reason']) ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
</div></div>
<?php endif; ?>

<?php if(isset($normalizationResult)): ?>
<div class="card border-success mb-4"><div class="card-body">
  <h2 class="h5">Stale Legacy Exception Normalization Result</h2>
  <p><strong>Batch ID:</strong> <code><?= e((string)$normalizationResult['batch_id']) ?></code></p>
  <div class="d-flex flex-wrap gap-4"><div><span class="text-muted">Normalized</span><div class="h3"><?= e((string)$normalizationResult['normalized']) ?></div></div><div><span class="text-muted">Skipped</span><div class="h3"><?= e((string)$normalizationResult['skipped']) ?></div></div><div><span class="text-muted">Failed</span><div class="h3"><?= e((string)$normalizationResult['failed']) ?></div></div></div>
</div></div>
<?php endif; ?>

<div class="row g-3 mb-4">
<?php foreach($labels as $key=>$label): ?><div class="col-md-4 col-xl-2"><div class="card h-100"><div class="card-body"><div class="small text-muted"><?= e($label) ?></div><div class="h3 mb-0"><?= e((string)($summary[$key]??0)) ?></div></div></div></div><?php endforeach; ?>
</div>

<div class="card mb-4"><div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
  <div><h2 class="h5 mb-1">Preview Eligible Records</h2><p class="text-muted mb-0"><?= e((string)$preview['total']) ?> imported open candidates were assessed without changing data.</p></div>
  <form method="post" action="<?= e(url('hr/arpa-appointments/issues/bulk-current')) ?>" onsubmit="return confirm('Execute the next eligible bulk reconciliation batch? Every appointment will be revalidated before promotion.');">
    <?= Csrf::field() ?>
    <button class="btn btn-danger" type="submit" <?= ($summary['ELIGIBLE']??0)<1?'disabled':'' ?>>Execute Eligible Promotions (next <?= e((string)$preview['batch_size']) ?>)</button>
  </form>
</div></div>

<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th>Appointment ID</th><th>Officer</th><th>NIC</th><th>Type</th><th>ARPA Division</th><th>ASC</th><th>District</th><th>Effective From</th><th>Legacy Exception</th><th>Eligibility</th><th>Blocker / Skip Reason</th></tr></thead>
<tbody>
<?php foreach($preview['rows'] as $row): ?>
<tr>
  <td><code><?= e((string)$row['id']) ?></code></td>
  <td><?= e(trim((string)$row['officer_number'].' - '.(string)$row['officer_name'],' -')) ?></td>
  <td><?= e((string)($row['nic']??'')) ?></td>
  <td><?= e(ucwords(strtolower(str_replace('_',' ',(string)$row['appointment_type'])))) ?></td>
  <td><?= e((string)$row['arpa_division_name']) ?></td><td><?= e((string)$row['asc_name']) ?></td><td><?= e((string)($row['district_name']??'')) ?></td>
  <td><?= e((string)$row['effective_from']) ?></td>
  <td><small><?= e((string)($row['legacy_exception_codes_json']??'[]')) ?></small></td>
  <td><span class="badge <?= !empty($row['eligible'])?'bg-success':'bg-secondary' ?>"><?= e(!empty($row['eligible'])?'Eligible':str_replace('_',' ',(string)$row['classification'])) ?></span><?php if((int)($row['exact_duplicate_count']??0)>0): ?><div class="small text-warning mt-1"><?= e((string)$row['exact_duplicate_count']) ?> exact reservation(s) will be retained as superseded history</div><?php endif; ?></td>
  <td><?= e((string)($row['blocker_reason']??'—')) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php if($preview['pages']>1): ?><nav class="mt-3" aria-label="Bulk preview pages"><ul class="pagination flex-wrap"><?php for($p=1;$p<=$preview['pages'];$p++): ?><li class="page-item <?= $p===$preview['page']?'active':'' ?>"><a class="page-link" href="<?= e(url('hr/arpa-appointments/issues/bulk-current?page='.$p.'&per_page='.$preview['per_page'])) ?>"><?= e((string)$p) ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>

<div class="card border-info mt-4"><div class="card-body">
  <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-3">
    <div><h2 class="h5 mb-1">Normalize Stale Resolved Exception Flags</h2><p class="text-muted mb-0">Preview found <?= e((string)$normalization['count']) ?> canonically resolved imported appointment(s) whose current exception flag is stale. Historical exception codes remain unchanged.</p></div>
    <form method="post" action="<?= e(url('hr/arpa-appointments/issues/bulk-current/normalize-stale-flags')) ?>" onsubmit="return confirm('Normalize the stale legacy exception flags shown in this preview? Only the current legacy_exception flag will change.');">
      <?= Csrf::field() ?><button class="btn btn-info" type="submit" <?= $normalization['count']<1?'disabled':'' ?>>Execute Flag Normalization</button>
    </form>
  </div>
  <?php if($normalization['count']>0): ?><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Appointment</th><th>Officer</th><th>NIC</th><th>Type</th><th>ARPA Division</th><th>ASC</th><th>Effective From</th><th>Existing Correction</th><th>Preserved Exception Codes</th></tr></thead><tbody>
  <?php foreach(array_slice($normalization['rows'],0,100) as $row): ?><tr><td><code><?= e((string)$row['id']) ?></code></td><td><?= e(trim((string)$row['officer_number'].' - '.(string)$row['officer_name'],' -')) ?></td><td><?= e((string)($row['nic']??'')) ?></td><td><?= e(ucwords(strtolower(str_replace('_',' ',(string)$row['appointment_type'])))) ?></td><td><?= e((string)$row['arpa_division_name']) ?></td><td><?= e((string)$row['asc_name']) ?></td><td><?= e((string)$row['effective_from']) ?></td><td><code><?= e((string)$row['canonical_correction_id']) ?></code></td><td><small><?= e((string)$row['legacy_exception_codes_json']) ?></small></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div></div>
