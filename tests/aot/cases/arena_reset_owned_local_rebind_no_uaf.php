<?php

// The same, with a heap rebind inside the loop on every third iteration.
function mk(string $p): string { return str_repeat($p, 3); }
function g(int $n, string $p, string $fill): int {
    $s = mk($p); $t = 0; $i = 0;
    while ($i < $n) { $pad = $p . (string)$i . 'aaaaaaaaaaaaa'; $s = $pad . 'b'; if ($i % 3 === 0) { $s = mk($p); } $t += strlen($s) + strlen($pad); $i++; }
    $junk = $fill . $fill; $t += strlen($junk); $junk2 = $junk . $fill;
    return $t + strlen($junk2) + ord($junk2[100]);
}
$fill = str_repeat(pack('P', 1), 64); $t = 0;
for ($z = 0; $z < 2000; $z++) { $t += g(20, 'q', $fill); } echo $t, "\n";
