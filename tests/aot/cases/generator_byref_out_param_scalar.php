<?php
// A generator's by-ref out parameter is written through to the caller's variable
function g(int &$n, ?string &$s = null): iterable { $n = 5; $s = 'x'; yield 1; $n = 6; yield 2; }
$n = 0;
foreach (g($n, $s) as $k) { echo $k, ':', $n, $s, "\n"; }
echo $n, "\n";
