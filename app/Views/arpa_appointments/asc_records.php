<?php require BASE_PATH.'/app/Views/arpa_appointments/tabs.php'; ?>

<div class="page-heading">
    <div>
        <div class="breadcrumb-lite">
            Human Resource Management / ARPA Officer Assignments
            <?php if(isset($district) && is_array($district)): ?>
                / <?= e($district['name_en']) ?>
            <?php endif; ?>
            / <?= e($asc['name_en']) ?>
        </div>

        <h1><?= e($title) ?> - <?= e($asc['name_en']) ?></h1>

        <p>
            <?= e($description) ?>
            <span class="text-muted">
                ASC <?= e($asc['dad_number']) ?>.
            </span>
        </p>
    </div>

    <a class="btn btn-outline-secondary" href="<?= e(url($summaryUrl)) ?>">
        <i class="bi bi-arrow-left"></i> Back to ASC Summary
    </a>
</div>

<?php if(in_array($dataTable['id'],['dems-arpa-submitted-appointments-table','dems-arpa-approval-verification-table'],true)): ?><h2 class="h5"><?= str_contains($dataTable['id'],'submitted')?'Original Workflow Requests':'Native Completed Workflow Actions' ?></h2><?php endif; ?>
<?php require BASE_PATH.'/app/Views/components/datatable.php'; ?>
<?php require BASE_PATH.'/app/Views/arpa_appointments/historical_approval_panel.php'; ?>
