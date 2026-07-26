<?php
// A callback taking its value BY REFERENCE, and a comparator passed by string
// name. Both used to SIGSEGV: emitInvoke boxed every argument and never
// consulted the callee's by-ref mask, and it indexed the callee's param types
// from 0 even though a closure's params are prefixed by its captures.
$m = ['a' => 1, 'b' => 2, 'c' => 3];
array_walk($m, function (&$v, $k) { $v = $v * 10; });
print_r($m);

// ...with a capture, which is what exposed the param-index offset.
$factor = 3;
$n = ['x' => 1, 'y' => 2];
array_walk($n, function (&$v, $k) use ($factor) { $v = $v * $factor; });
print_r($n);

// By-value callbacks stay exact.
$sum = 0;
array_walk($m, function ($v, $k) use (&$sum) { $sum += $v; });
echo $sum, "\n";

// The third argument reaches the callback.
array_walk($n, function ($v, $k, $tag) { echo $tag, $k, "=", $v, " "; }, "#");
echo "\n";

// Recursive walk descends into array leaves only. This one also hit a clang
// "invalid redefinition" — a recursive prelude fn with a callable dimension
// emitted its monomorphized clone twice.
$deep = ['s' => ['p' => 1, 'q' => 2], 'plain' => 3, 'd' => ['e' => ['f' => 4]]];
array_walk_recursive($deep, function ($v, $k) { echo $k, ":", $v, " "; });
echo "\n";

// A STRING-NAME comparator: the wrapper closure was synthesized with ONE
// parameter for a two-parameter stdlib function, because the extern is
// registered under its FQN (Runtime\Libc\strcasecmp) and the bare-name lookup
// missed.
$k1 = ['GREEN' => 1, 'blue' => 2, 'Red' => 3];
$k2 = ['green' => 'x', 'RED' => 'y'];
print_r(array_diff_ukey($k1, $k2, 'strcasecmp'));
print_r(array_intersect_ukey($k1, $k2, 'strcasecmp'));

// ...and the same through a closure comparator.
print_r(array_diff_ukey($k1, $k2, function ($a, $b) { return strcasecmp($a, $b); }));
