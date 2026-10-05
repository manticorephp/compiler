<?php
// LEAK: a by-ref callee overwriting the caller's owned local never releases the old value
// issue: #20
final class Big { public string $pool; public function __construct() { $this->pool = str_repeat('x', 4096); } }
function set(?Big &$o): void { $o = new Big(); }
function sets(string &$s, int $i): void { $s = str_repeat('s', 4096) . $i; }
$m0 = memory_get_peak_usage();
$x = new Big();
$y = '';
for ($i = 0; $i < 10000; $i++) { set($x); sets($y, $i); }
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 8 ? "flat\n" : "LEAK\n";
