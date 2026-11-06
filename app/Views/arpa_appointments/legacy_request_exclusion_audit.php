<div class="page-heading"><div><h1>Legacy Request Exclusion Audit</h1><p>Read-only administrative history. Source requests remain stored. Restoration is not offered because current timelines and dependencies must be reconciled before reinstatement.</p></div></div>
<?php if(!$rows): ?><div class="alert alert-info">No legacy request exclusions have been recorded.</div><?php endif; ?>
<?php foreach($rows as $row): $d=$row['details']; ?><div class="card mb-3"><div class="card-body">
<h2 class="h5"><a href="<?= e(url('hr/arpa-appointments/legacy-requests/'.$row['request_id'].'/exclusion')) ?>"><?= e($row['request_id']) ?></a></h2>
<dl class="row"><dt class="col-sm-4">Administrator / recorded at</dt><dd class="col-sm-8"><?= e(($row['administrator']?:$row['actor_user_id']).' / '.$row['created_at']) ?></dd>
<dt class="col-sm-4">Reason</dt><dd class="col-sm-8"><?= e($d['delete_reason']) ?></dd>
<dt class="col-sm-4">Evidence reference</dt><dd class="col-sm-8"><?= e($d['evidence_reference']) ?></dd></dl>
<details><summary>Preserved request, provenance and dependency evidence</summary><pre class="text-wrap"><?= e(json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre></details>
</div></div><?php endforeach; ?>
