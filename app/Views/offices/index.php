<?php use App\Core\Auth; ?>
<div class="page-heading"><div><div class="breadcrumb-lite">Organization / Offices</div><h1>Offices</h1><p>View Head Office, District Offices, and Agrarian Service Center Offices.</p></div><?php if(Auth::can('office.create')): ?><a class="btn btn-primary" href="<?= e(url('offices/create')) ?>"><i class="bi bi-plus-lg"></i> Add Office</a><?php endif; ?></div>
<?php require BASE_PATH . '/app/Views/components/datatable.php'; ?>
