<?php
// A concrete array handed to a known closure's untyped param is boxed (and,
// for a bool element, rebuilt) into a cell the call site owns: it is dropped
// after the call, so a loop of calls does not grow.
$f = function ($x) { return count($x); };
$g = function ($x) { return $x[0]; };
$sum = 0;
$base = memory_get_usage();
for ($i = 0; $i < 50000; $i++) {
    $sum += $f([$i % 2 === 0, true, false]);
    $sum += $f([$i, $i + 1]);
    $sum += \strlen($g([\str_repeat('x', 40) . $i]));
}
$grew = memory_get_usage() - $base;
echo $sum, "\n";
echo $grew < 1000000 ? "flat\n" : "grew $grew\n";
