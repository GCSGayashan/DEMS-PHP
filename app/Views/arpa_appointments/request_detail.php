<?php
use App\Core\{Auth,Csrf,DataTableFormat};
use App\Services\ArpaAppointmentRules;
use App\Services\ArpaWorkflowQueuePolicy;
$status=$request['workflow_status'];
$hasCanonical=$entity==='division'&&!empty($request['canonical_appointment_id']);
$hasEndClosure=$entity==='division'&&!empty($request['materialized_end_closure']);
$operationalStatus=$hasCanonical
    ?(!empty($request['canonical_effective_to'])&&$request['canonical_effective_to']<date('Y-m-d')?'ENDED':(($request['canonical_effective_from']??'')>date('Y-m-d')?'SCHEDULED':'CURRENT'))
    :($hasEndClosure
        ?(!empty($request['materialized_end_effective_to'])&&$request['materialized_end_effective_to']<date('Y-m-d')?'ENDED':(($request['source_effective_from']??'')>date('Y-m-d')?'SCHEDULED':'CURRENT'))
        :'RESERVATION');
$originLabel=($hasCanonical||$hasEndClosure)?'Native Appointment':'Native Workflow / Reservation';
$administrativelyDeleted=$entity==='division'&&($request['deleted_at']??null)!==null;
$step=match($status){
    'CREATED'=>['SUBMIT','CREATOR','arpa.appointment.submit','Submit'],
    'RETURNED'=>['SUBMIT','CREATOR','arpa.appointment.submit','Resubmit'],
    'SUBMITTED'=>['VERIFY','ASC','arpa.appointment.asc-verify','ASC Verify'],
    'ASC_VERIFIED'=>['APPROVE','ASC','arpa.appointment.asc-approve','ASC Approve'],
    'ASC_APPROVED'=>['VERIFY','DISTRICT','arpa.appointment.district-verify','District Verify'],
    'DISTRICT_VERIFIED'=>['APPROVE','DISTRICT','arpa.appointment.district-approve','District Approve'],
    'DISTRICT_APPROVED'=>['VERIFY','NATIONAL','arpa.appointment.national-verify','National Verify'],
    'NATIONAL_VERIFIED'=>['APPROVE','NATIONAL','arpa.appointment.national-approve','Final Approve'],default=>null};
$actor=(string)(Auth::user()['id']??'');
$canCorrectReturned=$status==='RETURNED'&&(new ArpaWorkflowQueuePolicy(\App\Core\Database::pdo()))->canCorrectReturnedRequest($actor,(string)$request['asc_location_id']);
$canEditSubmitted=$entity==='division'&&$status==='SUBMITTED'&&$actor===(string)$request['created_by'];
$canPrimary=$step&&Auth::can($step[2])&&($step[0]!=='SUBMIT'||($status==='CREATED'?$actor===(string)$request['created_by']:$canCorrectReturned));
$reviewStage=ArpaAppointmentRules::isReviewStatus($status)?ArpaAppointmentRules::reviewStage($status):null;
$stageRolePermission=match($status){'SUBMITTED'=>'arpa.appointment.asc-verify','ASC_VERIFIED'=>'arpa.appointment.asc-approve','ASC_APPROVED'=>'arpa.appointment.district-verify','DISTRICT_VERIFIED'=>'arpa.appointment.district-approve','DISTRICT_APPROVED'=>'arpa.appointment.national-verify','NATIONAL_VERIFIED'=>'arpa.appointment.national-approve',default=>null};
$canReturn=$stageRolePermission!==null&&Auth::can('arpa.appointment.return')&&Auth::can($stageRolePermission);
$canReject=$stageRolePermission!==null&&Auth::can('arpa.appointment.reject')&&Auth::can($stageRolePermission);
$expandedDivisionReviewPage=$entity==='division'&&in_array($status,['ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED'],true);
$workflowPanelTitle=match($status){'SUBMITTED'=>'ASC Verification','ASC_VERIFIED'=>'ASC Approval','ASC_APPROVED'=>'District Verification','DISTRICT_VERIFIED'=>'District Approval','DISTRICT_APPROVED'=>'National Verification','NATIONAL_VERIFIED'=>'National Approval',default=>'Workflow action'};
$editPermission=$entity==='subject'?'arpa.subject.create':'arpa.appointment.edit';
if($administrativelyDeleted){$canPrimary=false;$canReturn=false;$canReject=false;$canCorrectReturned=false;$canEditSubmitted=false;}
$lastReturn=null;for($i=count($workflowHistory)-1;$i>=0;$i--){if(in_array($workflowHistory[$i]['action'],['RETURN_FOR_CORRECTION','REJECT'],true)){$lastReturn=$workflowHistory[$i];break;}}
?>
<div class="page-heading"><div><div class="breadcrumb-lite">ARPA Officer Assignments / Review</div><h1><?= e(ucwords($entity).' '.ucwords(strtolower(str_replace('_',' ',$request['request_type'])))) ?> Request</h1><p>Check the officer, location, dates, and other assignment information.</p></div><div class="text-end"><div><span class="small text-muted">Operational Status</span> <?= DataTableFormat::badge($operationalStatus) ?></div><div class="mt-1"><span class="small text-muted">Approval Status</span> <?= DataTableFormat::badge($status) ?></div><div class="small text-muted mt-1"><?= e($originLabel) ?></div></div></div>
<?php if($administrativelyDeleted): ?><div class="alert alert-secondary"><strong>ADMINISTRATIVELY DELETED</strong><br>This request is retained for audit visibility and is no longer actionable. Reason: <?= e($request['delete_reason']?:'Not recorded') ?></div><?php endif; ?>
<?php if($status==='RETURNED'&&$lastReturn): ?><div class="alert alert-warning"><div class="d-flex flex-wrap justify-content-between gap-2"><strong>RETURNED FOR CORRECTION</strong><span><?= $lastReturn['action_at']?e(substr((string)$lastReturn['action_at'],0,16)):'Unavailable from legacy source' ?></span></div><div class="mt-2"><strong>Returned by:</strong> <?= e($lastReturn['performed_by']) ?> &middot; <strong>Level:</strong> <?= e($lastReturn['stage']) ?></div><div class="mt-1"><strong>Reason:</strong> <?= nl2br(e($lastReturn['comments'])) ?></div></div><?php endif; ?>
<div class="row g-3"><div class="<?= $expandedDivisionReviewPage?'col-lg-8':'col-lg-7' ?>"><div class="form-section"><h2 class="h5">Original Agrarian Service Center Request</h2>
<?php if($expandedDivisionReviewPage): ?>
<h3 class="h6 mt-3">Officer Information</h3><dl class="row mb-0">
<dt class="col-sm-4">Officer</dt><dd class="col-sm-8"><?= e($request['officer_number'].' - '.($request['officer_name']?:'Unnamed')) ?></dd>
<dt class="col-sm-4">NIC</dt><dd class="col-sm-8"><?= e($request['officer_nic']?:'-') ?></dd>
<dt class="col-sm-4">Designation</dt><dd class="col-sm-8"><?= e($request['designation_name']?:'-') ?></dd>
<dt class="col-sm-4">Class</dt><dd class="col-sm-8"><?= e($request['class_name']?:'-') ?></dd>
<dt class="col-sm-4">Officer Status / Service Status</dt><dd class="col-sm-8"><?= e($request['officer_status_name']?:$request['officer_operational_status']?:'-') ?></dd>
<dt class="col-sm-4">Officer Office</dt><dd class="col-sm-8"><?= e($request['office_name']?trim(($request['office_dad_number']?$request['office_dad_number'].' - ':'').$request['office_name']):'-') ?></dd>
</dl><h3 class="h6 mt-4">Assignment Information</h3><dl class="row mb-0">
<dt class="col-sm-4">Province</dt><dd class="col-sm-8"><?= e($request['province_name']?:'-') ?></dd>
<dt class="col-sm-4">District</dt><dd class="col-sm-8"><?= e($request['district_name']?:'-') ?></dd>
<dt class="col-sm-4">Agrarian Service Center</dt><dd class="col-sm-8"><?= e($request['asc_name']?trim(($request['asc_number']?$request['asc_number'].' - ':'').$request['asc_name']):'-') ?></dd>
<dt class="col-sm-4">ARPA Division</dt><dd class="col-sm-8"><?= e($request['arpa_name']?trim((($request['arpa_number']?:$request['arpa_official_code'])?($request['arpa_number']?:$request['arpa_official_code']).' - ':'').$request['arpa_name']):'-') ?></dd>
<dt class="col-sm-4">Request Type</dt><dd class="col-sm-8"><?= e(ucwords(strtolower(str_replace('_',' ',$request['request_type'])))) ?></dd>
<dt class="col-sm-4">Assignment Type</dt><dd class="col-sm-8"><?= e(ucwords(strtolower(str_replace('_',' ',$request['appointment_type']?:'-')))) ?></dd>
<dt class="col-sm-4">Start Date</dt><dd class="col-sm-8"><?= e($request['requested_effective_from']?:'-') ?></dd>
<dt class="col-sm-4">End Date</dt><dd class="col-sm-8"><?= e($request['requested_effective_to']?:'Current') ?></dd>
<dt class="col-sm-4">Remarks</dt><dd class="col-sm-8"><?= $request['request_remarks']?nl2br(e($request['request_remarks'])):'-' ?></dd>
<dt class="col-sm-4">Submitted By</dt><dd class="col-sm-8"><?= e($request['submitted_by_name']?:'-') ?></dd>
<dt class="col-sm-4">Submitted Date</dt><dd class="col-sm-8"><?= e($request['submitted_at']?substr((string)$request['submitted_at'],0,16):'-') ?></dd>
</dl>
<?php else: ?><dl class="row mb-0"><dt class="col-sm-4">Officer</dt><dd class="col-sm-8"><?= e($request['officer_number'].' - '.($request['officer_name']?:'Unnamed')) ?></dd><dt class="col-sm-4">Request Type</dt><dd class="col-sm-8"><?= e(ucwords(strtolower(str_replace('_',' ',$request['request_type'])))) ?></dd><?php if(isset($request['appointment_type'])): ?><dt class="col-sm-4">Assignment Type</dt><dd class="col-sm-8"><?= e(ucwords(strtolower(str_replace('_',' ',$request['appointment_type']?:'—')))) ?></dd><?php endif; ?><dt class="col-sm-4">Start Date</dt><dd class="col-sm-8"><?= e($request['requested_effective_from']?:'—') ?></dd><dt class="col-sm-4">End Date</dt><dd class="col-sm-8"><?= e($request['requested_effective_to']?:'Current') ?></dd><dt class="col-sm-4">Remarks</dt><dd class="col-sm-8"><?= e($request['request_remarks']?:'—') ?></dd></dl><?php endif; ?></div>
<?php if($impact): ?><div class="alert alert-warning"><h2 class="h6">Transfer / Permanent closure impact</h2><p class="mb-2">Final approval will create <?= count($impact) ?> separate dependent closure event<?= count($impact)===1?'':'s' ?>. No dependent assignment is carried forward silently.</p><ul class="mb-0"><?php foreach($impact as $row): ?><li><?= e(($row['appointment_type']??'Assignment').' — '.($row['arpa_name_snapshot']??$row['id']??'Unknown')) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
</div><div class="<?= $expandedDivisionReviewPage?'col-lg-4':'col-lg-5' ?>"><div class="form-section"><h2 class="h5"><?= e($workflowPanelTitle) ?></h2>
<?php if($canPrimary): ?><form method="post" action="<?= e(url('hr/arpa-appointments/workflow/'.$entity.'/'.$request['id'].'/'.strtolower($step[0]).'?stage='.$step[1])) ?>"><?= Csrf::field() ?><label class="form-label" for="primary-comments">Comments / remarks</label><textarea class="form-control mb-3" id="primary-comments" name="comments" rows="3"></textarea><button class="btn btn-primary"><?= e($step[3]) ?></button></form><?php else: ?><p class="text-muted">No primary workflow action is available to your account at this stage.</p><?php endif; ?>
<?php if($canReturn||$canReject): ?><hr><div class="row g-2"><?php if($canReturn): ?><div class="col-12"><form method="post" action="<?= e(url('hr/arpa-appointments/workflow/'.$entity.'/'.$request['id'].'/return_for_correction?stage='.$reviewStage)) ?>"><?= Csrf::field() ?><label class="form-label">Correction comments</label><textarea class="form-control mb-2" name="comments" rows="2" required></textarea><button class="btn btn-outline-warning">Return for Correction</button></form></div><?php endif; ?><?php if($canReject): ?><div class="col-12 mt-3"><form method="post" action="<?= e(url('hr/arpa-appointments/workflow/'.$entity.'/'.$request['id'].'/reject?stage='.$reviewStage)) ?>"><?= Csrf::field() ?><label class="form-label">Rejection reason</label><textarea class="form-control mb-2" name="comments" rows="2" required></textarea><button class="btn btn-outline-danger">Reject</button></form></div><?php endif; ?></div><?php endif; ?>
<?php if((($status==='CREATED'&&$actor===(string)$request['created_by'])||$canCorrectReturned||$canEditSubmitted)&&Auth::can($editPermission)): ?><hr><a class="btn btn-outline-secondary" href="<?= e(url('hr/arpa-appointments/requests/'.$entity.'/'.$request['id'].'/edit')) ?>"><?= $canEditSubmitted?'Edit Submitted Appointment':'Edit / Correct Request' ?></a><?php endif; ?>
</div></div></div>
<div class="form-section mt-3"><h2 class="h5">Stage review information</h2><?php if(!$stageReviews): ?><p class="text-muted mb-0">No District or National review information has been entered.</p><?php else: ?><div class="row g-3"><?php foreach($stageReviews as $review): ?><div class="col-lg-6"><div class="border rounded p-3 h-100"><div class="d-flex justify-content-between"><strong><?= e(ucfirst(strtolower($review['review_stage']))) ?> Review</strong><span class="text-muted small"><?= e(substr($review['updated_at'],0,16)) ?></span></div><div class="mt-2"><?= nl2br(e($review['review_information']?:'No review information.')) ?></div><?php if($review['remarks']): ?><div class="text-muted mt-2"><?= nl2br(e($review['remarks'])) ?></div><?php endif; ?><div class="small text-muted mt-2">Last updated by <?= e($review['updated_by_name']) ?></div></div></div><?php endforeach; ?></div><?php endif; ?></div>
<div class="form-section mt-3"><h2 class="h5">Workflow History</h2><?php if(!$workflowHistory): ?><p class="text-muted mb-0">No actions recorded yet.</p><?php else: ?><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Action</th><th>Stage / Level</th><th>Performed By</th><th>Date/Time</th><th>Status Change</th><th>Reason / Comments</th></tr></thead><tbody><?php foreach($workflowHistory as $w): ?><tr><td><?= e(str_replace('_',' ',$w['action'])) ?></td><td><?= e($w['stage']) ?></td><td><?= e($w['performed_by']) ?></td><td><?= $w['action_at']?e(substr((string)$w['action_at'],0,16)):'<span class="text-muted">Unavailable from legacy source</span>' ?></td><td><?= e($w['previous_status'].' -> '.$w['new_status']) ?></td><td><?= $w['comments']?nl2br(e($w['comments'])):'<span class="text-muted">No comments</span>' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
