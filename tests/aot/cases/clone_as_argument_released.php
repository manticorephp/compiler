<?php
class Tok { public function __construct(public string $s) {} public function __destruct() { echo "tok ", $this->s, "\n"; } }
function keep(mixed $v): int { return 1; }
function keepT(Tok $v): int { return 1; }
function any(Tok $t): mixed { return $t; }
$t = new Tok("x");
keep(clone $t); echo "a\n";
keepT(clone $t); echo "b\n";
$m = any($t);
keep(clone $m); echo "c\n";
$f = new SplFixedArray(1); $f[0] = new Tok("e");
$v = $f[0]; $f[0] = clone $v; unset($v); echo "d\n";
foreach ($f as $k => $w) { if (is_object($w)) { $f[$k] = clone $w; } }
unset($w); echo "e\n";
unset($f); echo "f\n";
unset($m, $t); echo "end\n";
