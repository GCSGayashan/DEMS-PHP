<?php require BASE_PATH.'/app/Views/arpa_appointments/tabs.php'; ?>
<?php require BASE_PATH.'/app/Views/arpa_appointments/timeline/mode_tabs.php'; ?>
<div class="page-heading">
  <div>
    <div class="breadcrumb-lite">Appointment Timeline / Officers</div>
    <h1>Officer Appointment Timelines</h1>
    <p>Cross-Division view of canonical ARPA appointments and Appointment Data Issues within your Active Working Context.</p>
  </div>
</div>
<div class="alert alert-info">Each Officer appears once. Open the timeline to review all permitted Permanent, Acting, Duty Covering, and Attend to the Duty appointments together.</div>
<?php require BASE_PATH.'/app/Views/components/datatable.php'; ?>
