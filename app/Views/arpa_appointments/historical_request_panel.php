<?php
use App\Core\Csrf;

$detail=$historicalPanel['detail']??null;
$case=$detail['case']??null;
$baseline=$detail['baseline']??null;
$historicalStatus=$case['workflow_status']??'Unavailable';
$actions=$detail['actions']??[];
$nextAction=$detail['next_action']??null;
$events=$detail['events']??[];
$emptyProgress=array_fill_keys(['ASC','DISTRICT','NATIONAL'],['VERIFY'=>null,'APPROVE'=>null]);
$progress=$emptyProgress;
foreach($events as $event){
    if(($event['approval_case_id']??null)!==($case['id']??null))continue;
    if(($event['action']==='SUBMIT'&&$event['stage']==='CREATOR'&&$event['new_status']==='SUBMITTED')
        ||($event['action']==='RETURN_FOR_CORRECTION'&&$event['new_status']==='RETURNED')){$progress=$emptyProgress;continue;}
    $stage=$event['stage'];$action=$event['action'];
    if(isset($progress[$stage])&&in_array($action,['VERIFY','APPROVE'],true)
        &&$event['new_status']===$stage.($action==='VERIFY'?'_VERIFIED':'_APPROVED'))$progress[$stage][$action]=$event;
}
?>
<section class="form-section"><h2 class="h5">Historical Appointment Approval</h2>
<dl class="row">
<dt class="col-sm-5">Officer</dt><dd class="col-sm-7"><?= e($request['officer_number'].' - '.$request['officer_name']) ?></dd>
<dt class="col-sm-5">Appointment Start Date</dt><dd class="col-sm-7"><?= e($baseline['appointment']['effective_from']??$request['canonical_effective_from']??$request['requested_effective_from']??'-') ?></dd>
<dt class="col-sm-5">Existing Closure / End Date</dt><dd class="col-sm-7"><?= e($baseline['closures'][0]['effective_to']??$request['canonical_effective_to']??'Open / no closure recorded') ?></dd>
<dt class="col-sm-5">Original Workflow Status</dt><dd class="col-sm-7"><?= e($status) ?></dd>
<dt class="col-sm-5">Historical Approval Status</dt><dd class="col-sm-7"><?= e($historicalStatus) ?></dd>
</dl>
<?php if(!$detail): ?>
<p class="text-muted"><?= e($historicalPanel['message']??'Historical approval details are unavailable in your current Working Context.') ?></p>
<?php else: ?>
<p class="small text-muted">This case covers the existing appointment and its recorded closure together.</p>
<?php if($nextAction!==null): ?>
<div class="border-top border-bottom py-3 mb-3" data-historical-next-action><h3 class="h6">Next Workflow Action</h3>
<dl class="row mb-0"><dt class="col-sm-5">Current Historical Status</dt><dd class="col-sm-7"><?= e($historicalStatus) ?></dd>
<dt class="col-sm-5">Next Action</dt><dd class="col-sm-7"><?= e($nextAction['label']) ?></dd>
<dt class="col-sm-5">Responsible Stage</dt><dd class="col-sm-7"><?= e($nextAction['stage']??'None') ?></dd>
<dt class="col-sm-5">Responsible Role</dt><dd class="col-sm-7"><?= e($nextAction['role']??'None') ?></dd>
<dt class="col-sm-5">Current User</dt><dd class="col-sm-7"><?= $nextAction['action']===null?'No further workflow action is available.':(!$administrativelyDeleted&&!$detail['source_stale']&&$nextAction['authorized']?'Authorized to perform this action.':'Not authorized to perform this action in the current case / Working Context.') ?></dd>
</dl></div>
<?php endif; ?>
<p class="small text-muted">Current review cycle</p><ul class="list-unstyled">
<?php foreach($progress as $stage=>$decisions): ?>
<li class="mb-2" data-historical-stage="<?= e($stage) ?>"><strong><?= e($stage) ?> review:</strong>
<?php foreach($decisions as $action=>$decision): ?><div><?= $action==='VERIFY'?'Verification':'Approval' ?>: <?= $decision?e(($action==='VERIFY'?'Verified':'Approved').' - '.($detail['display']['actors'][$decision['user_id']]??$decision['user_id']).' - '.$decision['action_at']):'Not recorded' ?></div><?php endforeach; ?>
</li>
<?php endforeach; ?>
</ul>
<?php if($detail['source_stale']): ?><div class="alert alert-warning">The source baseline has changed or cannot be validated. Existing-record attestation is unavailable; authorized source-baseline review is required.</div><?php endif; ?>
<?php if(!empty($detail['action_blocker'])): ?><p class="text-danger"><?= e($detail['action_blocker']) ?></p><?php endif; ?>
<?php if(!$administrativelyDeleted&&!$detail['source_stale']&&(!empty($actions['submit'])||!empty($actions['primary'])||!empty($actions['return'])||!empty($actions['reject']))): ?>
<?php $allActions=$actions;$formGroups=($actions['primary']??null)==='VERIFY'
    ? [array_replace($actions,['return'=>false,'reject'=>false]),array_replace($actions,['primary'=>null])]:[$actions];
foreach($formGroups as $formIndex=>$actions):
if(empty($actions['submit'])&&empty($actions['primary'])&&empty($actions['return'])&&empty($actions['reject']))continue;
$optionalReason=($actions['primary']??null)==='VERIFY'; ?>
<form method="post" action="<?= e(url('hr/arpa-appointments/historical-approvals/'.$case['id'].'/attest')) ?>">
<?= Csrf::field() ?>
<input type="hidden" name="expected_version" value="<?= e($case['version']) ?>">
<input type="hidden" name="baseline_hash" value="<?= e($case['baseline_hash']) ?>">
<?php if(empty($actions['submit'])): ?><label class="form-label" for="historical-reason-<?= $formIndex ?>">Decision reason <?= $optionalReason?'(optional)':'*' ?></label><textarea id="historical-reason-<?= $formIndex ?>" class="form-control mb-3" name="reason" maxlength="1000" <?= $optionalReason?'':'required' ?>></textarea><?php endif; ?>
<div class="form-check mb-3"><input class="form-check-input" id="historical-confirm-records-<?= $formIndex ?>" type="checkbox" name="confirm_records" value="1" required><label class="form-check-label" for="historical-confirm-records-<?= $formIndex ?>">I have checked the existing imported records, appointment dates and closure.</label></div>
<?php if(!empty($actions['final'])): ?><div class="form-check mb-3"><input class="form-check-input" id="historical-confirm-final" type="checkbox" name="confirm_final" value="1" required><label class="form-check-label" for="historical-confirm-final">I confirm final National approval of this existing appointment and closure.</label></div><?php endif; ?>
<div class="d-flex flex-wrap gap-2">
<?php if(!empty($actions['submit'])): ?><button class="btn btn-primary" name="action" value="SUBMIT"><?= e($nextAction['label']??'Submit Historical Appointment') ?></button><?php endif; ?>
<?php if(!empty($actions['primary'])): ?><button class="btn btn-primary" name="action" value="<?= e($actions['primary']) ?>"><?= e($nextAction['label']??ucfirst(strtolower($actions['primary'])).' Historical Appointment') ?></button><?php endif; ?>
<?php if(!empty($actions['return'])): ?><button class="btn btn-outline-warning" name="action" value="RETURN_FOR_CORRECTION">Return for Correction</button><?php endif; ?>
<?php if(!empty($actions['reject'])): ?><button class="btn btn-outline-danger" name="action" value="REJECT">Reject Historical Appointment</button><?php endif; ?>
</div></form>
<?php endforeach;$actions=$allActions; ?>
<?php else: ?><p class="text-muted">No historical approval action is available to your account at this stage.</p><?php endif; ?>
<details class="mt-3"><summary>Historical review audit and baseline</summary><p class="small text-break mt-2">Reviewed baseline: <?= e($case['baseline_hash']) ?></p>
<?php foreach($events as $event): $audit=json_decode($event['after_json'],true); ?>
<div class="border-top py-2 small"><strong><?= e($event['stage'].' / '.$event['action']) ?></strong><br><?= e(($detail['display']['actors'][$event['user_id']]??$event['user_id']).' - '.$event['action_at']) ?><br><?= e($event['reason']) ?>
<?php if(isset($audit['existing_record_attestation'])): ?><br>Existing-record attestation: <?= e($audit['existing_record_attestation']['statement']) ?><br><span class="text-break"><?= e($audit['existing_record_attestation']['baseline_hash']) ?></span><?php endif; ?>
</div><?php endforeach; ?></details>
<?php endif; ?>
</section>
