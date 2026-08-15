<?php
use App\Core\Auth; use App\Core\Csrf;
$user=Auth::user(); $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:''; $flashes=$_SESSION['_flash']??[]; unset($_SESSION['_flash']);
$organizationScope=$user?App\Core\ScopeService::scopeProfile((string)$user['id']):['enterprise'=>false,'level'=>'RESTRICTED'];
function activePath(string $needle,string $path):string{return str_contains($path,$needle)?'active':'';}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(config('app.name')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= e(url('assets/vendor/datatables/dataTables.bootstrap5.min.css')) ?>" rel="stylesheet">
<link href="<?= e(url('assets/css/app.css')) ?>" rel="stylesheet"></head>
<body>
<header class="topbar"><div class="d-flex align-items-center gap-2"><button class="btn btn-link text-white p-0 d-lg-none" id="menuToggle"><i class="bi bi-list fs-3"></i></button><div class="brand-circle sm">D</div><strong>DEMS</strong><span class="small d-none d-md-inline">Department Enterprise Management System</span><span class="badge rounded-pill bg-success">DEVELOPMENT</span></div>
<div class="d-flex align-items-center gap-3"><span class="d-none d-md-inline"><i class="bi bi-globe2"></i> English</span><div class="dropdown"><button class="btn btn-link text-white text-decoration-none dropdown-toggle p-0" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-person-fill"></i> <?= e($user['username']??'User') ?></button><ul class="dropdown-menu dropdown-menu-end"><li><a class="dropdown-item" href="<?= e(url('account/change-password')) ?>"><i class="bi bi-key me-2"></i>Change Password</a></li><li><hr class="dropdown-divider"></li><li><form method="post" action="<?= e(url('logout')) ?>" class="m-0"><?= Csrf::field() ?><button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Logout</button></form></li></ul></div></div></header>
<div class="app-shell"><aside class="sidebar" id="sidebar"><nav class="nav flex-column py-2">
<a class="nav-link <?= activePath('/dashboard',$path) ?>" href="<?= e(url('dashboard')) ?>"><i class="bi bi-grid-fill"></i> Dashboard</a>
<div class="nav-section">Organization Management</div>
<?php if(Auth::can('location.view')): ?>
<?php if(!$organizationScope['enterprise']): ?>
<?php if($organizationScope['level']==='ASC'): ?><a class="nav-link" href="<?= e(url('locations/type/ASC')) ?>"><i class="bi bi-geo-alt-fill"></i> My Agrarian Service Center</a><?php else: ?><a class="nav-link" href="<?= e(url('locations/type/DISTRICT')) ?>"><i class="bi bi-geo-alt-fill"></i> My District</a><?php endif; ?>
<?php foreach([['PROVINCE','Province Context'],['DISTRICT','District Context'],['ASC','Agrarian Service Centers'],['ARPA_DIVISION','ARPA Divisions'],['GN_DIVISION','Grama Niladhari Divisions']] as [$typeKey,$typeLabel]): ?><a class="nav-link ps-4" href="<?= e(url('locations/type/'.$typeKey)) ?>"><i class="bi bi-geo-alt"></i> <?= e($typeLabel) ?></a><?php endforeach; ?>
<?php else: ?>
<a class="nav-link <?= activePath('/locations',$path) ?>" href="<?= e(url('locations')) ?>"><i class="bi bi-geo-alt-fill"></i> All Locations</a>
<?php $menuLocationTypes=App\Core\Database::pdo()->query("SELECT system_key,name_en FROM location_type WHERE active=1 ORDER BY display_order")->fetchAll(); foreach($menuLocationTypes as $mlt): ?>
<a class="nav-link ps-4" href="<?= e(url('locations/type/'.$mlt['system_key'])) ?>"><i class="bi bi-geo-alt"></i> <?= e($mlt['name_en']) ?></a>
<?php endforeach; ?>
<a class="nav-link <?= activePath('/location-types',$path) ?>" href="<?= e(url('location-types')) ?>"><i class="bi bi-diagram-3-fill"></i> Location Types</a>
<?php endif; ?>
<?php if($organizationScope['enterprise']||$organizationScope['level']!=='ASC'): ?><a class="nav-link <?= activePath('/location-hierarchy',$path) ?>" href="<?= e(url('location-hierarchy')) ?>"><i class="bi bi-share-fill"></i> Location Hierarchy</a><?php endif; ?>
<?php endif; ?>
<?php if(Auth::can('office.view')): ?><a class="nav-link <?= activePath('/offices',$path) ?>" href="<?= e(url('offices')) ?>"><i class="bi bi-building"></i> Offices</a><?php endif; ?>
<div class="nav-section">Human Resource Management</div>
<?php if(Auth::can('officer.view')): ?><a class="nav-link <?= activePath('/hr/officers',$path) ?>" href="<?= e(url('hr/officers')) ?>"><i class="bi bi-people-fill"></i> Officers</a><?php endif; ?>
<?php if(Auth::can('arpa.appointment.view')): ?><a class="nav-link <?= activePath('/hr/arpa-appointments',$path) ?>" href="<?= e(url('hr/arpa-appointments')) ?>"><i class="bi bi-person-workspace"></i> ARPA Officer Appointments</a><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/new')) ?>">New Appointments</a><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/submitted')) ?>">Submitted Appointments</a><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/approval')) ?>">Approval / Verification</a><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/open')) ?>">Open Appointments</a><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/history')) ?>">Historical Appointments</a><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/vacant-divisions')) ?>">Vacant ARPA Divisions</a><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/issues')) ?>">Appointment Data Issues</a><?php endif; ?>
<?php if(Auth::can('arpa.legacy-preview.view')): ?><a class="nav-link ps-4 <?= activePath('/hr/arpa-appointments/legacy-preview',$path) ?>" href="<?= e(url('hr/arpa-appointments/legacy-preview')) ?>"><i class="bi bi-eye"></i> Legacy Appointment Preview</a><?php endif; ?>
<?php if(Auth::can('arpa.legacy-reconciliation.view')): ?><a class="nav-link ps-4 <?= activePath('/hr/arpa-appointments/legacy-review',$path) ?>" href="<?= e(url('hr/arpa-appointments/legacy-review')) ?>"><i class="bi bi-clipboard-check"></i> Legacy Migration Review</a><?php endif; ?>
<?php if(Auth::can('hr.master.view')): ?>
<a class="nav-link" href="<?= e(url('hr/titles')) ?>">Titles</a><a class="nav-link" href="<?= e(url('hr/appointment-natures')) ?>">Appointment Natures</a><a class="nav-link" href="<?= e(url('hr/designations')) ?>">Designations</a><a class="nav-link" href="<?= e(url('hr/classes')) ?>">Classes</a><a class="nav-link" href="<?= e(url('hr/officer-statuses')) ?>">Officer Statuses</a><a class="nav-link" href="<?= e(url('hr/civil-statuses')) ?>">Civil Statuses</a>
<?php endif; ?>
<div class="nav-section">Enterprise Modules</div>
<?php if(Auth::can('subject.master.view')): ?><a class="nav-link <?= activePath('/subjects',$path) ?>" href="<?= e(url('subjects')) ?>"><i class="bi bi-clipboard2-data"></i> Subject Management</a><?php endif; ?>
<a class="nav-link" href="<?= e(url('module/file-management')) ?>"><i class="bi bi-folder-fill"></i> File Management</a>
<a class="nav-link" href="<?= e(url('module/correspondence-management')) ?>"><i class="bi bi-file-earmark-text"></i> Correspondence</a>
<a class="nav-link" href="<?= e(url('module/workflow-management')) ?>"><i class="bi bi-list-check"></i> Workflow Management</a>
<div class="nav-section">User Management</div>
<?php if(Auth::can('user.view')): ?><a class="nav-link" href="<?= e(url('access-management/users')) ?>"><i class="bi bi-people"></i> Active Users</a><a class="nav-link ps-4" href="<?= e(url('access-management/users/historical')) ?>"><i class="bi bi-person-lock"></i> Historical / Disabled Users</a><a class="nav-link" href="<?= e(url('access-management/account-requests')) ?>"><i class="bi bi-card-checklist"></i> Account Requests</a><?php endif; ?>
<?php if(Auth::can('role.manage')): ?><a class="nav-link" href="<?= e(url('access-management/roles')) ?>"><i class="bi bi-shield-fill"></i> Roles</a><?php endif; ?>
<?php if(Auth::can('permission.view')): ?><a class="nav-link" href="<?= e(url('access-management/permissions')) ?>"><i class="bi bi-shield-check"></i> Permissions</a><?php endif; ?>
<?php if(Auth::can('user.assign-role')): ?><a class="nav-link" href="<?= e(url('access-management/role-assignments')) ?>"><i class="bi bi-person-badge"></i> Role Assignments</a><?php endif; ?>
<?php if(Auth::can('user.assign-scope')): ?><a class="nav-link" href="<?= e(url('access-management/scope-assignments')) ?>"><i class="bi bi-map"></i> Scope Assignments</a><?php endif; ?>
<?php if(Auth::can('user.retry-provisioning')): ?><a class="nav-link" href="<?= e(url('access-management/provisioning-failures')) ?>">Provisioning Failures</a><?php endif; ?>
<?php if(Auth::can('user.view-security-history')): ?><a class="nav-link" href="<?= e(url('access-management/security-history')) ?>">Security History</a><?php endif; ?>
<div class="nav-section">Reporting</div><a class="nav-link" href="<?= e(url('module/reporting')) ?>"><i class="bi bi-graph-up"></i> Reporting and Dashboards</a>
<?php if(Auth::can('user.view')||Auth::can('role.manage')||Auth::can('permission.view')||Auth::can('user.view-security-history')): ?><div class="nav-section">Administration</div><a class="nav-link" href="<?= e(url('module/system-administration')) ?>"><i class="bi bi-gear-fill"></i> System Administration</a><?php endif; ?>
</nav></aside>
<main class="content-area"><div class="page-fluid"><?php foreach($flashes as $f): ?><div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show"><?= e($f['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endforeach; ?><?= $content ?></div><footer class="page-footer">Department of Agrarian Development | DEMS PHP Edition | <?= date('Y') ?></footer></main></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(url('assets/vendor/jquery/jquery.min.js')) ?>"></script><script src="<?= e(url('assets/vendor/datatables/dataTables.min.js')) ?>"></script><script src="<?= e(url('assets/vendor/datatables/dataTables.bootstrap5.min.js')) ?>"></script><script src="<?= e(url('assets/js/dems-datatable.js')) ?>"></script><script src="<?= e(url('assets/js/dems-charts.js')) ?>"></script><script src="<?= e(url('assets/js/app.js')) ?>"></script></body></html>
