<?php require BASE_PATH.'/app/Views/arpa_appointments/tabs.php'; ?>
<?php require BASE_PATH.'/app/Views/arpa_appointments/timeline/mode_tabs.php'; ?>
<div class="page-heading">
  <div>
    <div class="breadcrumb-lite">Appointment Timeline<?php if($district): ?> / <?= e($district['name_en']) ?> District<?php endif; ?> / <?= e($asc['name_en']) ?> ASC / ARPA Divisions</div>
    <h1>ARPA Division Timelines - <?= e($asc['name_en']) ?> ASC</h1>
    <p>Canonical appointment coverage from 01 Jan 2025, including gaps and Appointment Data Issues.</p>
  </div>
  <?php if($backUrl): ?><a class="btn btn-outline-secondary" href="<?= e(url($backUrl)) ?>"><i class="bi bi-arrow-left"></i> Back to ASC Summary</a><?php endif; ?>
</div>
<div class="alert alert-info">
  Each row represents one ARPA Division in your current Active Working Context. Open a timeline to review its complete assignment coverage.
</div>
<?php require BASE_PATH.'/app/Views/components/datatable.php'; ?>
