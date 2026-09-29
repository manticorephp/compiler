<?php
// An element read stored into a local the ownership plan does not own (a
// rebound PARAM) was retained by the emitter and never released.
final class Tok { public function __construct(public string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
function f(Tok $t, SplFixedArray $s, int $i): void { $t = $s[$i]; echo $t->n, "\n"; }
function g(Tok $t, array $a): void { $t = $a[0]; echo $t->n, "\n"; }
function run(): void {
    $s = new SplFixedArray(1); $s[0] = new Tok('s0'); $p = new Tok('p'); f($p, $s, 0); unset($s);
    $a = [new Tok('a0')]; $q = new Tok('q'); g($q, $a); unset($a);
    echo "x\n";
}
run(); echo "end\n";
