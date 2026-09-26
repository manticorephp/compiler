<?php
function pad(string $s, int $n = 3, string $c = '.'): string { return str_pad($s, $n, $c); }
function twice(int $x, int $k = 2): int { return $x * $k; }
function sum3(int $a, int $b = 10, int $c = 100): int { return $a + $b + $c; }
function tag(string $s, string $l = '<', string $r = '>'): string { return $l . $s . $r; }
function bump(array &$a, int $v = 1): void { $a[] = $v; }

function call1(string $f, mixed $x): mixed { return $f($x); }
function call2(string $f, mixed $x, mixed $y): mixed { return $f($x, $y); }

$log = [];
function noisy(string $s) { global $log; $log[] = $s; return $s; }

var_dump(call1('pad', 'a'));
var_dump(call2('pad', 'a', 5));
var_dump(call1('twice', 7));
var_dump(call2('twice', 7, 3));
var_dump(call1('sum3', 1));
var_dump(call2('sum3', 1, 2));
var_dump(call1('tag', 'x'));
var_dump(call1('trim', '  y  '));
var_dump(call2('trim', 'xxyxx', 'x'));
var_dump(call1('json_encode', [1, 2]));
foreach (['pad', 'tag', 'trim'] as $f) {
    var_dump($f(noisy('z')));
}
var_dump($log);
$arr = [];
$g = 'bump';
$g($arr);
$g($arr, 5);
var_dump($arr);
