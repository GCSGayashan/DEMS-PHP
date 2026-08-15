<?php
declare(strict_types=1);

$tests = glob(__DIR__ . '/*Test.php') ?: [];
sort($tests);
$failed = false;
foreach ($tests as $test) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test);
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        $failed = true;
    }
}
exit($failed ? 1 : 0);
