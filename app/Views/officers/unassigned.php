<?php use App\Core\{Auth,DataTableFormat}; ?>
<div class="page-heading">
  <div><div class="breadcrumb-lite">Human Resource Management / Officers</div><h1>Unassigned Officers</h1><p>Approved Officers with no current active approved Office assignment in the current working context.</p></div>
  <div class="d-flex gap-2"><?php if(Auth::can('officer.office-assignment.approve')): ?><a class="btn btn-outline-success" href="<?= e(url('hr/officers/office-assignments/pending')) ?>">Pending Office Assignments</a><?php endif; ?><a class="btn btn-outline-secondary" href="<?= e(url('hr/officers')) ?>">Back to Officers</a></div>
</div>
<div class="alert alert-info">Office Assignment records—not <code>primary_office_id</code>—determine whether an Officer is currently assigned. Officers without reliable District evidence are visible only in National/System contexts. A <strong>Pending Assignment</strong> must be reviewed before another Office can be assigned.</div>
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-striped align-middle mb-0">
<thead><tr><th>DAD Number</th><th>Officer Name</th><th>NIC</th><th>Designation</th><th>Class</th><th>Officer Status</th><th>Office Assignment Status</th><th>Requested Office</th><th>Submitted By / Date</th><th>Actions</th></tr></thead>
<tbody>
<?php if($officers===[]): ?><tr><td colspan="10" class="text-center text-muted">No unassigned Officers are available in the current working context.</td></tr><?php endif; ?>
<?php foreach($officers as $officer): ?><tr>
<td><?= e($officer['dad_number']) ?></td><td><?= e($officer['name_with_initials']) ?></td><td><?= e($officer['nic']?:'—') ?></td><td><?= e($officer['designation_name']?:'—') ?></td><td><?= e($officer['class_name']?:'—') ?></td><td><?= DataTableFormat::badge($officer['officer_status_name']?:'APPROVED') ?></td><td><?= DataTableFormat::badge(strtoupper(str_replace(' ','_',$officer['assignment_status']))) ?></td><td><?= e($officer['requested_office']?:'—') ?></td><td><?= e($officer['submitted_by_name']?:'—') ?><?php if($officer['pending_submitted_at']): ?><br><small><?= e($officer['pending_submitted_at']) ?></small><?php endif; ?></td>
<td><div class="d-flex gap-1 flex-wrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('hr/officers/'.$officer['id'])) ?>">View</a><?php if(!empty($officer['can_assign'])): ?><a class="btn btn-sm btn-primary" href="<?= e(url('hr/officers/'.$officer['id'].'/offices/assign')) ?>"><?= !empty($officer['returned_assignment_id'])?'Correct Assignment':'Assign Office' ?></a><?php endif; ?></div></td>
</tr><?php endforeach; ?>
</tbody></table></div></div></div>
