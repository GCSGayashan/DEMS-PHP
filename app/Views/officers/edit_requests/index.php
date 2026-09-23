<?php use App\Core\{Auth,DataTableFormat}; ?>
<div class="page-heading">
  <div><div class="breadcrumb-lite">Human Resource Management / Officers</div><h1>Officer Edit Requests</h1><p>Profile changes awaiting maker-checker approval in the current working context.</p></div>
  <a class="btn btn-outline-secondary" href="<?= e(url('hr/officers')) ?>">Back to Officers</a>
</div>
<div class="card"><div class="card-body"><div class="table-responsive">
<table class="table table-striped align-middle mb-0">
<thead><tr><th>DAD Officer Number</th><th>Officer</th><th>NIC</th><th>Current Office</th><th>Requested By</th><th>Level</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead>
<tbody>
<?php if($requests===[]): ?><tr><td colspan="9" class="text-center text-muted">No Officer edit requests are available in the current working context.</td></tr><?php endif; ?>
<?php foreach($requests as $r): ?><tr>
<td><?= e($r['dad_number']) ?></td><td><?= e($r['name_with_initials']) ?></td><td><?= e($r['nic']) ?></td>
<td><?= !empty($r['office_name'])?e(trim(($r['office_dad']??'').' - '.$r['office_name'],' -')):'—' ?></td>
<td><?= e($r['submitted_by_name']?:$r['submitted_by_username']) ?></td><td><?= e(ucfirst(strtolower($r['request_level']))) ?></td>
<td><?= e($r['submitted_at']) ?></td><td><?= DataTableFormat::badge($r['workflow_status']) ?></td>
<td><?php if(Auth::can('officer.edit-approve')): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('hr/officer-edit-requests/'.$r['id'])) ?>">Review</a><?php elseif($r['workflow_status']==='RETURNED'): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('hr/officers/'.$r['officer_id'].'/edit')) ?>">Correct</a><?php else: ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('hr/officers/'.$r['officer_id'])) ?>">View Officer</a><?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div></div></div>
