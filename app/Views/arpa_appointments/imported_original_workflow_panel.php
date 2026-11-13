<?php use App\Core\Csrf; ?>
<section class="form-section"><h2 class="h5"><?= e($workflowPanelTitle) ?></h2>
<dl class="row"><dt class="col-sm-5">Original Workflow Status</dt><dd class="col-sm-7"><?= e($status) ?></dd>
<dt class="col-sm-5">Next Action</dt><dd class="col-sm-7"><?= e($step[3]??'Original Approval Completed') ?></dd>
<dt class="col-sm-5">Appointment Start</dt><dd class="col-sm-7"><?= e($request['requested_effective_from']) ?></dd>
<dt class="col-sm-5">Recorded Closure</dt><dd class="col-sm-7"><?= e($request['requested_effective_to']?:'Open') ?></dd></dl>
<?php if(!empty($importedWorkflow['allowed'])&&$step&&!$administrativelyDeleted): ?>
<form method="post" action="<?= e(url('hr/arpa-appointments/workflow/division/'.$request['id'].'/'.strtolower($step[0]).'?stage='.$step[1])) ?>"><?= Csrf::field() ?>
<label class="form-label" for="imported-review-comments">Comments / remarks</label><textarea class="form-control mb-3" id="imported-review-comments" name="comments" rows="3"></textarea>
<button class="btn btn-primary"><?= e($step[3]) ?></button></form>
<?php else: ?><p class="text-muted"><?= e($importedWorkflow['blocker']??'No original workflow action is available in your current Working Context.') ?></p><?php endif; ?>
<p class="small text-muted mt-3">Any separate Historical Approval case is retained for audit only and cannot authorize this request.</p>
<?php if(!empty($historicalPanel['detail'])): $auditCase=$historicalPanel['detail']; ?>
<details><summary>Separate Historical Case (Audit Only)</summary><p class="small text-break"><?= e($auditCase['case']['id'].' / '.$auditCase['case']['workflow_status']) ?></p>
<p class="small text-break">Recorded historical baseline: <?= e($auditCase['case']['baseline_hash']) ?></p>
<?php foreach($auditCase['events'] as $event): ?><div class="border-top py-2 small"><?= e($event['stage'].' / '.$event['action'].' / '.$event['action_at']) ?><br><?= e(($auditCase['display']['actors'][$event['user_id']]??$event['user_id']).' / '.$event['reason']) ?></div><?php endforeach; ?></details>
<?php endif; ?></section>
