<?php
use App\Core\{Auth,Csrf,DataTableFormat};
$canDecide=Auth::can('arpa.legacy-reconciliation.decide');
$pending=array_values(array_filter($group['records'],fn($row)=>empty($row['resolution_status'])));
?>
<div class="page-heading">
  <div><div class="breadcrumb-lite">ARPA Officer Appointments / Legacy Migration Review / Group</div><h1><?= e($group['officer_number'].' - '.$group['officer_name']) ?></h1><p><?= DataTableFormat::badge($group['function']) ?> &middot; <?= (int)$group['record_count'] ?> reconciled business record(s)</p></div>
  <a class="btn btn-outline-secondary" href="<?= e(url('hr/arpa-appointments/legacy-review')) ?>">Back to Review</a>
</div>
<div class="row g-3 mb-4">
  <div class="col-lg-6"><div class="form-section h-100"><h2 class="h5">Confirmed candidate context</h2><dl class="row mb-0">
    <dt class="col-sm-4">Officer DAD</dt><dd class="col-sm-8"><?= e($group['officer_number']) ?></dd>
    <dt class="col-sm-4">NIC</dt><dd class="col-sm-8"><?= e($group['nic']?:'Unavailable') ?></dd>
    <dt class="col-sm-4">Candidate ASC</dt><dd class="col-sm-8"><?= e(trim((string)$group['candidate_asc_number'].' '.(string)$group['candidate_asc_name'])?:'No candidate') ?></dd>
    <dt class="col-sm-4">Candidate Office</dt><dd class="col-sm-8"><?= e(trim((string)$group['candidate_office_number'].' '.(string)$group['candidate_office_name'])?:'Missing') ?></dd>
  </dl></div></div>
  <div class="col-lg-6"><div class="form-section h-100"><h2 class="h5">Current approved Office assignments</h2>
    <?php if($group['current_office_assignments']===[]): ?><p class="text-muted mb-0">No current approved Office assignment.</p><?php else: ?><ul class="mb-0"><?php foreach($group['current_office_assignments'] as $assignment): ?><li><?= e($assignment['office_number'].' - '.$assignment['office_name']) ?> <?= $assignment['is_primary']?'<span class="badge bg-primary">PRIMARY</span>':'' ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div></div>
</div>
<form method="post" action="<?= e(url('hr/arpa-appointments/legacy-review/groups/confirm')) ?>" class="form-section"><?= Csrf::field() ?>
  <h2 class="h5">Record-level decisions</h2>
  <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Select</th><th>Source references</th><th>Period</th><th>Evidence class</th><th>Why derived</th><th>Decision</th></tr></thead><tbody>
  <?php foreach($group['records'] as $row): ?><tr><td><?php if(empty($row['resolution_status'])&&$group['candidate_asc_id']!==null): ?><input class="form-check-input" type="checkbox" name="item_ids[]" value="<?= e($row['id']) ?>" checked aria-label="Select <?= e($row['primary_source_table'].':'.$row['primary_source_record_id']) ?>"><?php else: ?>&mdash;<?php endif; ?></td><td><?php foreach($row['sources'] as $source): ?><code class="me-2"><?= e($source) ?></code><?php endforeach; ?></td><td><?= e($row['effective_from'].' to '.($row['effective_to']?:'Open')) ?></td><td><?= DataTableFormat::badge($row['source_confidence']) ?></td><td><?= e($row['evidence_summary']) ?></td><td><?= DataTableFormat::badge($row['resolution_status']?:'PENDING') ?> <a class="btn btn-sm btn-link" href="<?= e(url('hr/arpa-appointments/legacy-review/items/'.$row['id'])) ?>">Review</a></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if($canDecide&&$pending!==[]&&$group['candidate_asc_id']!==null): ?><label class="form-label" for="group-reason">Decision reason *</label><textarea id="group-reason" class="form-control mb-2" name="decision_reason" rows="2" required>Apply reviewed confirmed ASC to selected current records in this Officer/function/ASC group</textarea><div class="form-check mb-3"><input id="confirm-group" class="form-check-input" type="checkbox" name="confirm_group" value="1" required><label class="form-check-label" for="confirm-group">I explicitly approve a separate decision and audit record for every selected record.</label></div><button class="btn btn-primary">Confirm Selected Records</button>
  <?php elseif($group['candidate_asc_id']===null): ?><div class="alert alert-warning mb-0">No ASC candidate exists. Open each record and select an ASC or choose Preserve History Only.</div>
  <?php else: ?><p class="text-muted mb-0">No unresolved records remain in this group.</p><?php endif; ?>
</form>
