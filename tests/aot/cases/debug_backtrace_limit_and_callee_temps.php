<?php
class H { public function __construct(public string $n) {} public function __destruct() { echo "drop {$this->n}\n"; } }
class R { public ?Closure $res = null; public function set(Closure $c) { $this->res = $c; return $this; } public function __destruct() { echo "R drop\n"; } }
class B extends R { public function set(Closure $c) { return parent::set($c); } }
class F {
    public static ?Closure $f = null; public ?Closure $h = null;
    public function t2($r) { $d = fn () => $r->n; return call_user_func($this->h ?? $d, 1, $r, $d); }
    public function t3($r) { $d = function () use ($r) { return $r->n; }; return call_user_func($d); }
    public function t4($r) { $d = fn () => $r->n; $e = $this->h ?? $d; return $e(); }
    public function t6(R $q, $r) { $q->set(fn () => $r); echo "t6 set\n"; return 1; }
    public function t7($r) { return (fn () => $r->n)(); }
    public function t8($r) { $x = true ? fn () => $r->n : null; return $x(); }
    public function t9($r) { return array_map(fn ($v) => $r->n, [1]); }
    public function t10($r) { $d = fn () => $r->n; return call_user_func_array($this->h ?? $d, []); }
}
$f = new F();
foreach (['t2','t3','t4','t7','t8','t9','t10'] as $m) { $h = new H($m); var_dump($f->$m($h)); unset($h); echo "== $m\n"; }
function six($f) { $q = new B(); $h = new H("t6"); $f->t6($q, $h); unset($h); echo "t6 body\n"; unset($q); echo "t6 unset\n"; }
six($f); echo "== t6\n";
class G {
    public static ?Closure $f = null;
    public static function mk($n) { yield $n; yield "m2"; }
    public static function dyn($n) { $c = 'G'; $m = 'mk'; return $c::$m($n); }
}
foreach (G::dyn("dynm") as $v) { echo "$v\n"; }
function lim1() { return count(debug_backtrace(0, 1)); }
function lim2() { return lim1(); }
function lim3() { $b = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2); return count($b) . ":" . $b[0]['function'] . ":" . $b[1]['function']; }
function lim4() { return lim3(); }
function lim5($n) { return count(debug_backtrace(0, $n)); }
function lim6() { return lim5(1) . "," . lim5(2) . "," . lim5(0); }
var_dump(lim2(), lim4(), lim6());
$m0 = 0; for ($i = 0; $i < 20000; $i++) { $d = lim4(); if ($i === 1000) { $m0 = memory_get_peak_usage(); } } echo memory_get_peak_usage() - $m0 < 2000000 ? "flat\n" : "grew\n";
