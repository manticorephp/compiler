<?php
// A codegen builtin with no stdlib twin (count, abs, substr, min, ...) called through a runtime name throws "undefined function".
foreach (['count', 'abs'] as $n) {
    echo $n === 'count' ? $n([1, 2, 3]) : $n(-4), "\n";
}
// names built at run time so no call folds to a direct builtin call
$N = [];
foreach (explode(',', 'count,sizeof,abs,max,min,substr,implode,join,sprintf,intval,pow,round,floor,ceil,sqrt') as $w) { $N[$w] = $w; }
$a = [3, 1, 2];
foreach ([strtoupper($N['count']), $N['sizeof']] as $f) { echo $f($a), $f([[1, 2], [3]], 1), "\n"; }
foreach ([-4, 4.5, -2.5, 0] as $v) { $f = $N['abs']; var_dump($f($v)); }
$f = $N['max']; var_dump($f(1, 5, 3), $f([4, 9, 2]), $f('a', 'b'), $f(1.5, 1), $f(2, 2.0));
$f = $N['min']; var_dump($f(4, 2, 8), $f([4, 9, 2]), $f('a', 'b'), $f(2, 2.0));
$f = $N['substr']; var_dump($f('hello', 1), $f('hello', 1, 3), $f('hello', -2), $f('hi', 5));
$f = $N['implode']; var_dump($f(',', $a), $f($a), $f('-', ['a']));
$f = $N['join']; var_dump($f('+', $a));
$f = $N['sprintf']; var_dump($f('%05.1f|%s', 3.14159, 'x'), $f('plain'));
$f = $N['intval']; var_dump($f('12abc'), $f(7.9), $f('ff', 16), $f(null));
$f = $N['pow']; var_dump($f(2, 10), $f(2, -1), $f(2.0, 3));
$f = $N['round']; var_dump($f(2.5), $f(1.2345, 2), $f(-0.5));
foreach ([$N['floor'], $N['ceil'], $N['sqrt']] as $f) { var_dump($f(7.5), $f(16)); }
// direct calls keep the inline builtin
var_dump(substr('abc', 1), pow(2, 3), pow(2, 0.5), max(1, 2.5), min([3, 1]), abs(-3), abs(-3.5), count($a), intval('9'), round(2.567, 1), implode('', $a));
$f = $N['sprintf']; var_dump($f('%d|%s|%s', '42', 'x', 7));
var_dump(call_user_func($N['max'], 3, 9), call_user_func_array($N['substr'], ['abcdef', 2, 2]), call_user_func($N['count'], [1, 2]));
// a user function with a mixed variadic, called by a run-time name
function packed(string $tag, mixed ...$rest): string { return $tag . ':' . count($rest) . ':' . implode('/', $rest); }
$f = implode('', ['pack', 'ed']);
var_dump($f('a'), $f('b', 1), $f('c', 1, 'x', 2.5));
