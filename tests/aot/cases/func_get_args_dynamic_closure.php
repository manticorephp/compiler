<?php
// A dynamically invoked closure reading func_get_args() sees the arguments past its declared list (Symfony ImportMapConfigReader).
function id($f) { return $f; }
class H { public $cb; }
$h = new H; $h->cb = function () { return func_num_args() . func_get_arg(0) . json_encode(func_get_args()); };
echo ($h->cb)('prop', 2), "\n";
$f = id(function ($a) { return func_num_args() . $a . func_get_arg(1) . json_encode(func_get_args()); });
echo $f('p', 'q'), "\n";
echo $f('only', 'x', [1, 2], 3.5, null), "\n";
$c = \Closure::bind(static fn () => func_get_arg(0), null, null);
echo $c('bound'), "\n";
echo \Closure::bind(static fn () => func_get_arg(0), null, null)('bound-inline'), "\n";
$v = 7; $cap = id(function ($a = 'dflt') use ($v) { return $v . $a . json_encode(func_get_args()); });
echo $cap(), $cap('a'), $cap('a', 'b'), "\n";
$pack = id(function (string ...$r) { return implode('|', $r); });
echo $pack('x', 'y'), "\n";
$plain = id(function ($a) { return $a; });
echo $plain('ignored surplus', 'z'), "\n";
for ($i = 0; $i < 3; $i++) { $g = id(function ($a) { return func_num_args(); }); echo $g("l", new ArrayObject([$i])); }
echo "done\n";
