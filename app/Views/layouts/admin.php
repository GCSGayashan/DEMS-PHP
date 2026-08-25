<?php
use App\Core\Auth; use App\Core\Csrf;
$user=Auth::user(); $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:''; $flashes=$_SESSION['_flash']??[]; unset($_SESSION['_flash']);
$activeContext=Auth::activeContext();$availableContexts=Auth::availableContexts();
$friendlyRoleNames=[
    'SYSTEM_ADMIN'=>'System Administrator',
    'SECURITY_ADMIN'=>'Security Administrator',
    'USER_ADMIN'=>'User Administrator',
    'NATIONAL_ADMIN'=>'National Administrator',
    'NATIONAL_SUBJECT_OFFICER'=>'National Subject Officer',
    'NATIONAL_VIEWER'=>'National Viewer',
    'DISTRICT_ADMIN'=>'District Administrator',
    'DISTRICT_SUBJECT_OFFICER'=>'District Subject Officer',
    'DISTRICT_VIEWER'=>'District Viewer',
    'ASC_ADMIN'=>'ASC Administrator',
    'ASC_SUBJECT_OFFICER'=>'ASC Subject Officer',
    'ASC_VIEWER'=>'ASC Viewer',
    'ARPA_OFFICER'=>'ARPA Officer',
    'FARMER'=>'Farmer',
];
$friendlyRoleName=static fn(array $context):string=>$friendlyRoleNames[(string)$context['role_code']]??(string)$context['role_name'];
$roleIcon=static function(array $context):string{
    $code=(string)$context['role_code'];
    if($code==='SYSTEM_ADMIN'||(string)$context['role_level']==='SYSTEM')return 'bi-gear';
    if(str_ends_with($code,'_ADMIN'))return 'bi-shield-check';
    if(str_contains($code,'SUBJECT_OFFICER'))return 'bi-person-badge';
    if(str_ends_with($code,'_VIEWER')||$code==='AUDITOR')return 'bi-eye';
    return match((string)$context['role_level']){
        'NATIONAL'=>'bi-bank','DISTRICT'=>'bi-building','ASC'=>'bi-house-door',
        'ARPA'=>'bi-flower1',default=>'bi-person-badge',
    };
};
$contextLocationNames=static function(array $context):array{
    $name=trim((string)($context['location_name']??''));
    $ascNames=static function(string $value):array{
        if($value==='')return ['full'=>'Assigned Agrarian Service Center','compact'=>'Assigned ASC'];
        $fullSuffix=' agrarian service center';
        if(str_ends_with(strtolower($value),$fullSuffix))$value=substr($value,0,-strlen($fullSuffix));
        if(str_ends_with(strtolower($value),' asc'))$value=substr($value,0,-4);
        return ['full'=>$value.' Agrarian Service Center','compact'=>$value.' ASC'];
    };
    return match((string)$context['role_level']){
        'SYSTEM','NATIONAL'=>['full'=>'National Level','compact'=>'National Level'],
        'DISTRICT'=>['full'=>$name===''?'Assigned District':(str_ends_with(strtolower($name),' district')?$name:$name.' District'),'compact'=>$name===''?'Assigned District':(str_ends_with(strtolower($name),' district')?$name:$name.' District')],
        'ASC'=>$ascNames($name),
        'ARPA'=>['full'=>$name===''?'Assigned ARPA Division':(str_ends_with(strtolower($name),' arpa division')?$name:$name.' ARPA Division'),'compact'=>$name===''?'Assigned ARPA Division':(str_ends_with(strtolower($name),' arpa division')?$name:$name.' ARPA Division')],
        default=>['full'=>$name===''?'Assigned access':$name,'compact'=>$name===''?'Assigned access':$name],
    };
};
$availableContextGroups=[];
foreach($availableContexts as $context){
    $key=(string)$context['role_id'];
    if(!isset($availableContextGroups[$key])){
        $availableContextGroups[$key]=[
            'role_name'=>$friendlyRoleName($context),
            'role_icon'=>$roleIcon($context),
            'contexts'=>[],
        ];
    }
    $availableContextGroups[$key]['contexts'][]=$context;
}
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
<header class="topbar"><div class="d-flex align-items-center gap-2 topbar-brand"><button class="btn btn-link text-white p-0 d-lg-none" id="menuToggle"><i class="bi bi-list fs-3"></i></button><img class="dad-logo dad-logo-topbar" src="<?= e(url('assets/img/dad-logo.png')) ?>" alt="Department of Agrarian Development"><strong class="topbar-brand-name">DEMS</strong><span class="small d-none d-xl-inline">Department Enterprise Management System</span><span class="badge rounded-pill bg-success topbar-development"><span class="d-none d-sm-inline">DEVELOPMENT</span><span class="d-sm-none">DEV</span></span></div>
<div class="d-flex align-items-center gap-3 topbar-actions">
<?php if($activeContext): ?>
<div class="dropdown">
  <?php $activeLocationNames=$contextLocationNames($activeContext); ?>
  <button class="btn btn-link context-switcher text-white text-decoration-none" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Change role or office">
    <span class="context-switcher-text">
      <span class="context-role"><i class="bi <?= e($roleIcon($activeContext)) ?>" aria-hidden="true"></i><span class="context-role-label"><?= e($friendlyRoleName($activeContext)) ?></span></span>
      <span class="context-location"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i><span class="context-location-label context-location-full"><?= e($activeLocationNames['full']) ?></span><span class="context-location-label context-location-compact"><?= e($activeLocationNames['compact']) ?></span></span>
    </span>
    <i class="bi bi-chevron-down context-switcher-caret" aria-hidden="true"></i>
  </button>
  <div class="dropdown-menu dropdown-menu-end working-context-dropdown">
    <h6 class="dropdown-header">Change Role or Office</h6>
    <?php foreach($availableContextGroups as $group): ?>
      <div class="working-context-dropdown-role"><i class="bi <?= e($group['role_icon']) ?> me-2" aria-hidden="true"></i><?= e($group['role_name']) ?></div>
      <?php foreach($group['contexts'] as $context):
          $selected=$context['role_assignment_id']===$activeContext['role_assignment_id']
              &&($context['scope_assignment_id']??null)===($activeContext['scope_assignment_id']??null);
          $locationNames=$contextLocationNames($context);
      ?>
        <form method="post" action="<?= e(url('select-context')) ?>" class="m-0">
          <?= Csrf::field() ?>
          <input type="hidden" name="role_assignment_id" value="<?= e($context['role_assignment_id']) ?>">
          <input type="hidden" name="scope_assignment_id" value="<?= e($context['scope_assignment_id']??'') ?>">
          <button class="dropdown-item<?= $selected?' active':'' ?>" type="submit">
            <span class="working-context-dropdown-location">
              <span class="fw-semibold"><i class="bi bi-geo-alt-fill me-1" aria-hidden="true"></i><?= e($locationNames['full']) ?></span>
              <?php if(!empty($context['location_dad_number'])): ?><span class="small text-muted"><?= e($context['location_dad_number']) ?></span><?php endif; ?>
            </span>
            <?php if($selected): ?><span class="badge text-bg-success">Current</span><?php endif; ?>
          </button>
        </form>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<span class="d-none d-xl-inline"><i class="bi bi-globe2"></i> English</span><div class="dropdown"><button class="btn btn-link text-white text-decoration-none dropdown-toggle p-0" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-person-fill"></i> <span class="topbar-user-label"><?= e($user['username']??'User') ?></span></button><ul class="dropdown-menu dropdown-menu-end"><li><a class="dropdown-item" href="<?= e(url('account/change-password')) ?>"><i class="bi bi-key me-2"></i>Change Password</a></li><li><a class="dropdown-item" href="<?= e(url('select-context')) ?>"><i class="bi bi-person-badge me-2"></i>Change Role or Office</a></li><li><hr class="dropdown-divider"></li><li><form method="post" action="<?= e(url('logout')) ?>" class="m-0"><?= Csrf::field() ?><button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form></li></ul></div></div></header>
<div class="app-shell"><aside class="sidebar" id="sidebar"><nav class="nav flex-column py-2">
<a class="nav-link <?= activePath('/dashboard',$path) ?>" href="<?= e(url('dashboard')) ?>"><i class="bi bi-grid-fill"></i> Dashboard</a>
<div class="nav-section">Organization</div>
<?php if(Auth::can('location.view')): ?>
<?php if(!$organizationScope['enterprise']): ?>
<?php if($organizationScope['level']==='ASC'): ?><a class="nav-link" href="<?= e(url('locations/type/ASC')) ?>"><i class="bi bi-geo-alt-fill"></i> My Service Center</a><?php else: ?><a class="nav-link" href="<?= e(url('locations/type/DISTRICT')) ?>"><i class="bi bi-geo-alt-fill"></i> My District</a><?php endif; ?>
<?php foreach([['PROVINCE','Province'],['DISTRICT','District'],['ASC','Agrarian Service Centers'],['ARPA_DIVISION','ARPA Divisions'],['GN_DIVISION','GN Divisions']] as [$typeKey,$typeLabel]): ?><a class="nav-link ps-4" href="<?= e(url('locations/type/'.$typeKey)) ?>"><i class="bi bi-geo-alt"></i> <?= e($typeLabel) ?></a><?php endforeach; ?>
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
<div class="nav-section">HUMAN RESOURCE MANAGEMENT</div>
<?php if(Auth::can('officer.view')): ?><a class="nav-link <?= activePath('/hr/officers',$path) ?>" href="<?= e(url('hr/officers')) ?>"><i class="bi bi-people-fill"></i> Officers</a><?php endif; ?>
<?php if(Auth::can('arpa.appointment.view')): ?><a class="nav-link <?= activePath('/hr/arpa-appointments',$path) ?>" href="<?= e(url('hr/arpa-appointments')) ?>"><i class="bi bi-person-workspace"></i> ARPA Officer Assignments</a><?php if(Auth::can('arpa.appointment.create')): ?><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/new')) ?>">New Assignments</a><?php endif; ?><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/submitted')) ?>">Submitted</a><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/approval')) ?>">Review &amp; Approve</a><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/open')) ?>">Current Assignments</a><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/history')) ?>">Assignment History</a><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/vacant-divisions')) ?>">Vacant ARPA Divisions</a><a class="nav-link ps-4" href="<?= e(url('hr/arpa-appointments/issues')) ?>">Appointment Data Issues</a><?php endif; ?>
<?php if(Auth::can('arpa.legacy-preview.view')): ?><a class="nav-link ps-4 <?= activePath('/hr/arpa-appointments/legacy-preview',$path) ?>" href="<?= e(url('hr/arpa-appointments/legacy-preview')) ?>"><i class="bi bi-eye"></i> Legacy Appointment Preview</a><?php endif; ?>
<?php if(Auth::can('arpa.legacy-reconciliation.view')): ?><a class="nav-link ps-4 <?= activePath('/hr/arpa-appointments/legacy-review',$path) ?>" href="<?= e(url('hr/arpa-appointments/legacy-review')) ?>"><i class="bi bi-clipboard-check"></i> Legacy Migration Review</a><?php endif; ?>
<?php if(Auth::can('hr.master.view')): ?>
<div class="nav-link fw-semibold"><i class="bi bi-person-vcard"></i> Officer Supporting Master</div><a class="nav-link ps-4" href="<?= e(url('hr/titles')) ?>">Titles</a><a class="nav-link ps-4" href="<?= e(url('hr/appointment-natures')) ?>">Appointment Natures</a><a class="nav-link ps-4" href="<?= e(url('hr/designations')) ?>">Designations</a><a class="nav-link ps-4" href="<?= e(url('hr/classes')) ?>">Classes</a><a class="nav-link ps-4" href="<?= e(url('hr/officer-statuses')) ?>">Officer Statuses</a><a class="nav-link ps-4" href="<?= e(url('hr/civil-statuses')) ?>">Civil Statuses</a>
<?php endif; ?>
<div class="nav-section">Enterprise Modules</div>
<?php if(Auth::can('subject.master.view')): ?><a class="nav-link <?= activePath('/subjects',$path) ?>" href="<?= e(url('subjects')) ?>"><i class="bi bi-clipboard2-data"></i> Subject Management</a><?php endif; ?>
<a class="nav-link" href="<?= e(url('module/file-management')) ?>"><i class="bi bi-folder-fill"></i> File Management</a>
<a class="nav-link" href="<?= e(url('module/correspondence-management')) ?>"><i class="bi bi-file-earmark-text"></i> Correspondence</a>
<a class="nav-link" href="<?= e(url('module/workflow-management')) ?>"><i class="bi bi-list-check"></i> Workflow Management</a>
<div class="nav-section">User Management</div>
<?php if(Auth::can('user.view')): ?><a class="nav-link" href="<?= e(url('access-management/users')) ?>"><i class="bi bi-people"></i> Active Users</a><a class="nav-link ps-4" href="<?= e(url('access-management/users/historical')) ?>"><i class="bi bi-person-lock"></i> Inactive Users</a><a class="nav-link" href="<?= e(url('access-management/account-requests')) ?>"><i class="bi bi-card-checklist"></i> User Requests</a><?php endif; ?>
<?php if(Auth::can('role.manage')): ?><a class="nav-link" href="<?= e(url('access-management/roles')) ?>"><i class="bi bi-shield-fill"></i> Roles</a><?php endif; ?>
<?php if(Auth::can('permission.view')): ?><a class="nav-link" href="<?= e(url('access-management/permissions')) ?>"><i class="bi bi-shield-check"></i> Permissions</a><?php endif; ?>
<?php if(Auth::can('user.assign-role')): ?><a class="nav-link" href="<?= e(url('access-management/role-assignments')) ?>"><i class="bi bi-person-badge"></i> User Roles</a><?php endif; ?>
<?php if(Auth::can('user.assign-scope')): ?><a class="nav-link" href="<?= e(url('access-management/scope-assignments')) ?>"><i class="bi bi-map"></i> Assigned Locations</a><?php endif; ?>
<?php if(Auth::can('user.retry-provisioning')): ?><a class="nav-link" href="<?= e(url('access-management/provisioning-failures')) ?>">Provisioning Failures</a><?php endif; ?>
<?php if(Auth::can('user.view-security-history')): ?><a class="nav-link" href="<?= e(url('access-management/security-history')) ?>">Security History</a><?php endif; ?>
<div class="nav-section">Reporting</div><a class="nav-link" href="<?= e(url('module/reporting')) ?>"><i class="bi bi-graph-up"></i> Reporting and Dashboards</a>
<?php if(Auth::can('user.view')||Auth::can('role.manage')||Auth::can('permission.view')||Auth::can('user.view-security-history')): ?><div class="nav-section">Administration</div><a class="nav-link" href="<?= e(url('module/system-administration')) ?>"><i class="bi bi-gear-fill"></i> System Administration</a><?php endif; ?>
</nav></aside>
<main class="content-area"><div class="page-fluid"><?php foreach($flashes as $f): ?><div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show"><?= e($f['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endforeach; ?><?= $content ?></div><footer class="page-footer">Department of Agrarian Development | DEMS PHP Edition | <?= date('Y') ?></footer></main></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(url('assets/vendor/jquery/jquery.min.js')) ?>"></script><script src="<?= e(url('assets/vendor/datatables/dataTables.min.js')) ?>"></script><script src="<?= e(url('assets/vendor/datatables/dataTables.bootstrap5.min.js')) ?>"></script><script src="<?= e(url('assets/js/dems-datatable.js')) ?>"></script><script src="<?= e(url('assets/js/dems-charts.js')) ?>"></script><script src="<?= e(url('assets/js/app.js')) ?>"></script></body></html>
