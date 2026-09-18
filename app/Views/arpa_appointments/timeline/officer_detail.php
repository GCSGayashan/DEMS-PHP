<?php
use App\Core\DataTableFormat;
use App\Services\{ArpaAppointmentDataIssueCorrectionService,ArpaAppointmentIssuePresentation};
require BASE_PATH.'/app/Views/arpa_appointments/tabs.php';
require BASE_PATH.'/app/Views/arpa_appointments/timeline/mode_tabs.php';
$date=static fn(?string $value,string $fallback='—'):string=>DataTableFormat::date($value,$fallback);
$label=static fn(?string $value):string=>DataTableFormat::enumLabel((string)$value);
?>
<div class="page-heading">
  <div>
    <div class="breadcrumb-lite">Appointment Timeline / Officers / <?= e($officer['dad_number']) ?></div>
    <h1><?= e($officer['dad_number'].' - '.$officer['name_with_initials']) ?></h1>
    <p>NIC: <?= e($officer['nic']?:'—') ?> · Service Permanency: <?= e($label($officer['arpa_service_permanency']?:'UNKNOWN')) ?></p>
    <p class="mb-0">Primary Office: <?= e($officer['primary_office_name']?($officer['primary_office_dad'].' - '.$officer['primary_office_name']):'—') ?></p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(url('hr/arpa-appointments/timeline/officers')) ?>"><i class="bi bi-arrow-left"></i> Back to Officer Timelines</a>
</div>

<div class="row g-3 mb-4">
<?php foreach(['Total Appointments'=>$summary['total_appointments'],'Permanent'=>$summary['permanent'],'Acting'=>$summary['acting'],'Attend to Duty'=>$summary['attend_to_duty'],'Duty Covering'=>$summary['duty_covering'],'Current / Open'=>$summary['current_open'],'Data Issues'=>$summary['data_issues']] as $title=>$value): ?>
  <div class="col-6 col-md-4 col-xl"><div class="card h-100"><div class="card-body"><div class="small text-muted"><?= e($title) ?></div><div class="h2 mb-0"><?= e((string)$value) ?></div></div></div></div>
<?php endforeach; ?>
</div>

<div class="card mb-4"><div class="card-body">
  <h2 class="h5 mb-3">Canonical ARPA Appointments</h2>
  <div class="table-responsive"><table class="table table-bordered align-middle">
    <thead><tr><th>Period</th><th>Appointment</th><th>Location</th><th>Workflow / State</th><th>Data Issues</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach($appointments as $appointment):
      $state=$appointment['display_status'];
    ?>
      <tr class="<?= $appointment['issue_keys']?'table-warning':'' ?>">
        <td><strong><?= $date($appointment['effective_from'],'—') ?></strong> to <strong><?= $date($appointment['effective_to']) ?></strong></td>
        <td><span class="badge text-bg-secondary"><?= e($label($appointment['appointment_type'])) ?></span></td>
        <td><div class="fw-semibold"><?= e($appointment['arpa_dad'].' - '.$appointment['arpa_name']) ?></div><div class="small text-muted"><?= e($appointment['asc_dad'].' - '.$appointment['asc_name']) ?><?= $appointment['district_name']?' / '.e($appointment['district_name']):'' ?></div></td>
        <td><?= DataTableFormat::badge($state) ?><div class="small mt-1"><?= e($label($appointment['workflow_status'])) ?> · <?= e($appointment['display_origin']) ?></div><?php foreach($appointment['exception_labels'] as $exceptionLabel): ?><div class="small text-warning"><?= e($exceptionLabel) ?></div><?php endforeach; ?></td>
        <td><?php if(!$appointment['issue_keys']): ?><span class="text-muted">None</span><?php else: ?><?php foreach($appointment['issue_keys'] as $key): ?><code class="d-block small"><?= e((string)$key) ?></code><?php endforeach; ?><?php endif; ?></td>
        <td><?php if($appointment['appointment_id']): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('hr/arpa-appointments/divisions/'.$appointment['appointment_id'])) ?>">View Appointment</a><?php else: ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('hr/arpa-appointments/requests/division/'.$appointment['request_id'])) ?>">View Request</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div></div>

<div class="card"><div class="card-body">
  <h2 class="h5 mb-3">Appointment Data Issues</h2>
  <?php if(!$issues): ?><div class="alert alert-success mb-0">No Officer timeline conflict or unresolved Appointment Data Issue was detected.</div><?php endif; ?>
  <?php foreach($issues as $issue): $presentation=ArpaAppointmentIssuePresentation::for((string)$issue['issue_type']);$existing=!empty($issue['existing_data_issue']);$correctable=$existing&&$can_correct&&in_array((string)$issue['issue_type'],ArpaAppointmentDataIssueCorrectionService::CORRECTABLE_ISSUES,true); ?>
    <div class="border rounded p-3 mb-3">
      <div class="d-flex flex-wrap justify-content-between gap-2"><div><span class="badge text-bg-danger">Data Issue</span> <strong><?= e($presentation['title']) ?></strong></div><code><?= e((string)$issue['row_key']) ?></code></div>
      <div class="mt-2"><?= e($presentation['explanation']) ?></div>
      <?php if(!empty($issue['issue_from'])): ?><div class="small mt-1">Affected period: <?= $date($issue['issue_from'],'—') ?> to <?= $date($issue['issue_to']) ?></div><?php endif; ?>
      <div class="small text-muted mt-1">Affected appointment rows: <?= e(implode(', ',(array)$issue['affected_source_ids'])) ?></div>
      <?php if($existing): ?><a class="btn btn-sm btn-outline-danger mt-2" href="<?= e(url('hr/arpa-appointments/issues/'.rawurlencode((string)$issue['row_key']))) ?>"><?= $correctable?'Review / Correct Data':'View Issue' ?></a><?php else: ?><div class="small text-muted mt-2">Review the affected canonical appointments. Existing records have not been changed.</div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div></div>
