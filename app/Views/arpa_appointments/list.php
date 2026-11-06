<?php use App\Core\Auth; require BASE_PATH.'/app/Views/arpa_appointments/tabs.php'; ?>
<div class="page-heading">
    <div>
        <div class="breadcrumb-lite">Human Resource Management / ARPA Officer Assignments</div>
        <h1><?= e($title) ?></h1>
        <p><?= e($description??'Search and review ARPA Officer assignments.') ?></p>
    </div>
    <?php if($createUrl && $createPermission && Auth::can($createPermission)): ?>
        <a class="btn btn-primary" href="<?= e(url($createUrl)) ?>">
            <i class="bi bi-plus-lg"></i> <?= e($createLabel??'Create') ?>
        </a>
    <?php endif; ?>
</div>
<?php if(in_array($dataTable['id'],['dems-arpa-submitted-appointments-table','dems-arpa-approval-verification-table'],true)): ?><h2 class="h5"><?= str_contains($dataTable['id'],'submitted')?'Original Workflow Requests':'Native Completed Workflow Actions' ?></h2><?php endif; ?>
<?php require BASE_PATH.'/app/Views/components/datatable.php'; ?>
<?php require BASE_PATH.'/app/Views/arpa_appointments/historical_approval_panel.php'; ?>
