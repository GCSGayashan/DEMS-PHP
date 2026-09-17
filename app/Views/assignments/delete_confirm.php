<div class="page-heading">
    <div>
        <div class="breadcrumb-lite">Administration / Assignment Correction</div>
        <h1>Delete Assignment</h1>
        <p>This action immediately revokes the assignment while retaining its audit history.</p>
    </div>
</div>

<div class="alert alert-warning">
    This restricted correction is available only to the DEMS operational administrator. It does not run Submit, Review, or Approval workflow actions.
</div>

<div class="card mb-4">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-md-4">Assignment Type</dt><dd class="col-md-8"><?= e($assignmentType) ?></dd>
            <dt class="col-md-4">Officer / User</dt><dd class="col-md-8"><?= e($subjectLabel) ?></dd>
            <dt class="col-md-4">Current Assignment</dt><dd class="col-md-8"><?= e($assignmentLabel) ?></dd>
            <dt class="col-md-4">Effective From</dt><dd class="col-md-8"><?= \App\Core\DataTableFormat::date($assignment['effective_from']??null,'—') ?></dd>
            <dt class="col-md-4">Effective To</dt><dd class="col-md-8"><?= \App\Core\DataTableFormat::date($assignment['effective_to']??null,'Current') ?></dd>
            <dt class="col-md-4">Approval Status</dt><dd class="col-md-8"><?= \App\Core\DataTableFormat::badge((string)($assignment['approval_status']??'UNKNOWN')) ?></dd>
            <dt class="col-md-4">Active Status</dt><dd class="col-md-8"><?= \App\Core\DataTableFormat::badge(!empty($assignment['active'])?'ACTIVE':'INACTIVE') ?></dd>
        </dl>
    </div>
</div>

<form method="post" action="<?= e(url($postUrl)) ?>" class="card">
    <div class="card-body">
        <?= \App\Core\Csrf::field() ?>
        <div class="mb-3">
            <label class="form-label" for="delete_reason">Delete Reason <span class="text-danger">*</span></label>
            <textarea class="form-control" id="delete_reason" name="delete_reason" rows="3" maxlength="500" required></textarea>
            <div class="form-text">Explain why this assignment is erroneous or duplicated. This reason is retained in the audit history.</div>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" value="1" id="confirm_delete" name="confirm_delete" required>
            <label class="form-check-label" for="confirm_delete">Delete this assignment</label>
        </div>
        <button class="btn btn-danger" type="submit">Delete Assignment</button>
        <a class="btn btn-outline-secondary" href="<?= e(url($cancelUrl)) ?>">Cancel</a>
    </div>
</form>
