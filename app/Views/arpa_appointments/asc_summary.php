<?php use App\Core\Auth; require BASE_PATH.'/app/Views/arpa_appointments/tabs.php'; ?>

<div class="page-heading">
    <div>
        <div class="breadcrumb-lite">Human Resource Management / ARPA Officer Appointments</div>
        <h1><?= e($title) ?></h1>
        <p><?= e($description) ?></p>
    </div>

    <?php if($createUrl && $createPermission && Auth::can($createPermission)): ?>
        <a class="btn btn-primary" href="<?= e(url($createUrl)) ?>">
            <i class="bi bi-plus-lg"></i> <?= e($createLabel??'Create') ?>
        </a>
    <?php endif; ?>
</div>

<div class="mb-3">
    <h2 class="h5 mb-1">ASC Summary</h2>
    <p class="text-muted mb-0">
        Select <strong>View Records</strong> to open the records belonging to an Agrarian Service Center.
    </p>
</div>

<?php require BASE_PATH.'/app/Views/components/datatable.php'; ?>