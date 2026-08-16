<?php use App\Core\Csrf; ?>
<div class="page-heading"><div><div class="breadcrumb-lite">User Management / Active Users</div><h1>Reset Password</h1><p>Set a temporary credential for the selected operational account.</p></div></div>
<div class="row g-4">
    <div class="col-12 col-xl-5"><div class="card border-0 shadow-sm"><div class="card-body">
        <h2 class="h6">Account</h2>
        <dl class="row mb-0"><dt class="col-5">Username</dt><dd class="col-7"><?= e($user['username']) ?></dd><dt class="col-5">Display name</dt><dd class="col-7"><?= e($user['display_name']?:'Not set') ?></dd><dt class="col-5">Status</dt><dd class="col-7"><?= e($user['account_status']) ?></dd><dt class="col-5">Roles</dt><dd class="col-7"><?= e($user['effective_roles']?:'None') ?></dd><dt class="col-5">Scopes</dt><dd class="col-7"><?= e($user['effective_scopes']?:'None') ?></dd></dl>
    </div></div></div>
    <div class="col-12 col-xl-7"><div class="card border-0 shadow-sm"><form method="post" action="<?= e(url('access-management/users/'.$user['id'].'/reset-password')) ?>" class="card-body">
        <?= Csrf::field() ?>
        <div class="alert alert-warning">The temporary password is never displayed again. The user must replace it after the next successful login.</div>
        <div class="mb-3"><label class="form-label">Temporary Password *</label><input type="password" class="form-control" name="temporary_password" minlength="8" autocomplete="new-password" required><div class="form-text">At least 8 characters with uppercase, lowercase, number, and symbol.</div></div>
        <div class="mb-3"><label class="form-label">Confirm Temporary Password *</label><input type="password" class="form-control" name="temporary_password_confirmation" minlength="8" autocomplete="new-password" required></div>
        <div class="mb-3"><label class="form-label">Reason *</label><textarea class="form-control" name="reason" rows="3" required></textarea></div>
        <div class="mb-3"><label class="form-label">Official Reference</label><input class="form-control" name="official_reference" maxlength="255"></div>
        <div class="d-flex justify-content-end gap-2"><a class="btn btn-outline-secondary" href="<?= e(url('access-management/users')) ?>">Cancel</a><button class="btn btn-danger" type="submit"><i class="bi bi-key-fill"></i> Reset Password</button></div>
    </form></div></div>
</div>
