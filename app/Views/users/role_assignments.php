<?php use App\Core\Csrf; ?>
<div class="page-heading"><div><div class="breadcrumb-lite">User Management / Role Assignments</div><h1>Role Assignments</h1><p>Effective-dated role assignments with maker-checker approval.</p></div><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignRole"><i class="bi bi-plus-lg"></i> Assign Role</button></div>
<?php require BASE_PATH . '/app/Views/components/datatable.php'; ?>
<div class="modal fade" id="assignRole" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="<?= e(url('access-management/role-assignments')) ?>">
    <?= Csrf::field() ?>
    <div class="modal-header"><h5 class="modal-title">Assign Role</h5><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label">User</label><select class="form-select" name="user_id" required><option value="">Select</option><?php foreach ($users as $user): ?><option value="<?= e($user['id']) ?>"><?= e($user['username']) ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label">Role</label><select class="form-select" name="role_id" required><option value="">Select</option><?php foreach ($roles as $role): ?><option value="<?= e($role['id']) ?>"><?= e($role['role_level'] . ' - ' . $role['role_name']) ?></option><?php endforeach; ?></select></div>
        <div class="row g-2"><div class="col"><label class="form-label">Effective From</label><input type="date" name="effective_from" class="form-control" value="<?= date('Y-m-d') ?>"></div><div class="col"><label class="form-label">Effective To</label><input type="date" name="effective_to" class="form-control"></div></div>
        <div class="alert alert-info mt-3 mb-0">The assignment is created as DRAFT. A different authorized administrator must approve it.</div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Create Draft</button></div>
</form></div></div>
