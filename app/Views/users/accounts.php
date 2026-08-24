<?php use App\Core\{Auth,Csrf,SecurityHeaders}; ?>
<div class="page-heading">
    <div><div class="breadcrumb-lite">User Management / Active Users</div><h1>Active Users</h1><p>Search and manage users who can currently sign in to DEMS.</p></div>
    <?php if (Auth::can('user.request')): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestUser"><i class="bi bi-plus-lg"></i> Request User Account</button><?php endif; ?>
</div>
<div class="mb-3"><a class="btn btn-outline-secondary" href="<?= e(url('access-management/users/historical')) ?>">Inactive Users</a></div>
<?php require BASE_PATH . '/app/Views/components/datatable.php'; ?>
<?php if (Auth::can('user.request')): ?>
<div class="modal fade" id="requestUser" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" method="post" action="<?= e(url('access-management/users/request')) ?>">
    <?= Csrf::field() ?>
    <div class="modal-header"><h5 class="modal-title">Request New User</h5><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label" for="account-source">Account Source *</label>
            <select class="form-select" id="account-source" name="account_source" required>
                <option value="EXISTING_OFFICER">Existing Approved Officer</option>
                <option value="MANUAL_NO_OFFICER">User Not Yet Registered as Officer</option>
            </select>
        </div>
        <div id="existing-officer-fields">
            <label class="form-label" for="request-officer">Approved Officer *</label>
            <select class="form-select mb-3" id="request-officer" name="officer_id" required><option value="">Select</option><?php foreach ($officers as $officer): ?><option value="<?= e($officer['id']) ?>"><?= e($officer['dad_number'] . ' - ' . $officer['name_with_initials']) ?></option><?php endforeach; ?></select>
        </div>
        <div id="manual-account-fields" hidden>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label" for="request-full-name">Full Name *</label><input class="form-control" id="request-full-name" name="full_name" maxlength="255"></div>
                <div class="col-md-6"><label class="form-label" for="request-role">Role *</label><select class="form-select" id="request-role" name="role_id"><option value="">Select role</option><?php foreach ($roles as $role): ?><option value="<?= e($role['id']) ?>" data-level="<?= e($role['role_level']) ?>"><?= e($role['role_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label" for="request-effective-from">Effective From *</label><input class="form-control" id="request-effective-from" type="date" name="effective_from" value="<?= e($accessBaseline) ?>" min="<?= e($accessBaseline) ?>"></div>
                <div class="col-md-6" id="request-location-wrap"><label class="form-label" for="request-location">Assigned Location *</label><input class="form-control form-control-sm mb-2" id="request-location-search" type="search" placeholder="Search location"><select class="form-select" id="request-location" name="location_id"><option value="">Select a role first</option></select></div>
                <div class="col-md-6" id="request-national-wrap" hidden><div class="form-label">Assigned Location</div><div class="form-control bg-light">National Level</div></div>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Username *</label><input class="form-control" name="username" required minlength="5" maxlength="50"></div>
            <div class="col-md-6"><label class="form-label">Security Method</label><select class="form-select" name="mfa_method"><option value="AUTHENTICATOR_APP">Authenticator App</option><option value="SMS_OTP">SMS Code</option></select></div>
            <div class="col-12"><label class="form-label">Temporary Password *</label><input type="password" class="form-control" name="temporary_password" minlength="8" required><div class="form-text">The user must change this password after signing in.</div></div>
        </div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Submit Request</button></div>
</form></div></div>
<script nonce="<?= e(SecurityHeaders::nonce()) ?>">(()=>{const source=document.getElementById('account-source'),officerFields=document.getElementById('existing-officer-fields'),manualFields=document.getElementById('manual-account-fields'),officer=document.getElementById('request-officer'),fullName=document.getElementById('request-full-name'),role=document.getElementById('request-role'),date=document.getElementById('request-effective-from'),locationWrap=document.getElementById('request-location-wrap'),nationalWrap=document.getElementById('request-national-wrap'),location=document.getElementById('request-location'),search=document.getElementById('request-location-search');if(!source||!role||!location)return;const endpoint=<?= json_encode(url('access-management/assignment-locations'),JSON_THROW_ON_ERROR) ?>,geographic=new Set(['DISTRICT','ASC','ARPA','FARMER']);let timer=0,request=null;const clear=label=>location.replaceChildren(new Option(label,''));const setEnabled=(container,enabled)=>container.querySelectorAll('input,select').forEach(field=>field.disabled=!enabled);const load=async()=>{const level=role.selectedOptions[0]?.dataset.level||'',roleId=role.value,usesLocation=geographic.has(level);locationWrap.hidden=!usesLocation;nationalWrap.hidden=usesLocation||!roleId;location.required=usesLocation;search.required=false;if(!usesLocation||!roleId){clear('National Level');return;}request?.abort();request=new AbortController();clear('Loading locations...');try{const query=new URLSearchParams({role_id:roleId,q:search.value.trim(),limit:'100'}),response=await fetch(endpoint+'?'+query,{headers:{Accept:'application/json'},signal:request.signal});if(!response.ok)throw new Error('Location lookup denied');const data=await response.json();clear(data.results.length?'Select location':'No matching locations');for(const row of data.results)location.add(new Option([row.dad_number,row.name_en].filter(Boolean).join(' - '),row.id));}catch(error){if(error.name!=='AbortError')clear('Unable to load locations');}};const mode=()=>{const manual=source.value==='MANUAL_NO_OFFICER';officerFields.hidden=manual;manualFields.hidden=!manual;setEnabled(officerFields,!manual);setEnabled(manualFields,manual);officer.required=!manual;fullName.required=manual;role.required=manual;date.required=manual;if(manual)load();};source.addEventListener('change',mode);role.addEventListener('change',()=>{search.value='';clear('Select location');load();});search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(load,250);});mode();})();</script>
<?php endif; ?>
