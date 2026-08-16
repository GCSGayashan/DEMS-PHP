<?php use App\Core\Auth; require BASE_PATH.'/app/Views/arpa_appointments/tabs.php'; ?>
<div class="page-heading"><div><div class="breadcrumb-lite">Human Resource Management / ARPA Officer Appointments</div><h1><?= e($title) ?></h1><p><?= e($description??'Server-side search, filters, sorting, pagination, and permission-controlled actions.') ?></p></div><?php if($createUrl && $createPermission && Auth::can($createPermission)): ?><a class="btn btn-primary" href="<?= e(url($createUrl)) ?>"><i class="bi bi-plus-lg"></i> <?= e($createLabel??'Create') ?></a><?php endif; ?></div>
<?php if(isset($ascSummary) && is_array($ascSummary)): ?>
<div class="form-section mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2 class="h5 mb-1">ASC Appointment Summary</h2>
            <p class="text-muted mb-0">
                <?php if(($ascSummary['district_names']??[])!==[]): ?>
                    <?= e(implode(', ',$ascSummary['district_names'])) ?> District - open appointments by ASC.
                <?php else: ?>
                    Open appointments by ASC within your District scope.
                <?php endif; ?>
            </p>
        </div>
        <span class="badge bg-primary fs-6">
            <?= e((string)($ascSummary['totals']['total']??0)) ?> Open
        </span>
    </div>

    <?php if(($ascSummary['rows']??[])===[]): ?>
        <div class="alert alert-light border mt-3 mb-0">
            No active Agrarian Service Centers are available in your District scope.
        </div>
    <?php else: ?>
        <div class="table-responsive mt-3">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>ASC</th>
                        <th class="text-end">Permanent</th>
                        <th class="text-end">Acting</th>
                        <th class="text-end">Duty Covering</th>
                        <th class="text-end">Attend to Duty</th>
                        <th class="text-end">Total Open</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($ascSummary['rows'] as $row): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($row['asc_name']) ?></div>
                                <div class="text-muted small"><?= e($row['asc_dad']) ?></div>
                            </td>
                            <td class="text-end"><?= e((string)$row['permanent']) ?></td>
                            <td class="text-end"><?= e((string)$row['acting']) ?></td>
                            <td class="text-end"><?= e((string)$row['duty_covering']) ?></td>
                            <td class="text-end"><?= e((string)$row['attend_to_duty']) ?></td>
                            <td class="text-end fw-semibold"><?= e((string)$row['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr class="fw-semibold">
                        <td>District Total</td>
                        <td class="text-end"><?= e((string)$ascSummary['totals']['permanent']) ?></td>
                        <td class="text-end"><?= e((string)$ascSummary['totals']['acting']) ?></td>
                        <td class="text-end"><?= e((string)$ascSummary['totals']['duty_covering']) ?></td>
                        <td class="text-end"><?= e((string)$ascSummary['totals']['attend_to_duty']) ?></td>
                        <td class="text-end"><?= e((string)$ascSummary['totals']['total']) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<h2 class="h5 mb-3">Detailed Appointments</h2>
<?php endif; ?>
<?php require BASE_PATH.'/app/Views/components/datatable.php'; ?>
