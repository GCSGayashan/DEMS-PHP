<?php use App\Core\{Csrf,DataTableFormat}; $dash='—'; ?>
<div class="page-heading">
    <div>
        <div class="breadcrumb-lite">Human Resource Management / Officers / Office Assignments / Review</div>
        <h1>Review Office Assignment</h1>
        <p>Review the submitted assignment within the target Office scope of your current Active Working Context.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('hr/officers/office-assignments/pending')) ?>">Back to Pending Assignments</a>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-body">
            <h2 class="h5 mb-3">Officer</h2>
            <dl class="row mb-0">
                <dt class="col-sm-5">DAD Number</dt><dd class="col-sm-7"><?= e($assignment['officer_dad']) ?></dd>
                <dt class="col-sm-5">Name</dt><dd class="col-sm-7"><?= e($assignment['officer_name']?:$dash) ?></dd>
                <dt class="col-sm-5">NIC</dt><dd class="col-sm-7"><?= e($assignment['nic']?:$dash) ?></dd>
                <dt class="col-sm-5">Designation</dt><dd class="col-sm-7"><?= e($assignment['designation_name']?:$dash) ?></dd>
            </dl>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-body">
            <h2 class="h5 mb-3">Proposed Office Assignment</h2>
            <dl class="row mb-0">
                <dt class="col-sm-5">Office</dt><dd class="col-sm-7"><?= e($assignment['office_dad'].' - '.$assignment['office_name']) ?></dd>
                <dt class="col-sm-5">Office Type</dt><dd class="col-sm-7"><?= e($assignment['office_type']) ?></dd>
                <dt class="col-sm-5">Linked Location</dt><dd class="col-sm-7"><?= e(trim(($assignment['location_dad']??'').' - '.($assignment['location_name']??''),' -')?:'National') ?></dd>
                <dt class="col-sm-5">Effective From</dt><dd class="col-sm-7"><?= e($assignment['effective_from']) ?></dd>
                <dt class="col-sm-5">Submitted By</dt><dd class="col-sm-7"><?= e($assignment['submitted_by_name']?:$assignment['submitted_by_username']?:$dash) ?></dd>
                <dt class="col-sm-5">Submitted At</dt><dd class="col-sm-7"><?= e($assignment['submitted_at']?:$dash) ?></dd>
                <dt class="col-sm-5">Status</dt><dd class="col-sm-7"><?= DataTableFormat::badge($assignment['approval_status']) ?></dd>
            </dl>
        </div></div>
    </div>
</div>

<div class="card mt-4"><div class="card-body">
    <h2 class="h5">Current Offices in Your Working Context</h2>
    <?php if($assignment['current_offices']===[]): ?><p class="text-muted mb-0">No current approved Office assignment is visible in this working context.</p><?php else: ?>
        <div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead><tr><th>Office</th><th>Type</th><th>Location</th><th>Effective From</th><th>Primary</th></tr></thead><tbody>
        <?php foreach($assignment['current_offices'] as $office): ?><tr><td><?= e($office['office_dad'].' - '.$office['office_name']) ?></td><td><?= e($office['office_type']) ?></td><td><?= e($office['location_name']?:$dash) ?></td><td><?= e($office['effective_from']) ?></td><td><?= (int)$office['is_primary']===1?'Yes':'No' ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div></div>

<div class="card mt-4"><div class="card-body d-flex justify-content-between align-items-center">
    <div><h2 class="h5 mb-1">Approval</h2><p class="text-muted mb-0">Approval updates this same assignment record. It does not create another Office assignment.</p></div>
    <form method="post" action="<?= e(url('hr/officers/office-assignments/'.$assignment['id'].'/approve')) ?>"><?= Csrf::field() ?><button class="btn btn-success" type="submit">Approve Office Assignment</button></form>
</div></div>
