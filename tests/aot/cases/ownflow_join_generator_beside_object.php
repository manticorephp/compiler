<?php
function gen(): \Generator { yield 1; yield 2; }
function pick(bool $f): \Iterator {
    $it = new ArrayIterator([7, 8]);
    if ($f) { $it = gen(); }
    return $it;
}
foreach ([true, false] as $f) {
    $it = pick($f);
    foreach ($it as $v) { echo $v, "\n"; }
}
$g = pick(true);
echo $g->current(), "\n";
$g->next();
echo $g->current(), "\n";
