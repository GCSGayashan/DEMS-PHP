<?php use App\Core\Csrf; ?>
<div class="page-heading">
    <div>
        <div class="breadcrumb-lite">User Management / Active Users / Edit Effective Date</div>
        <h1>Edit Effective Date</h1>
        <p>Change the start date for this user role only.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('access-management/users')) ?>">Cancel</a>
</div>
<div class="row justify-content-center">
    <div class="col-xl-7 col-lg-8">
        <form class="card" method="post" action="<?= e(url('access-management/role-assignments/'.$assignment['id'].'/effective-from')) ?>">
            <?= Csrf::field() ?>
            <div class="card-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-6"><div class="text-muted small">Username</div><div class="fw-semibold"><?= e($assignment['username']) ?></div></div>
                    <div class="col-md-6"><div class="text-muted small">Display Name</div><div class="fw-semibold"><?= e($assignment['display_name']?:'Not recorded') ?></div></div>
                    <div class="col-md-6"><div class="text-muted small">Role</div><div class="fw-semibold"><?= e($assignment['role_name']) ?></div></div>
                    <div class="col-md-6"><div class="text-muted small">Assigned Location</div><div class="fw-semibold"><?= e($assignment['assigned_location']?:'National Level') ?></div></div>
                    <div class="col-md-6"><div class="text-muted small">Current Effective From</div><div class="fw-semibold"><?= e(date('d M Y',strtotime((string)$assignment['effective_from']))) ?></div></div>
                    <div class="col-md-6"><div class="text-muted small">End Date</div><div class="fw-semibold"><?= e($assignment['effective_to']?date('d M Y',strtotime((string)$assignment['effective_to'])):'Current') ?></div></div>
                </div>
                <label class="form-label" for="effective-from">New Effective From *</label>
                <input class="form-control" id="effective-from" type="date" name="effective_from" value="<?= e($assignment['effective_from']) ?>" min="<?= e($baseline) ?>"<?= $assignment['effective_to']?' max="'.e($assignment['effective_to']).'"':'' ?> required>
                <div class="form-text">The date cannot be before 01 January 2025 or after the role end date.</div>
            </div>
            <div class="card-footer d-flex justify-content-end gap-2">
                <a class="btn btn-outline-secondary" href="<?= e(url('access-management/users')) ?>">Cancel</a>
                <button class="btn btn-primary" type="submit"><i class="bi bi-calendar-check"></i> Update Date</button>
            </div>
        </form>
    </div>
</div>
