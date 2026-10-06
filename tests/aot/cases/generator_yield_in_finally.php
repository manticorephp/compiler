<?php
// A `finally` holding a yield is emitted at every jump that leaves it (a
// return, an exception), and each copy is its own resume point.
function a(): \Generator { try { yield 1; return 5; } finally { echo "fin\n"; yield 9; } }
$n = 0;
foreach (a() as $v) { echo $v, "\n"; if (++$n > 6) { echo "runaway\n"; break; } }
function b(): \Generator { try { yield 1; throw new \RuntimeException('x'); } finally { echo "fin b\n"; yield 2; echo "after 2\n"; } }
$g = b();
try { foreach ($g as $v) { echo $v, "\n"; } } catch (\RuntimeException $e) { echo "caught ", $e->getMessage(), "\n"; }
var_dump($g->current());
