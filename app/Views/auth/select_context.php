<?php
use App\Core\Csrf;

$flashes=$_SESSION['_flash']??[];
unset($_SESSION['_flash']);

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
$locationName=static function(array $context):string{
    $name=trim((string)($context['location_name']??''));
    $ascName=static function(string $value):string{
        if($value==='')return 'Assigned Agrarian Service Center';
        if(str_contains(strtolower($value),'agrarian service center'))return $value;
        if(str_ends_with(strtolower($value),' asc'))$value=substr($value,0,-4);
        return $value.' Agrarian Service Center';
    };
    return match((string)$context['role_level']){
        'SYSTEM','NATIONAL'=>'National Level',
        'DISTRICT'=>$name===''?'Assigned District':(str_ends_with(strtolower($name),' district')?$name:$name.' District'),
        'ASC'=>$ascName($name),
        'ARPA'=>$name===''?'Assigned ARPA Division':(str_ends_with(strtolower($name),' arpa division')?$name:$name.' ARPA Division'),
        default=>$name===''?'Role access':$name,
    };
};
$accessDescription=static function(array $context):string{
    $code=(string)$context['role_code'];
    $level=(string)$context['role_level'];
    $viewer=str_ends_with($code,'_VIEWER');
    $administrator=str_ends_with($code,'_ADMIN');
    $subjectOfficer=str_contains($code,'SUBJECT_OFFICER');
    $location=trim((string)($context['location_name']??''));
    if($level==='DISTRICT'&&$location!==''&&!str_ends_with(strtolower($location),' district'))$location.=' District';
    return match(true){
        $viewer&&in_array($level,['SYSTEM','NATIONAL'],true)=>'View-only access across the country',
        $viewer&&$level==='DISTRICT'=>'View-only access for this district and its offices',
        $viewer=>'View-only access for this office',
        $code==='SYSTEM_ADMIN'=>'System administration access',
        $administrator&&$level==='NATIONAL'=>'Administrative access at National Level',
        $administrator&&$level==='DISTRICT'=>'Administrative access for '.($location!==''?$location:'this district'),
        $administrator=>'Administrative access for this office',
        $subjectOfficer&&$level==='NATIONAL'=>'Work with assigned records and services at National Level',
        $subjectOfficer&&$level==='DISTRICT'=>'Work with the records and services assigned to this district',
        $subjectOfficer=>'Work with the records and services assigned to this office',
        in_array($level,['SYSTEM','NATIONAL'],true)=>'Access across the country',
        $level==='DISTRICT'=>'Access includes offices under this district',
        in_array($level,['ASC','ARPA'],true)=>'Access for this office',
        default=>'Access for this role',
    };
};

?>
<div class="mx-auto" style="max-width:900px">
  <div class="text-center mb-4">
    <img class="dad-logo dad-logo-auth mb-2" src="<?= e(url('assets/img/dad-logo.png')) ?>" alt="Department of Agrarian Development">
    <h2 class="h5 mb-1">DEMS</h2>
    <div class="text-muted small mb-3">Department Enterprise Management System</div>
    <h1 class="h3">Choose Where You Want to Work</h1>
    <p class="text-muted mb-2">You have access to more than one role or office.<br>Select the role and location you want to use now.<br>You can change this later without signing out.</p>
    <div class="small text-muted">Signed in as: <span class="fw-semibold text-body"><?= e($user['username']??'User') ?></span></div>
  </div>

  <?php foreach($flashes as $flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
  <?php endforeach; ?>

  <?php if($contexts===[]): ?>
    <div class="alert alert-warning">
      <h2 class="h5">Access unavailable</h2>
      <p class="mb-0">Your account does not currently have an active approved role and location. Please contact your system administrator.</p>
    </div>
  <?php else: ?>
    <?php if(count($contexts)>5): ?>
      <div class="mx-auto mb-3" style="max-width:620px">
        <label class="form-label fw-semibold" for="context-search">Search role or location</label>
        <div class="input-group">
          <span class="input-group-text bg-white"><i class="bi bi-search" aria-hidden="true"></i></span>
          <input class="form-control" id="context-search" type="search" placeholder="Enter a role, location, or DAD number" autocomplete="off" data-context-search>
        </div>
      </div>
    <?php endif; ?>

    <div class="row g-3 working-context-group" data-context-group>
      <?php foreach($contexts as $context):
          $current=$activeContext
              &&$activeContext['role_assignment_id']===$context['role_assignment_id']
              &&($activeContext['scope_assignment_id']??null)===($context['scope_assignment_id']??null);
          $displayRole=$friendlyRoleName($context);
          $displayLocation=$locationName($context);
          $searchText=strtolower(implode(' ',array_filter([
              $displayRole,$context['role_code'],$context['location_label'],
              $context['location_name'],$context['location_dad_number'],$displayLocation,
          ])));
      ?>
        <div class="col-12 col-md-6" data-context-row data-context-search-text="<?= e($searchText) ?>">
          <div class="card h-100 shadow-sm">
            <div class="card-body d-flex flex-column">
              <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
                <h2 class="h5 mb-0"><i class="bi <?= e($roleIcon($context)) ?> text-primary me-2" aria-hidden="true"></i><?= e($displayRole) ?></h2>
                <?php if($current): ?><span class="badge text-bg-success">Current</span><?php endif; ?>
              </div>
              <div class="fw-semibold mb-1"><i class="bi bi-geo-alt-fill text-primary me-1" aria-hidden="true"></i><?= e($displayLocation) ?></div>
              <?php if(!empty($context['location_dad_number'])): ?>
                <div class="small text-muted ms-4 mb-2"><?= e($context['location_dad_number']) ?></div>
              <?php endif; ?>
              <p class="small text-muted mb-3"><?= e($accessDescription($context)) ?></p>
              <form method="post" action="<?= e(url('select-context')) ?>" class="mt-auto">
                <?= Csrf::field() ?>
                <input type="hidden" name="role_assignment_id" value="<?= e($context['role_assignment_id']) ?>">
                <input type="hidden" name="scope_assignment_id" value="<?= e($context['scope_assignment_id']??'') ?>">
                <button class="btn btn-primary w-100" type="submit">Continue</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="working-context-no-results text-center text-muted py-5" data-context-no-results hidden>
      <i class="bi bi-search fs-3 d-block mb-2" aria-hidden="true"></i>
      No matching role or location was found.
    </div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('logout')) ?>" class="text-center mt-4">
    <?= Csrf::field() ?>
    <button class="btn btn-link" type="submit"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Sign out</button>
  </form>
</div>
