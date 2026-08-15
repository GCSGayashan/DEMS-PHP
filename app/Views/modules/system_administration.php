<?php use App\Core\Auth; ?>
<div class="page-heading">
    <div><div class="breadcrumb-lite">Administration</div><h1>System Administration</h1><p>Manage operational identities, access control, and security audit records.</p></div>
</div>
<div class="row g-3">
<?php if(Auth::can('user.view')): ?>
    <div class="col-12"><h2 class="h5 mb-0">User Management</h2></div>
    <?php foreach([
        ['Active / Operational Users','Current enabled DEMS accounts.','access-management/users','bi-people-fill'],
        ['Historical / Disabled Users','Preserved identities that are not operational.','access-management/users/historical','bi-person-lock'],
        ['Account Requests','Draft, submitted, and pending account requests.','access-management/account-requests','bi-card-checklist'],
    ] as [$title,$description,$route,$icon]): ?>
    <div class="col-12 col-md-6 col-xl-4"><a class="card h-100 border-0 shadow-sm text-decoration-none" href="<?= e(url($route)) ?>"><div class="card-body"><i class="bi <?= e($icon) ?> fs-3 text-primary"></i><h3 class="h6 mt-3 text-body"><?= e($title) ?></h3><p class="text-muted mb-0"><?= e($description) ?></p></div></a></div>
    <?php endforeach; ?>
<?php endif; ?>
<?php if(Auth::can('role.manage')||Auth::can('permission.view')): ?>
    <div class="col-12 mt-4"><h2 class="h5 mb-0">Access Control</h2></div>
    <?php if(Auth::can('role.manage')): ?><div class="col-12 col-md-6 col-xl-4"><a class="card h-100 border-0 shadow-sm text-decoration-none" href="<?= e(url('access-management/roles')) ?>"><div class="card-body"><i class="bi bi-shield-fill fs-3 text-primary"></i><h3 class="h6 mt-3 text-body">Roles</h3><p class="text-muted mb-0">Review protected and custom application roles.</p></div></a></div><?php endif; ?>
    <?php if(Auth::can('permission.view')): ?><div class="col-12 col-md-6 col-xl-4"><a class="card h-100 border-0 shadow-sm text-decoration-none" href="<?= e(url('access-management/permissions')) ?>"><div class="card-body"><i class="bi bi-shield-check fs-3 text-primary"></i><h3 class="h6 mt-3 text-body">Permissions</h3><p class="text-muted mb-0">Inspect application permission definitions.</p></div></a></div><?php endif; ?>
<?php endif; ?>
<?php if(Auth::can('user.view-security-history')): ?>
    <div class="col-12 mt-4"><h2 class="h5 mb-0">System Administration</h2></div>
    <div class="col-12 col-md-6 col-xl-4"><a class="card h-100 border-0 shadow-sm text-decoration-none" href="<?= e(url('access-management/security-history')) ?>"><div class="card-body"><i class="bi bi-clock-history fs-3 text-primary"></i><h3 class="h6 mt-3 text-body">User Access Audit</h3><p class="text-muted mb-0">Operational access and security events.</p></div></a></div>
<?php endif; ?>
</div>
