<?php
// A failed fopen reports its warning to the installed error handler, as php's does: Symfony's file
// cache turns "File exists" into a retry with a fresh temp name, and fwrite(false) crashed the page.
$dir = sys_get_temp_dir() . '/mc_fopen_' . getmypid();
@mkdir($dir);
$f = $dir . '/tmp';
file_put_contents($f, 'taken');
set_error_handler(static fn (int $type, string $message) => throw new \ErrorException($message, 0, $type));
try {
    fopen($f, 'x');
    echo "opened\n";
} catch (\ErrorException $e) {
    echo $e->getSeverity() === E_WARNING ? 'warning: ' : 'other: ', str_contains($e->getMessage(), 'File exists') ? 'File exists' : $e->getMessage(), "\n";
}
try {
    fopen($dir . '/missing/x', 'r');
} catch (\ErrorException $e) {
    echo str_contains($e->getMessage(), 'No such file or directory') ? 'No such file or directory' : $e->getMessage(), "\n";
}
$h = fopen($dir . '/fresh', 'x');
echo get_debug_type($h) === 'resource (stream)' ? 'fresh ok' : get_debug_type($h), "\n";
fclose($h);
restore_error_handler();
var_dump(@fopen($f, 'x'));
unlink($f); unlink($dir . '/fresh'); rmdir($dir);
