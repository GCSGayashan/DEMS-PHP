<?php
$arpaPath=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)?:'';
$arpaTabs=[
  ['Dashboard','hr/arpa-appointments','/hr/arpa-appointments'],
  ['New Assignments','hr/arpa-appointments/new','/hr/arpa-appointments/new'],
  ['Submitted','hr/arpa-appointments/submitted','/hr/arpa-appointments/submitted'],
  ['Review & Approve','hr/arpa-appointments/approval','/hr/arpa-appointments/approval'],
  ['Current Assignments','hr/arpa-appointments/open','/hr/arpa-appointments/open'],
  ['Assignment History','hr/arpa-appointments/history','/hr/arpa-appointments/history'],
  ['Appointment Timeline','hr/arpa-appointments/timeline','/hr/arpa-appointments/timeline'],
  ['Vacant ARPA Divisions','hr/arpa-appointments/vacant-divisions','/hr/arpa-appointments/vacant-divisions'],
  ['Appointment Data Issues','hr/arpa-appointments/issues','/hr/arpa-appointments/issues'],
  ['Subjects / Functions','hr/arpa-appointments/subjects','/hr/arpa-appointments/subjects'],
];
?>
<nav class="mb-3" aria-label="ARPA assignment views"><div class="nav nav-tabs flex-nowrap overflow-auto">
<?php foreach($arpaTabs as [$label,$href,$match]):$normalizedPath=str_replace('/DEMS-PHP/public','',$arpaPath);$active=$normalizedPath===$match||(in_array($match,['/hr/arpa-appointments/issues','/hr/arpa-appointments/timeline'],true)&&str_starts_with($normalizedPath,$match.'/')); ?>
<a class="nav-link text-nowrap <?= $active?'active':'' ?>" href="<?= e(url($href)) ?>" <?= $active?'aria-current="page"':'' ?>><?= e($label) ?></a>
<?php endforeach; ?>
</div></nav>
