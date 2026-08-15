<?php use App\Core\Auth; ?>
<div class="page-heading"><div><div class="breadcrumb-lite">Subject Management / Head Office</div><h1>Central Subject Master</h1><p>Head Office-defined subjects and functions available to every ASC.</p></div><?php if(Auth::can('subject.master.create')): ?><a class="btn btn-primary" href="<?= e(url('subjects/create')) ?>"><i class="bi bi-plus-lg"></i> Add Subject</a><?php endif; ?></div>
<?php require BASE_PATH.'/app/Views/components/datatable.php'; ?>
