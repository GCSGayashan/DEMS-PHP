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
<input type="hidden" name="version" value="<?= e($v('version')) ?>">

<?php if(!empty($directAdminEdit)): ?>
<div class="alert alert-warning">
<strong>Direct administrative correction</strong><br>
Changes are applied immediately to this approved Officer and recorded in the audit history. Officer workflow and assignments are not changed.
</div>
<?php endif; ?>

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
<label class="form-label">Telephone Number</label>
<input
    class="form-control"
    name="primary_mobile"
    value="<?= e($v('primary_mobile')) ?>"
    placeholder="0XXXXXXXXX or +94XXXXXXXXX"
>
</div>

<div class="col-md-3">
<label class="form-label">WhatsApp Number</label>
<input
    class="form-control"
    name="alternative_mobile"
    value="<?= e($v('alternative_mobile')) ?>"
    placeholder="0XXXXXXXXX or +94XXXXXXXXX"
>
<div class="form-text">At least one contact number is required.</div>
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

<div class="col-md-4">
<label class="form-label">Service Permanency *</label>
<select id="service-permanency" class="form-select" name="arpa_service_permanency" required>
<option value="" <?= (string)$v('arpa_service_permanency')===''?'selected':'' ?>>Select...</option>
<option value="PERMANENT_IN_SERVICE" <?= (string)$v('arpa_service_permanency')==='PERMANENT_IN_SERVICE'?'selected':'' ?>>Permanent In Service</option>
<option value="NOT_PERMANENT_IN_SERVICE" <?= (string)$v('arpa_service_permanency')==='NOT_PERMANENT_IN_SERVICE'?'selected':'' ?>>Not Permanent In Service</option>
</select>
</div>

<div class="col-md-4">
<label class="form-label">Permanented Date <span id="permanented-date-required" hidden>*</span></label>
<input id="service-permanented-date" type="date" class="form-control" name="service_permanented_date" value="<?= e($v('service_permanented_date')) ?>">
</div>

<div class="col-md-4">
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
<label class="form-label">Start Date *</label>
<input
    type="date"
    class="form-control"
    name="effective_from"
    value="<?= e($v('effective_from', date('Y-m-d'))) ?>"
    required
>
</div>

<?php if((string)$v('approval_status')!=='DRAFT'): ?>
<div class="col-12">
<div class="alert alert-info mb-0">
Office membership is not changed here.
Use effective-dated Office Assignments to move an Officer between offices.
</div>
</div>
<?php endif; ?>

</div>
</div>

<?php if((string)$v('approval_status')==='DRAFT'): ?>
<div class="form-section">
<h5>Initial Office Assignment</h5>
<div class="row g-3">
<div class="col-md-8">
<label class="form-label">Assigned Office</label>
<select class="form-select" name="initial_office_id">
<option value="" <?= $initialOfficeAssignment?'disabled':'' ?>><?= $initialOfficeAssignment?'Select a replacement Office':'No initial Office selected' ?></option>
<?php foreach($availableOffices as $office): ?>
<option value="<?= e($office['id']) ?>" <?= (string)($initialOfficeAssignment['office_id']??'')===(string)$office['id']?'selected':'' ?>>
<?= e($office['dad_number'].' - '.$office['name_en'].' ('.$office['office_type'].')') ?>
</option>
<?php endforeach; ?>
</select>
<div class="form-text">Changing this selection updates the same returned assignment; it does not create a duplicate.</div>
</div>
<div class="col-md-4">
<label class="form-label">Effective From</label>
<input type="date" class="form-control" name="office_effective_from" value="<?= e($initialOfficeAssignment['effective_from']??$v('effective_from',date('Y-m-d'))) ?>">
</div>
</div>
</div>
<?php endif; ?>

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

<script>document.addEventListener('DOMContentLoaded',()=>{const permanency=document.getElementById('service-permanency');const permanentedDate=document.getElementById('service-permanented-date');const marker=document.getElementById('permanented-date-required');const syncPermanency=()=>{const required=permanency?.value==='PERMANENT_IN_SERVICE';if(permanentedDate){permanentedDate.required=required;if(!required)permanentedDate.value='';}if(marker)marker.hidden=!required;};permanency?.addEventListener('change',syncPermanency);syncPermanency();});</script>
