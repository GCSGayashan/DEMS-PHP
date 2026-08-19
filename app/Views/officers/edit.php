<?php
use App\Core\Csrf;

$v = static fn(string $key, mixed $default = '') =>
    $officer[$key] ?? $default;
?>
<div class="page-heading">
    <div>
        <div>
            <div class="breadcrumb-lite">
                Human Resource Management / Officers / Edit
            </div>
            <h1>Edit Officer</h1>
            <p>
                <?= e($officer['dad_number'].' - '.$officer['name_with_initials']) ?>
            </p>
        </div>
    </div>
</div>

<form
    method="post"
    enctype="multipart/form-data"
    action="<?= e(url('hr/officers/'.$officer['id'].'/edit')) ?>"
>
<?= Csrf::field() ?>

<div class="form-section">
<h5>Identity</h5>

<div class="row g-3">

<div class="col-md-3">
<label class="form-label">NIC *</label>
<input
    class="form-control"
    name="nic"
    value="<?= e($v('nic')) ?>"
    required
>
</div>

<div class="col-md-3">
<label class="form-label">Employee Number</label>
<input
    class="form-control"
    name="employee_number"
    value="<?= e($v('employee_number')) ?>"
>
</div>

<div class="col-md-3">
<label class="form-label">Title *</label>
<select class="form-select" name="title_id" required>
<option value="">Select</option>
<?php foreach ($titles as $r): ?>
<option
    value="<?= e($r['id']) ?>"
    <?= (string)$v('title_id') === (string)$r['id'] ? 'selected' : '' ?>
>
<?= e($r['name_en']) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-3">
<label class="form-label">Gender *</label>
<select class="form-select" name="gender" required>
<option value="">Select</option>
<?php foreach (['MALE','FEMALE'] as $gender): ?>
<option
    value="<?= e($gender) ?>"
    <?= (string)$v('gender') === $gender ? 'selected' : '' ?>
>
<?= e($gender) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-4">
<label class="form-label">Name with Initials *</label>
<input
    class="form-control"
    name="name_with_initials"
    value="<?= e($v('name_with_initials')) ?>"
    required
>
</div>

<div class="col-md-8">
<label class="form-label">Full Name (English) *</label>
<input
    class="form-control"
    name="full_name_en"
    value="<?= e($v('full_name_en')) ?>"
    required
>
</div>

<div class="col-md-6">
<label class="form-label">Full Name (Sinhala)</label>
<input
    class="form-control"
    name="full_name_si"
    value="<?= e($v('full_name_si')) ?>"
>
</div>

<div class="col-md-6">
<label class="form-label">Full Name (Tamil)</label>
<input
    class="form-control"
    name="full_name_ta"
    value="<?= e($v('full_name_ta')) ?>"
>
</div>

<div class="col-md-3">
<label class="form-label">Date of Birth *</label>
<input
    type="date"
    class="form-control"
    name="date_of_birth"
    value="<?= e($v('date_of_birth')) ?>"
    required
>
</div>

<div class="col-md-3">
<label class="form-label">Civil Status</label>
<select class="form-select" name="civil_status_id">
<option value="">Optional</option>
<?php foreach ($civilStatuses as $r): ?>
<option
    value="<?= e($r['id']) ?>"
    <?= (string)$v('civil_status_id') === (string)$r['id'] ? 'selected' : '' ?>
>
<?= e($r['name_en']) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-6">
<label class="form-label">
Photograph
<span class="text-muted small">
Optional. Upload only to replace current photograph.
</span>
</label>
<input
    type="file"
    class="form-control"
    name="photograph"
    accept="image/jpeg,image/png"
>
</div>

</div>
</div>

<div class="form-section">
<h5>Contact</h5>

<div class="row g-3">

<div class="col-md-6">
<label class="form-label">Permanent Address *</label>
<textarea
    class="form-control"
    name="permanent_address"
    required
><?= e($v('permanent_address')) ?></textarea>
</div>

<div class="col-md-6">
<label class="form-label">Temporary Address</label>
<textarea
    class="form-control"
    name="temporary_address"
><?= e($v('temporary_address')) ?></textarea>
</div>

<div class="col-md-3">
<label class="form-label">Primary Mobile *</label>
<input
    class="form-control"
    name="primary_mobile"
    value="<?= e($v('primary_mobile')) ?>"
    placeholder="0XXXXXXXXX or +94XXXXXXXXX"
    required
>
</div>

<div class="col-md-3">
<label class="form-label">Alternative Mobile *</label>
<input
    class="form-control"
    name="alternative_mobile"
    value="<?= e($v('alternative_mobile')) ?>"
    placeholder="0XXXXXXXXX or +94XXXXXXXXX"
    required
>
</div>

<div class="col-md-3">
<label class="form-label">Personal Email</label>
<input
    type="email"
    class="form-control"
    name="personal_email"
    value="<?= e($v('personal_email')) ?>"
>
</div>

<div class="col-md-3">
<label class="form-label">Official Email</label>
<input
    type="email"
    class="form-control"
    name="official_email"
    value="<?= e($v('official_email')) ?>"
>
</div>

</div>
</div>

<div class="form-section">
<h5>Employment</h5>

<div class="row g-3">

<div class="col-md-3">
<label class="form-label">Initial Appointment Date *</label>
<input
    type="date"
    class="form-control"
    name="initial_appointment_date"
    value="<?= e($v('initial_appointment_date')) ?>"
    required
>
</div>

<div class="col-md-3">
<label class="form-label">Appointment Nature *</label>
<select
    class="form-select"
    name="appointment_nature_id"
    required
>
<option value="">Select</option>
<?php foreach ($appointmentNatures as $r): ?>
<option
    value="<?= e($r['id']) ?>"
    <?= (string)$v('appointment_nature_id') === (string)$r['id'] ? 'selected' : '' ?>
>
<?= e($r['name_en']) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-3">
<label class="form-label">Primary Designation *</label>
<select
    class="form-select"
    name="primary_designation_id"
    required
>
<option value="">Select</option>
<?php foreach ($designations as $r): ?>
<option
    value="<?= e($r['id']) ?>"
    <?= (string)$v('primary_designation_id') === (string)$r['id'] ? 'selected' : '' ?>
>
<?= e($r['name_en']) ?>
(<?= e($r['designation_level']) ?>)
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-3">
<label class="form-label">Class</label>
<select class="form-select" name="class_id">
<option value="">Not Applicable</option>
<?php foreach ($classes as $r): ?>
<option
    value="<?= e($r['id']) ?>"
    <?= (string)$v('class_id') === (string)$r['id'] ? 'selected' : '' ?>
>
<?= e($r['name_en']) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-6">
<label class="form-label">Officer Status *</label>
<select
    class="form-select"
    name="officer_status_id"
    required
>
<option value="">Select</option>
<?php foreach ($statuses as $r): ?>
<option
    value="<?= e($r['id']) ?>"
    <?= (string)$v('officer_status_id') === (string)$r['id'] ? 'selected' : '' ?>
>
<?= e($r['name_en']) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-6">
<label class="form-label">Effective From *</label>
<input
    type="date"
    class="form-control"
    name="effective_from"
    value="<?= e($v('effective_from', date('Y-m-d'))) ?>"
    required
>
</div>

<div class="col-12">
<div class="alert alert-info mb-0">
Office membership is not changed here.
Use effective-dated Office Assignments to move an Officer between offices.
</div>
</div>

</div>
</div>

<button class="btn btn-primary">
Save Changes
</button>

<a
    class="btn btn-outline-secondary"
    href="<?= e(url('hr/officers/'.$officer['id'])) ?>"
>
Cancel
</a>

</form>