<?php
class Res { public function __construct(public string $n) {} public function __destruct() { echo "res-drop {$this->n}\n"; } }
class Mw { public string $n = "mw"; public function handle($req, $next) { return $next($req); } public function __destruct() { echo "mw-drop\n"; } }
class Reg {
    public string $method = 'handle';
    public function make(string $c) { $r = new ReflectionClass($c); return $r->newInstanceArgs([]); }
    public function makeDirect(string $c) { return new $c(); }
    public function handleCarry($c) { return $c; }
    public function lvl1($pipe) {
        return function ($p) use ($pipe) { $pipe = $this->makeDirect($pipe); echo "lvl1\n"; return $pipe->handle($p, fn ($x) => $x); };
    }
    public function lvl2($pipe) {
        return function ($p) use ($pipe) {
            try { $pipe = $this->makeDirect($pipe); echo "lvl2\n"; return $pipe->handle($p, fn ($x) => $x); }
            catch (Throwable $e) { return null; }
        };
    }
    public function lvl3($pipe) {
        return function ($p) use ($pipe) {
            $pipe = $this->makeDirect($pipe); $parameters = [$p, fn ($x) => $x]; echo "lvl3\n";
            $carry = method_exists($pipe, $this->method) ? $pipe->{$this->method}(...$parameters) : $pipe(...$parameters);
            return $this->handleCarry($carry);
        };
    }
    public function lvl4($pipe) {
        return function ($p) use ($pipe) { $pipe = $this->make($pipe); echo "lvl4\n"; return $pipe->handle($p, fn ($x) => $x); };
    }
}
$r = new Reg();
foreach (['lvl1', 'lvl2', 'lvl3', 'lvl4'] as $l) {
    $f = $r->$l('Mw');
    $v = $f(new Res($l));
    echo "got {$v->n}\n";
    unset($v);
    unset($f);
    echo "after $l\n";
}
class M2 { public function h($r) { return $r; } }
class R2 { public function __destruct() { echo "d\n"; } }
function a() { $a = [new R2()]; $m = new M2(); $n = 'h'; $x = $m->{$n}(...$a); unset($a); echo "a\n"; unset($x); echo "a2\n"; }
function b() { $a = [new R2()]; $m = new M2(); $n = 'h'; $x = $m->{$n}(...$a); unset($a); echo "b\n"; $x = null; echo "b2\n"; }
function c() { $a = [new R2()]; $m = new M2(); $n = 'h'; $x = $m->{$n}(...$a); unset($a); echo "c\n"; return; }
function d() { $a = [new R2()]; $m = new M2(); $n = 'h'; $y = 1; $x = $m->{$n}(...$a); echo "d\n"; }
a(); echo "=\n"; b(); echo "=\n"; c(); echo "=\n"; d(); echo "=\n";
