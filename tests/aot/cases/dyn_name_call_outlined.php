<?php
// Functions called through a run-time name from several sites of one shape:
// by-reference callees write the caller's variables, a callee's exception
// reaches the caller's catch, an unknown name throws, and prelude callbacks
// (usort/array_map/array_filter) take string names.
function bump(int &$n, int $by): int { $n += $by; return $n; }
function boom(string $why, int $code): never { throw new RuntimeException($why, $code); }
function cmpLen(string $a, string $b): int { return strlen($a) <=> strlen($b); }
function twice(int $x): int { return $x * 2; }
function odd(int $x): bool { return $x % 2 === 1; }

function call2(string $f, mixed $a, mixed $b): mixed { return $f($a, $b); }
function call2ref(string $f, int $n, int $by): string { $r = $f($n, $by); return "$r/$n"; }

$subject = 'abc123';
$pattern = '/(\d+)/';
$m = null;
$f = 'preg_match';
var_dump($f($pattern, $subject, $m), $m);

$list = [3, 1, 2];
$g = 'sort';
$g($list);
echo implode(',', $list), "\n";

$stack = [1];
$h = 'array_push';
$h($stack, 9);
echo implode(',', $stack), "\n";

$n = 10;
$by = 5;
$b = 'bump';
var_dump($b($n, $by), $n);
echo call2ref('bump', 1, 2), "\n";
echo call2ref('intdiv', 7, 2), "\n";

foreach (['boom', 'no_such_fn'] as $name) {
    try {
        call2($name, 'bad', 7);
    } catch (RuntimeException $e) {
        echo get_class($e), ':', $e->getMessage(), ':', $e->getCode(), "\n";
    } catch (Error $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
var_dump(call2('str_repeat', 'ab', 2), call2('strcmp', 'a', 'b'), call2('max', 4, 9));

$words = ['ccc', 'a', 'bb'];
usort($words, 'cmpLen');
echo implode(',', $words), "\n";
echo implode(',', array_map('twice', [1, 2, 3])), "\n";
echo implode(',', array_filter([1, 2, 3, 4, 5], 'odd')), "\n";
