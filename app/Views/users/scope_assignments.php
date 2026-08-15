<?php use App\Core\Csrf; ?>
<div class="page-heading"><div><div class="breadcrumb-lite">User Management / Scope Assignments</div><h1>Scope Assignments</h1><p>National, District, ASC and ARPA geographic access attached to an approved role assignment.</p></div><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignScope"><i class="bi bi-plus-lg"></i> Assign Scope</button></div>
<?php require BASE_PATH . '/app/Views/components/datatable.php'; ?>
<div class="modal fade" id="assignScope" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" method="post" action="<?= e(url('access-management/scope-assignments')) ?>">
    <?= Csrf::field() ?>
    <div class="modal-header"><h5 class="modal-title">Assign Scope</h5><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-12"><label class="form-label">Approved Role Assignment *</label><select class="form-select" name="role_assignment_id" required><option value="">Select</option><?php foreach ($roleAssignments as $assignment): ?><option value="<?= e($assignment['id']) ?>"><?= e($assignment['username'] . ' | ' . $assignment['role_level'] . ' | ' . $assignment['role_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Scope Type</label><select class="form-select" name="scope_type"><option>NATIONAL</option><option>DISTRICT</option><option>ASC</option><option>ARPA_DIVISION</option><option>PROVINCE</option><option>DS_DIVISION</option><option>GN_DIVISION</option></select></div>
            <div class="col-md-4"><label class="form-label">Mode</label><select class="form-select" name="scope_mode"><option>EXACT</option><option>INCLUDE_CHILDREN</option><option>NATIONAL</option></select></div>
            <div class="col-md-4"><label class="form-label">Effective From</label><input type="date" class="form-control" name="effective_from" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-12"><label class="form-label">Location Target</label><select class="form-select" name="location_id"><option value="">National / None</option><?php foreach ($locations as $location): ?><option value="<?= e($location['id']) ?>"><?= e($location['type_key'] . ' | ' . $location['dad_number'] . ' | ' . $location['name_en']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Effective To</label><input type="date" class="form-control" name="effective_to"></div>
        </div>
        <div class="alert alert-info mt-3 mb-0">Scope type is validated against the selected role level. ARPA Officer supports multiple separate ARPA scope rows.</div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Create Draft</button></div>
</form></div></div>
