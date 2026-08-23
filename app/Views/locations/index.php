<?php use App\Core\Auth; ?>
<div class="page-heading">
    <div><div class="breadcrumb-lite">Organization / Locations<?= isset($type) ? ' / ' . e($type['name_en']) : '' ?></div><h1><?= isset($type) ? e($type['name_en']) : 'Locations' ?></h1><p>View Department locations and their organizational structure.</p></div>
    <?php if (Auth::can('location.create')): ?><a class="btn btn-primary" href="<?= e(url('locations/create')) ?>"><i class="bi bi-plus-lg"></i> Add Location</a><?php endif; ?>
</div>
<?php require BASE_PATH . '/app/Views/components/datatable.php'; ?>
