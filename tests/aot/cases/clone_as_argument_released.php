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
unset($m, $t); echo "end\n";
