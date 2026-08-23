<?php use App\Core\{Auth, Csrf}; ?>
<div class="page-heading">
    <div><div class="breadcrumb-lite">User Management / Active Users</div><h1>Active Users</h1><p>Search and manage users who can currently sign in to DEMS.</p></div>
    <?php if (Auth::can('user.request')): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestUser"><i class="bi bi-plus-lg"></i> Request User Account</button><?php endif; ?>
</div>
<div class="mb-3"><a class="btn btn-outline-secondary" href="<?= e(url('access-management/users/historical')) ?>">Inactive Users</a></div>
<?php require BASE_PATH . '/app/Views/components/datatable.php'; ?>
<?php if (Auth::can('user.request')): ?>
<div class="modal fade" id="requestUser" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="<?= e(url('access-management/users/request')) ?>">
    <?= Csrf::field() ?>
    <div class="modal-header"><h5 class="modal-title">Request New User</h5><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label">Approved Officer *</label><select class="form-select" name="officer_id" required><option value="">Select</option><?php foreach ($officers as $officer): ?><option value="<?= e($officer['id']) ?>"><?= e($officer['dad_number'] . ' - ' . $officer['name_with_initials']) ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label">Username *</label><input class="form-control" name="username" required minlength="5" maxlength="50"></div>
        <div class="mb-3"><label class="form-label">Temporary Password *</label><input type="password" class="form-control" name="temporary_password" minlength="8" required><div class="form-text">The user must change this password after signing in.</div></div>
        <div class="mb-3"><label class="form-label">Security Method</label><select class="form-select" name="mfa_method"><option value="AUTHENTICATOR_APP">Authenticator App</option><option value="SMS_OTP">SMS Code</option></select></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Submit Request</button></div>
</form></div></div>
<?php endif; ?>
