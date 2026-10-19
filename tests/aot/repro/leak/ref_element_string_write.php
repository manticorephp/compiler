<?php
// A string written through a reference element of an array property leaks.
// issue: #354
class T { public array $r = []; }
function one($i) { $t = new T; $y = "z$i"; $t->r[] = &$y; $t->r[0] = "q"; }
for ($i = 0; $i < 3000; $i++) one($i);
$b = memory_get_peak_usage();
for ($i = 0; $i < 60000; $i++) one($i);
echo memory_get_peak_usage() - $b < 1024 * 1024 ? "flat\n" : "grew\n";
