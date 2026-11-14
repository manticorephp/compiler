<?php
// An int landing in a slot whose type admits float but not int (?float, float|string) becomes a float: params, returns, props, hooks, variadics.
namespace App;
interface I { public function f(int $x): ?float; }
trait T { public function tr(int $x): float|string { return $x; } public static ?float $ts = null; }
abstract class B implements I { public function f(int $x): ?float { return $x * 2; } }
final class K extends B {
    use T;
    public float|string $u = 'x';
    public function __construct(public float|null $p = 4, public ?int $keep = 3) { }
    public function arr(array $a): ?float { return $a['k'] ?? null; }
    public function gen(int $n): \Generator { yield $n; return $n; }
}
function keep1(int|float|null $x): int|float|null { return $x; }
function keep2(?int $x): ?int { return $x; }
function keep3(float|bool $x) { var_dump($x); }
function nul(?float $x = null): ?float { return $x; }
function deflt(?float $x = 3): ?float { return $x; }
$k = new K();
var_dump($k->f(3), $k->tr(4), $k->p, $k->keep);
$k->u = 9; var_dump($k->u); $k->u = "s"; var_dump($k->u); $k->u = 1.5; var_dump($k->u);
K::$ts = 6; var_dump(K::$ts); $z = 8; K::$ts = $z; var_dump(K::$ts); K::$ts = null; var_dump(K::$ts);
var_dump($k->arr(['k' => 5]), $k->arr([]), $k->arr(['k' => 2.5]));
$g = $k->gen(5); foreach ($g as $v) var_dump($v); var_dump($g->getReturn());
var_dump(keep1(1), keep2(2), nul(), nul(null), nul(5), deflt(), deflt(null));
keep3(true); keep3(5);
$s = static fn(?float $x): ?float => $x; var_dump($s(1), $s(null));
$m = [$k, 'f']; var_dump($m(10));
var_dump(array_sum(array_map(fn(int $i): ?float => $i, [1, 2, 3])));
$c = (float)nul(null); var_dump($c);
$x = nul(3) + 1; var_dump($x);
var_dump(json_encode(['a' => nul(1)]));

function r1(int $a): ?float { return $a; }
function r2(int $a): float|string { return $a; }
function r3(mixed $a): ?float { return $a; }
function p1(?float $x) { var_dump($x); }
function p2(float|string $x) { var_dump($x); }
function p3(?float $x = 3) { var_dump($x); }
function p4(float|null|bool $x) { var_dump($x); }
function v1(float ...$xs) { var_dump($xs); }
function v2(?float ...$xs) { var_dump($xs); }
function br(?float &$x) { var_dump($x); }
class C2 {
    public ?float $a = null;
    public float|string $b = 0.5;
    public static ?float $s = null;
    public function __construct(public ?float $pp = null) {}
    public function m(?float $x): ?float { return $x; }
    public static function sm(int $x): ?float { return $x; }
    public float $h { get => 1; }
    public float|string $u { get => 2; }
}
var_dump(r1(5), r2(6), r3(7), r3(null));
p1(1); p1(null); p2(2); p2("x"); p3(); p4(4); p4(true);
v1(1, 2.5); v2(1, null);
$i = 9; br($i); var_dump($i);
$o = new C2(8); var_dump($o->pp);
$o->a = 5; $n = 6; $o->b = $n; var_dump($o->a, $o->b);
C2::$s = 3; var_dump(C2::$s);
var_dump($o->m(4), C2::sm(5), $o->h, $o->u);
$f = fn(int $x): ?float => $x; var_dump($f(2));
$g = function (?float $x) { return $x; }; var_dump($g(3));
$d = 'App\p1'; $d(11);
var_dump(array_map(fn(?float $x) => $x, [1, null]));
