<?php

// A local owned before a loop that resets its arena each iteration, rebound to
// an arena string inside it and not read after it: the flow's drops (the in-loop
// overwrite, the return) must never touch a value a reset already freed.
function mk(string $p): string { return str_repeat($p, 3); }
function g(int $n, string $p, string $fill): int {
    $s = mk($p); $t = 0; $i = 0;
    while ($i < $n) { $pad = $p . (string)$i . 'aaaaaaaaaaaaa'; $s = $pad . 'b'; $t += strlen($s) + strlen($pad); $i++; }
    $junk = $fill . $fill; $t += strlen($junk); $junk2 = $junk . $fill;
    return $t + strlen($junk2) + ord($junk2[100]);
}
$fill = str_repeat(pack('P', 1), 64); $t = 0;
for ($z = 0; $z < 2000; $z++) { $t += g(20, 'q', $fill); } echo $t, "\n";
