<?php
// dyn_dispatch — hot DYNAMIC sites: method by name, callable array, call_user_func,
// `$cls::$m()`, dynamic property read/write, `new $cls`; monomorphic and 4-way polymorphic.
// The gate for docs/design/table-dispatch.md: no regression on the monomorphic rows.
// `Shape::$w` is declared on purpose: without it the poly read hits tests/aot/repro/wrong/dyn_prop_read_subclass_slot.php.
abstract class Shape { public int $n = 0; public int $w = 0; abstract public function area(int $k): int; public static function make(int $k): int { return $k + 1; } }
final class Sq extends Shape { public int $w = 3; public function area(int $k): int { return $this->w * $k; } }
final class Re extends Shape { public int $w = 2; public int $h = 5; public function area(int $k): int { return $this->w * $this->h + $k; } }
final class Ci extends Shape { public int $w = 7; public function area(int $k): int { return $this->w + $k; } }
final class Tr extends Shape { public int $w = 4; public function area(int $k): int { return ($this->w * $k) >> 1; } }

/** A name the compiler cannot fold: every site below stays a dynamic one. */
function opaque(string $s, int $argc): string { return $argc > 0 ? $s : ''; }

function bench(string $label, callable $f, int $reps): void
{
    $t = hrtime(true);
    $r = $f($reps);
    $ms = (int)((hrtime(true) - $t) / 1000000);
    echo $label, ' ', $r, "\n";
    fwrite(STDERR, \sprintf("%-22s %6d ms\n", $label, $ms));
}

$reps = 2000000 * $argc;
$mono = new Sq();
$poly = [new Sq(), new Re(), new Ci(), new Tr()];
$classes = ['Sq', 'Re', 'Ci', 'Tr'];
$m = opaque('area', $argc);
$p = opaque('w', $argc);
$an = opaque('area', $argc);
$cn = opaque('Sq', $argc);
$fn = opaque('make', $argc);
$qn = opaque('n', $argc);

bench('method-name mono', function (int $n) use ($mono, $m): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += $mono->$m($i); } return $s; }, $reps);
bench('method-name poly', function (int $n) use ($poly, $m): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += $poly[$i & 3]->$m($i); } return $s; }, $reps);
bench('callable-array mono', function (int $n) use ($mono, $an): int { $c = [$mono, $an]; $s = 0; for ($i = 0; $i < $n; $i++) { $s += $c($i); } return $s; }, $reps);
bench('call_user_func poly', function (int $n) use ($poly, $an): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += call_user_func([$poly[$i & 3], $an], $i); } return $s; }, $reps);
bench('static-name mono', function (int $n) use ($cn, $fn): int { $c = $cn; $f = $fn; $s = 0; for ($i = 0; $i < $n; $i++) { $s += $c::$f($i); } return $s; }, $reps);
bench('prop-read mono', function (int $n) use ($mono, $p): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += $mono->$p; } return $s; }, $reps);
bench('prop-read poly', function (int $n) use ($poly, $p): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += $poly[$i & 3]->$p; } return $s; }, $reps);
bench('prop-write mono', function (int $n) use ($mono, $qn): int { $q = $qn; for ($i = 0; $i < $n; $i++) { $mono->$q = $i; } return $mono->n; }, $reps);
bench('new-cls poly', function (int $n) use ($classes): int { $s = 0; for ($i = 0; $i < $n; $i++) { $c = $classes[$i & 3]; $o = new $c(); $s += $o->w; } return $s; }, $reps >> 2);
// Fixed-name sites the compiler cannot resolve to one body: a 4-way union receiver (vdisp), and an
// erased receiver some class answers only through __call (the magic-only verdict).
final class Prox { /** @param array<int,mixed> $a */ public function __call(string $m, array $a): mixed { return (int)$a[0] + 1; } }
function erase_obj(mixed $o): mixed { return $o; }
bench('vcall mono', function (int $n) use ($poly): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += $poly[0]->area($i); } return $s; }, $reps);
bench('vcall poly', function (int $n) use ($poly): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += $poly[$i & 3]->area($i); } return $s; }, $reps);
bench('magic-mixed mono', function (int $n) use ($mono): int { $e = erase_obj($mono); $s = 0; for ($i = 0; $i < $n; $i++) { $s += (int)$e->area($i); } return $s; }, $reps);
bench('magic-mixed poly', function (int $n) use ($mono): int { $es = [erase_obj($mono), erase_obj(new Prox())]; $s = 0; for ($i = 0; $i < $n; $i++) { $s += (int)$es[$i & 1]->area($i); } return $s; }, $reps);
