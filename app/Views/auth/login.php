<?php use App\Core\Csrf; $flashes=$_SESSION['_flash']??[]; unset($_SESSION['_flash']); ?>
<div class="row justify-content-center"><div class="col-12 col-md-6 col-lg-4"><div class="card shadow-sm border-0">
<div class="card-body p-4"><div class="text-center mb-4"><div class="brand-circle mx-auto mb-2">D</div><h3 class="mb-1">DEMS</h3><div class="text-muted">Department Enterprise Management System</div></div>
<?php foreach($flashes as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div><?php endforeach; ?>
<?php if (!empty($sessionNotice)): ?><div class="alert alert-warning"><?= e($sessionNotice) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('login')) ?>"><?= Csrf::field() ?>
<div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control" required autofocus></div>
<div class="mb-3"><label class="form-label">Password</label><input name="password" type="password" class="form-control" required></div>
<button class="btn btn-primary w-100">Sign in</button></form></div></div></div></div>
