<?php require BASE_PATH.'/app/Views/arpa_appointments/tabs.php'; ?>
<?php require BASE_PATH.'/app/Views/arpa_appointments/timeline/mode_tabs.php'; ?>
<div class="page-heading">
  <div>
    <div class="breadcrumb-lite">Appointment Timeline / <?= e($district['name_en']) ?> District / Agrarian Service Centers</div>
    <h1>ASC Timeline Summary - <?= e($district['name_en']) ?> District</h1>
    <p>Select an Agrarian Service Center to view its ARPA Division timelines.</p>
  </div>
  <?php if($backUrl): ?><a class="btn btn-outline-secondary" href="<?= e(url($backUrl)) ?>"><i class="bi bi-arrow-left"></i> Back to District Summary</a><?php endif; ?>
</div>
<?php require BASE_PATH.'/app/Views/components/datatable.php'; ?>
