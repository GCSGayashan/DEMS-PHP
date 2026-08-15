<?php require BASE_PATH.'/app/Views/arpa_appointments/tabs.php';
$labels=['CURRENT_ACTION_REQUIRED'=>'Needs Attention','LEGACY_DATA_WARNINGS'=>'Old Data Warnings','HISTORICAL_EXCEPTIONS'=>'Historical Records','RESOLVED_REVIEWED'=>'Reviewed / Corrected'];
?>
<div class="page-heading"><div><div class="breadcrumb-lite">Human Resource Management / ARPA Officer Appointments</div><h1>Appointment Data Issues</h1><p>Check appointment records that may contain missing or conflicting information.</p></div></div>
<div class="alert alert-info mb-3"><h2 class="h6">About this page</h2><p class="mb-0">This page shows appointment records that may need checking. Some old records are shown only as warnings. Select Review to see what needs to be checked. Correcting a data issue does not require the normal appointment approval process.</p></div>
<div class="nav nav-pills gap-2 mb-3" aria-label="Appointment issue categories">
<?php foreach($labels as $key=>$label): ?><a class="nav-link <?= $category===$key?'active':'' ?>" href="<?= e(url('hr/arpa-appointments/issues?category='.$key)) ?>"><?= e($label) ?></a><?php endforeach; ?>
</div>
<?php if($category==='CURRENT_ACTION_REQUIRED'): ?><div class="alert alert-warning"><strong>Needs Attention</strong><br>These records may need correction.</div><?php endif; ?>
<?php if($category==='LEGACY_DATA_WARNINGS'): ?><div class="alert alert-secondary"><strong>Old Data Warnings</strong><br>These are old records with unusual information. They do not always need correction.</div><?php endif; ?>
<?php if($category==='HISTORICAL_EXCEPTIONS'): ?><div class="alert alert-info"><strong>Historical Records</strong><br>These issues belong to old appointment history.</div><?php endif; ?>
<?php if($category==='RESOLVED_REVIEWED'): ?><div class="alert alert-success"><strong>Reviewed / Corrected</strong><br>These issues were already reviewed.</div><?php endif; ?>
<?php require BASE_PATH.'/app/Views/components/datatable.php'; ?>
