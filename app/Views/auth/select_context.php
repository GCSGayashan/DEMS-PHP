<?php use App\Core\Csrf; $flashes=$_SESSION['_flash']??[];unset($_SESSION['_flash']); ?>
<div class="mx-auto" style="max-width:900px">
  <div class="text-center mb-4"><div class="brand-circle mx-auto mb-2">D</div><h1 class="h3">Select Working Context</h1><p class="text-muted">Choose the role and location you want to use for this session.</p></div>
  <?php foreach($flashes as $flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
  <?php if($contexts===[]): ?>
    <div class="alert alert-warning"><h2 class="h5">Access unavailable</h2><p class="mb-0">Your account does not have an active approved role and scope for today. Please contact an administrator.</p></div>
  <?php else: ?>
    <div class="row g-3"><?php foreach($contexts as $context): $current=$activeContext&&$activeContext['role_assignment_id']===$context['role_assignment_id']&&($activeContext['scope_assignment_id']??null)===($context['scope_assignment_id']??null); ?>
      <div class="col-md-6"><div class="card h-100"><div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between gap-2"><h2 class="h5 mb-1"><?= e($context['role_name']) ?></h2><?php if($current): ?><span class="badge text-bg-success align-self-start">Current</span><?php endif; ?></div>
        <div class="small text-muted mb-3"><?= e($context['role_code'].' | '.$context['role_level']) ?></div>
        <div class="fw-semibold"><i class="bi bi-geo-alt me-1"></i><?= e($context['location_label']) ?></div>
        <div class="small text-muted mb-3"><?= e(match($context['scope_mode']){'INCLUDE_CHILDREN'=>'Includes permitted descendant locations','EXACT'=>'Exact assigned location','NATIONAL'=>'National access',default=>'No administrative geographic scope'}) ?></div>
        <form method="post" action="<?= e(url('select-context')) ?>" class="mt-auto"><?= Csrf::field() ?><input type="hidden" name="role_assignment_id" value="<?= e($context['role_assignment_id']) ?>"><input type="hidden" name="scope_assignment_id" value="<?= e($context['scope_assignment_id']??'') ?>"><button class="btn btn-primary w-100" type="submit"><?= $current?'Continue':'Use This Context' ?></button></form>
      </div></div></div>
    <?php endforeach; ?></div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('logout')) ?>" class="text-center mt-4"><?= Csrf::field() ?><button class="btn btn-link" type="submit">Sign out</button></form>
</div>
