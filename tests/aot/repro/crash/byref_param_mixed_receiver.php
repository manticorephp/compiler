<?php
// `$m->f($n)` on a mixed receiver where one implementer takes $n by value and another by-ref SIGSEGVs
// issue: #16
final class V { public function f($n) { return $n; } }
final class R { public function f(&$n) { $n = 9; } }
function t(mixed $m): int { $n = 1; $m->f($n); return $n; }
echo t(new V()), t(new R()), "\n";
