<?php
// MANTICORE-ONLY (memory_get_usage() is the peak RSS here). A reference alias of
// a superglobal names the same module cell, so a whole store THROUGH the alias
// owes the cell the same release its direct store does — without it every
// call left the previous array behind.
function seed(int $i): void
{
    $s = &$_SESSION;
    $s = ['n' => $i, 'name' => 'user' . $i];
    $s['extra'] = str_repeat('e', 8) . $i;
}
// The same store through a TWO-hop alias: the emitter follows the chain to
// the cell, so the scan and the release must too.
function seed2(int $i): void
{
    $s = &$_SESSION;
    $t = &$s;
    $t = ['n' => $i, 'name' => 'user' . $i];
    $t['extra'] = str_repeat('e', 8) . $i;
}
for ($i = 0; $i < 200; $i++) { seed($i); seed2($i); }
$m0 = memory_get_usage();
for ($i = 0; $i < 20000; $i++) { seed($i); seed2($i); }
$m1 = memory_get_usage();
echo $_SESSION['n'], ' ', $_SESSION['name'], ' ', $_SESSION['extra'], "\n";
echo (($m1 - $m0) < 512 * 1024) ? "flat\n" : 'growth=' . round(($m1 - $m0) / 1048576, 1) . "MB\n";
