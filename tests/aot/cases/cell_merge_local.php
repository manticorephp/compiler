<?php
// if/else binds a local to distinct scalar kinds -> boxed cell at the merge
function classify(int $n) {
    if ($n > 0) { $r = 1.5; } else { $r = "neg"; }
    return $r;
}
var_dump(classify(5));
var_dump(classify(-1));

// explicit mixed across a merge
function t(mixed $x, bool $b) {
    if ($b) { $x = 1.5; } else { $x = "hi"; }
    var_dump($x);
}
t(0, true);
t(0, false);

// no-else: merge with the pre-if value
function g(int $n) {
    $v = 0;
    if ($n > 10) { $v = "big"; }
    return $v;
}
var_dump(g(20));
var_dump(g(3));

// pre-merge concrete use stays raw; post-merge use reads the cell
function h(bool $b) {
    $x = 7;
    echo "pre=", $x * 2, "\n";
    if ($b) { $x = "str"; }
    return $x;
}
var_dump(h(true));
var_dump(h(false));

// array reuse of the same name after a scalar merge re-narrows to array
function reuse(bool $b) {
    if ($b) { $x = 1; } else { $x = "s"; }
    var_dump($x);
    $x = [10, 20, 30];
    echo $x[1], "\n";
}
reuse(true);
reuse(false);

// int|float in a concat / echo context
function calc(bool $b): string {
    if ($b) { $n = 10; } else { $n = 2.5; }
    return "val=" . $n;
}
echo calc(true), "\n";
echo calc(false), "\n";
