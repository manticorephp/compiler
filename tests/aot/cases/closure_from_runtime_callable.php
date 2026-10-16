<?php
// Closure::fromCallable of a callable held in an erased slot builds a real Closure.
class C {
    protected function getEnv(string $n): string { return "env:$n"; }
    public function mk(): mixed { return \Closure::fromCallable(ids([$this, 'getEnv'])); }
    public static function up(string $s): string { return strtoupper($s); }
}
function ids(mixed $v): mixed { return $v; }
$f = (new C())->mk();
var_dump($f instanceof \Closure, is_callable($f), $f('X'));
$g = \Closure::fromCallable(ids(['C', 'up']));
var_dump($g instanceof \Closure, $g('abc'));
$h = \Closure::fromCallable(ids(fn ($x) => $x * 2));
var_dump($h(21));
$k = \Closure::fromCallable(ids([new C(), 'mk']));
var_dump($k()('W'));
$args = ['V'];
var_dump($f(...$args));
var_dump(array_map(\Closure::fromCallable(ids(['C', 'up'])), ['a', 'b']));
