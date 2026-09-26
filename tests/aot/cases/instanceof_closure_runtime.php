<?php
final class Inv { public function __invoke($v) { return true; } }
/** @param array<int, mixed> $vals */
function check(array $vals, $value): void {
    foreach ($vals as $av) {
        echo $av instanceof \Closure ? 'C' : 'n';
        echo \is_object($av) ? 'o' : '-';
        echo ' ';
    }
    echo "\n";
}
$f = static fn($v) => true;
$arr = ['a', 1, $f, new Inv(), function () {}, 'strlen'];
foreach ($arr as &$x) { if (\is_object($x) && \is_callable($x)) { $x = static fn($v) => $x($v); } }
unset($x);
check($arr, 1);
$c = $f;
var_dump($c instanceof \Closure, $f instanceof Closure);
/** @param array<int, mixed> $vals */
function run(array $vals, $value): void {
    foreach ($vals as $av) {
        if ($av instanceof \Closure) { var_dump($av($value)); continue; }
        var_dump($av === $value);
    }
}
run($arr, 'strlen');
function cb(callable $c): bool { return $c instanceof Closure; }
var_dump(cb('strlen'), cb($f), cb(new Inv()), cb([new Inv(), '__invoke']));
$g = function () { yield 1; };
var_dump($g instanceof Closure, $g() instanceof Closure, "abc" instanceof Closure);

$c = $f;
var_dump($c instanceof \Closure);
function cb2(callable $c): bool { return $c instanceof Closure; }
var_dump(cb2('strlen'), cb2($f), cb2(new Inv()));
