<?php use App\Core\Csrf; ?>
<div class="page-heading">
    <div><div class="breadcrumb-lite">User Management / Roles</div><h1>Roles</h1><p>Protected standard roles and approved custom roles. Legacy roles are hidden by default.</p></div>
    <div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="<?= e(url('access-management/roles' . ($showLegacy ? '' : '?show_legacy=1'))) ?>"><?= $showLegacy ? 'Hide Legacy' : 'Show Legacy Roles' ?></a><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRole"><i class="bi bi-plus-lg"></i> Add Role</button></div>
</div>
<?php require BASE_PATH . '/app/Views/components/datatable.php'; ?>
<div class="modal fade" id="addRole" tabindex="-1"><div class="modal-dialog modal-xl"><form class="modal-content" method="post" action="<?= e(url('access-management/roles')) ?>">
    <?= Csrf::field() ?>
    <div class="modal-header"><h5 class="modal-title">Create Custom Role</h5><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="row g-3">
        <div class="col-md-5"><label class="form-label">Role Name *</label><input class="form-control" name="role_name" required></div>
        <div class="col-md-4"><label class="form-label">System Key</label><input class="form-control" name="role_code" placeholder="AUTO_FROM_NAME"></div>
        <div class="col-md-3"><label class="form-label">Role Level</label><input class="form-control" value="CUSTOM" disabled></div>
        <div class="col-12"><label class="form-label">Description</label><input class="form-control" name="description"></div>
        <div class="col-12"><label class="form-label">Permissions</label><div class="row g-2 border rounded p-2" style="max-height:320px;overflow:auto"><?php foreach ($permissions as $permission): ?><div class="col-md-4"><label class="form-check"><input class="form-check-input" type="checkbox" name="permissions[]" value="<?= e($permission['id']) ?>"> <span class="form-check-label"><code><?= e($permission['permission_key']) ?></code><br><small class="text-muted"><?= e($permission['description']) ?></small></span></label></div><?php endforeach; ?></div></div>
    </div></div>
    <div class="modal-footer"><button class="btn btn-primary">Submit</button></div>
</form></div></div>
