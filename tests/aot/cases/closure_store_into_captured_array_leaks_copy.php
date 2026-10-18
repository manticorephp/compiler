<?php
// A closure that stores into its by-value captured array leaks the per-call copy (the capture param is untyped, so nothing owns the copy).
function run(string $v, int $n) {
    $seen = ['a' => 1, 'b' => 2];
    return preg_replace_callback('/x/', function ($m) use ($seen, $n) { $seen['k'] = $n; $seen['z'] = 1; return (string)count($seen); }, $v);
}
for ($i = 0; $i < 2000; $i++) { run('xx', $i); }
$base = memory_get_peak_usage();
for ($i = 0; $i < 60000; $i++) { run('xxx', $i); }
echo memory_get_peak_usage() - $base < 4 * 1024 * 1024 ? "flat\n" : "grew\n";
