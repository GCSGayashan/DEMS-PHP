<?php use App\Core\Auth; require BASE_PATH.'/app/Views/arpa_appointments/tabs.php'; ?>
<div class="page-heading">
    <div>
        <div class="breadcrumb-lite">Human Resource Management / ARPA Officer Assignments</div>
        <h1><?= e($title) ?></h1>
        <p><?= e($description??'Search and review ARPA Officer assignments.') ?></p>
    </div>
    <?php
    $hierarchyBase=match($dataTable['id']){
        'dems-arpa-submitted-appointments-table'=>'hr/arpa-appointments/submitted',
        'dems-arpa-approval-verification-table'=>'hr/arpa-appointments/approval',
        default=>null,
    };
    $hierarchyLevel=$hierarchyBase===null?null:(\App\Core\ScopeService::scopeProfile((string)Auth::user()['id'])['level']??null);
    if(in_array($hierarchyLevel,['DISTRICT','NATIONAL'],true)): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url($hierarchyBase.'?view=hierarchy')) ?>"><?= $hierarchyLevel==='NATIONAL'?'Browse Districts / ASCs':'Browse ASCs' ?></a>
    <?php endif; ?>
    <?php if($createUrl && $createPermission && Auth::can($createPermission)): ?>
        <a class="btn btn-primary" href="<?= e(url($createUrl)) ?>">
            <i class="bi bi-plus-lg"></i> <?= e($createLabel??'Create') ?>
        </a>
    <?php endif; ?>
</div>
<?php require BASE_PATH.'/app/Views/components/datatable.php'; ?>
