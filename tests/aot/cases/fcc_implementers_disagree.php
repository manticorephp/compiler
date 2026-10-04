<?php
// Implementers that disagree on defaults or by-ref modes: the callable passes
// what was supplied and each callee applies its own defaults / modes.
interface I { function f(int $x); }
class B implements I { function f(int|string $x, int $z = 4) { echo "B$x$z\n"; } }
class S implements I { function f(int $x, string $z = "s", $w = 9) { echo "S$x$z$w\n"; } }
function runI(I $i) { $f = $i->f(...); $f(3); }
runI(new B);
runI(new S);

class V { function g(int $x) { $x++; echo "V $x\n"; } }
class R { function g(int &$x) { $x++; echo "R $x\n"; } }
function runM(mixed $m) { $n = 1; $f = $m->g(...); $f($n); echo $n, "\n"; }
runM(new V);
runM(new R);
