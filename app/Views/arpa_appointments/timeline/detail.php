<?php
use App\Core\DataTableFormat;
use App\Services\{ArpaAppointmentDataIssueCorrectionService,ArpaAppointmentIssuePresentation};
require BASE_PATH.'/app/Views/arpa_appointments/tabs.php';
require BASE_PATH.'/app/Views/arpa_appointments/timeline/mode_tabs.php';
$dash='—';
$date=static fn(?string $value,string $fallback='Open'):string=>DataTableFormat::date($value,$fallback);
$typeLabel=static fn(?string $value):string=>DataTableFormat::enumLabel((string)$value);
$statuses=array_map(static fn($value):string=>$typeLabel((string)$value),(array)($diagnostic['timeline_statuses']??[]));
$hasTimelineConflict=array_intersect((array)($diagnostic['timeline_statuses']??[]),['INVALID_PERIOD','MULTIPLE_OPEN_ASSIGNMENTS','OVERLAP'])!==[]||$summary['data_issues']>0;
?>
<div class="page-heading">
  <div>
    <div class="breadcrumb-lite">ARPA Officer Assignments / Appointment Timeline</div>
    <h1><?= e($division['dad_number'].' - '.$division['name_en']) ?></h1>
    <p><?= e($division['asc_dad_number'].' - '.$division['asc_name']) ?><?= $division['district_name']?' / '.e($division['district_name']):'' ?></p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(url('hr/arpa-appointments/timeline')) ?>">Back to Division List</a>
</div>

<div class="row g-3 mb-4">
  <?php foreach([
    'Canonical Appointments'=>$summary['total_appointments'],
    'Current / Open'=>$summary['current_open_appointments'],
    'Historical / Ended'=>$summary['historical_appointments'],
    'Uncovered Periods'=>$summary['missing_periods'],
    'Unresolved Data Issues'=>$summary['data_issues'],
  ] as $label=>$value): ?>
  <div class="col-6 col-lg"><div class="card h-100"><div class="card-body"><div class="text-muted small"><?= e($label) ?></div><div class="display-6"><?= e((string)$value) ?></div></div></div></div>
  <?php endforeach; ?>
</div>

<?php if($hasTimelineConflict): ?>
<div class="alert alert-warning"><strong>Timeline needs attention:</strong> <?= e(implode(', ',$statuses)) ?>. Conflicting periods and Data Issues are shown below; existing records have not been changed.</div>
<?php elseif($statuses!==['Complete']): ?>
<div class="alert alert-info"><strong>Timeline contains uncovered periods:</strong> <?= e(implode(', ',$statuses)) ?>. Uncovered periods are informational and do not need to be filled completely.</div>
<?php else: ?>
<div class="alert alert-success"><strong>Timeline complete.</strong> Canonical assignment periods cover the timeline without a detected gap or overlap.</div>
<?php endif; ?>

<div class="card mb-4"><div class="card-body">
  <h2 class="h5 mb-3">Canonical Appointment Timeline</h2>
  <div class="table-responsive"><table class="table table-bordered align-middle">
    <thead><tr><th>Period</th><th>Officer / Appointment</th><th>Status</th><th>Workflow / Source</th><th>Action</th></tr></thead>
    <tbody>
    <?php if(!$entries): ?><tr><td colspan="5" class="text-center text-muted">No appointment timeline information is available.</td></tr><?php endif; ?>
    <?php foreach($entries as $entry): ?>
      <?php if($entry['entry_kind']==='MISSING_PERIOD'): ?>
        <tr class="table-warning">
          <td><strong><?= $date($entry['effective_from'],$dash) ?></strong> to <strong><?= $date($entry['effective_to'],'Open') ?></strong></td>
          <td><span class="badge text-bg-warning">Uncovered Period</span><div class="small mt-1">No canonical ARPA appointment covers this period.</div></td>
          <td>Informational uncovered period from the 2025-01-01 reporting baseline</td>
          <td>Resolve any Data Issues first, then add only evidence-backed history.</td>
          <td><?php if($can_add_historical): ?><a class="btn btn-sm btn-warning" href="<?= e(url('hr/arpa-appointments/new?'.http_build_query(['asc_location_id'=>$division['asc_location_id'],'arpa_division_location_id'=>$division['id'],'effective_from'=>$entry['effective_from'],'effective_to'=>$entry['effective_to']]))) ?>">Add Historical Appointment</a><?php else: ?><span class="text-muted">View only</span><?php endif; ?></td>
        </tr>
      <?php elseif($entry['entry_kind']==='DATA_ISSUE'): $presentation=ArpaAppointmentIssuePresentation::for((string)$entry['issue_type']); $reconciliation=!empty($entry['reconciliation_item_id']); $correctable=$can_correct&&in_array((string)$entry['issue_type'],ArpaAppointmentDataIssueCorrectionService::CORRECTABLE_ISSUES,true); ?>
        <tr class="table-danger">
          <td><?= $date($entry['effective_from'],$dash) ?> to <?= $date($entry['effective_to'],'Open') ?></td>
          <td><span class="badge text-bg-danger">Appointment Data Issue</span><div class="fw-semibold mt-1"><?= e($presentation['title']) ?></div><div class="small"><?= e($entry['officer_number'].' - '.$entry['officer_name']) ?></div></td>
          <td>Unresolved</td>
          <td><?= e($presentation['explanation']) ?><div class="small text-muted mt-1"><?= e((string)$entry['appointment_types']) ?> / <?= e((string)$entry['effective_periods']) ?></div><div class="small mt-1">Reference: <code><?= e((string)$entry['row_key']) ?></code></div></td>
          <td><a class="btn btn-sm btn-outline-danger" href="<?= e(url($reconciliation?'hr/arpa-appointments/legacy-review/items/'.$entry['reconciliation_item_id']:'hr/arpa-appointments/issues/'.rawurlencode((string)$entry['row_key']))) ?>"><?= $correctable?'Review / Correct Data':'View Issue' ?></a></td>
        </tr>
      <?php else:
        $isReservation=$entry['source_kind']==='RESERVATION';
        $isFuture=$entry['effective_from']>date('Y-m-d');
        $isEnded=$entry['effective_to']!==null&&$entry['effective_to']<date('Y-m-d');
        $state=$isReservation?'Reserved / '.$typeLabel($entry['workflow_status']):($isEnded?'Historical / Ended':($isFuture?'Scheduled':'Current'));
      ?>
        <tr class="<?= !empty($entry['overlap'])?'table-warning':'' ?>">
          <td><strong><?= $date($entry['effective_from'],$dash) ?></strong> to <strong><?= $date($entry['effective_to'],'Open') ?></strong></td>
          <td><div class="fw-semibold"><?= e($entry['officer_number'].' - '.$entry['officer_name']) ?></div><div class="small text-muted"><?= e($entry['nic']?:$dash) ?></div><span class="badge text-bg-secondary mt-1"><?= e($typeLabel($entry['appointment_type'])) ?></span></td>
          <td><?= DataTableFormat::badge($state) ?><?= !empty($entry['overlap'])?' '.DataTableFormat::badge('Overlap'):'' ?><?= !empty($entry['legacy_exception'])?' '.DataTableFormat::badge('Legacy Exception'):'' ?></td>
          <td><div><?= e($entry['workflow_status']?$typeLabel($entry['workflow_status']):'Operational') ?></div><div class="small text-muted"><?= e($typeLabel($entry['record_origin']).' / '.$typeLabel($entry['source_kind'])) ?></div><?php if($entry['end_reason']): ?><div class="small">End reason: <?= e($entry['end_reason']) ?></div><?php endif; ?></td>
          <td><?php if($entry['appointment_id']): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('hr/arpa-appointments/divisions/'.$entry['appointment_id'])) ?>">View Appointment</a><?php else: ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('hr/arpa-appointments/requests/division/'.$entry['request_id'])) ?>">View Request</a><?php endif; ?></td>
        </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div></div>

<div class="alert alert-secondary mb-0"><strong>Canonical timeline:</strong> uncovered periods are reported from 01 Jan 2025 but are allowed. Appointment Data Issues must still be completed before a normal New Assignment can be submitted. This page is read-only and uses the existing correction workflow.</div>
