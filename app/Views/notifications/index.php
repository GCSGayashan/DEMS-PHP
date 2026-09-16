<div class="page-heading"><div><div class="breadcrumb-lite">Notifications</div><h1>Notification Center</h1><p>Reading a notification does not complete its underlying workflow action.</p></div></div>
<ul class="nav nav-pills mb-3">
<?php foreach(['action'=>'Action Required','unread'=>'Unread','all'=>'All','completed'=>'Completed'] as $key=>$label): ?><li class="nav-item"><a class="nav-link <?= $view===$key?'active':'' ?>" href="<?= e(url('notifications?view='.$key)) ?>"><?= e($label) ?></a></li><?php endforeach; ?>
</ul>
<?php require BASE_PATH.'/app/Views/components/datatable.php'; ?>
