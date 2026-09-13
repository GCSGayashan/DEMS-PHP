<?php
$timelinePath=str_replace('/DEMS-PHP/public','',parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)?:'');
$officerMode=str_starts_with($timelinePath,'/hr/arpa-appointments/timeline/officers');
?>
<nav class="mb-3" aria-label="Appointment timeline mode"><div class="btn-group" role="group">
  <a class="btn <?= !$officerMode?'btn-primary':'btn-outline-primary' ?>" href="<?= e(url('hr/arpa-appointments/timeline')) ?>">ARPA Division Timeline</a>
  <a class="btn <?= $officerMode?'btn-primary':'btn-outline-primary' ?>" href="<?= e(url('hr/arpa-appointments/timeline/officers')) ?>">Officer Timeline</a>
</div></nav>
