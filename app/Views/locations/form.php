<?php use App\Core\Csrf; ?>
<div class="page-heading"><div><div class="breadcrumb-lite">Organization / Locations / Add</div><h1>Add Location</h1></div></div>
<form method="post" action="<?= e(url('locations')) ?>"><?= Csrf::field() ?><div class="form-section"><div class="row g-3">
<div class="col-md-4"><label class="form-label">Location Type *</label><select class="form-select" name="location_type_id" required><option value="">Select</option><?php foreach($types as $r): ?><option value="<?= e($r['id']) ?>"><?= e($r['name_en']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Official Code</label><input class="form-control" name="official_code"></div><div class="col-md-4"><label class="form-label">Start Date *</label><input type="date" class="form-control" name="effective_from" value="<?= date('Y-m-d') ?>" required></div>
<div class="col-md-4"><label class="form-label">English Name *</label><input class="form-control" name="name_en" required></div><div class="col-md-4"><label class="form-label">Sinhala Name</label><input class="form-control" name="name_si"></div><div class="col-md-4"><label class="form-label">Tamil Name</label><input class="form-control" name="name_ta"></div>
</div></div><button class="btn btn-primary">Submit</button> <a class="btn btn-outline-secondary" href="<?= e(url('locations')) ?>">Cancel</a></form>
