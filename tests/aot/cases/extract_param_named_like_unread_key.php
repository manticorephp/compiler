<?php
// A bare `array` param the body re-tags as a cell (extract() writes a variable of its own name) stays the caller's:
// the else edge must not give it away (musl and glibc abort on the double free).
function r(array $c): int { extract($c); return 1; }
function s(array $c, array $d): int { extract($d); return count($c); }
$sum = 0;
for ($i = 0; $i < 2000; $i++) {
    $sum += r(['items' => ['a', 'b'], 'n' => $i]);
    $sum += s(['x' => [1, 2, 3]], ['k' => [4]]);
}
$keep = ['items' => ['a'], 'c' => 'later'];
for ($i = 0; $i < 3; $i++) { r($keep); }
echo $sum, ' ', json_encode($keep), "\n";
