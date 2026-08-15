<?php use App\Core\Csrf; ?>
<div class="page-heading">
  <div>
    <div class="breadcrumb-lite">Account / Change Password</div>
    <h1><?= !empty($forced)?'Set a New Password':'Change Password' ?></h1>
    <p><?= !empty($forced)?'Replace your temporary password before continuing.':'Update the password for your signed-in account.' ?></p>
  </div>
</div>
<div class="card border-0 shadow-sm" style="max-width:620px">
  <div class="card-body p-4">
    <form method="post" action="<?= e(url('account/change-password')) ?>">
      <?= Csrf::field() ?>
      <div class="mb-3"><label class="form-label" for="current-password">Current Password</label><input id="current-password" type="password" class="form-control" name="current_password" autocomplete="current-password" required></div>
      <div class="mb-3"><label class="form-label" for="new-password">New Password</label><input id="new-password" type="password" class="form-control" name="new_password" minlength="12" autocomplete="new-password" aria-describedby="password-help" required><div id="password-help" class="form-text">At least 12 characters with uppercase, lowercase, number and symbol.</div></div>
      <div class="mb-3"><label class="form-label" for="confirm-password">Confirm New Password</label><input id="confirm-password" type="password" class="form-control" name="new_password_confirmation" minlength="12" autocomplete="new-password" required></div>
      <button class="btn btn-primary" type="submit">Change Password</button>
    </form>
  </div>
</div>
