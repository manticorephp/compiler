<?php
final class O { public function __construct(public string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
/** @param array<int, mixed> $a */
function f(array $a): void { $y = $a[0]; $a[0] = new O('b'); echo "y=", $y->n, "\n"; }
function g(): void { $a = [new O('c'), 1, 'x']; $y = $a[0]; $a[0] = 5; echo "y=", $y->n, "\n"; $z = $a[2]; $a[2] = str_repeat('q', 3); echo "z=", $z, "\n"; }
function h(): void { $s = new SplFixedArray(2); $s[0] = new O('d'); $y = $s[0]; $s[0] = null; echo "y=", $y->n, "\n"; }
f([new O('a'), 2]); g(); h(); echo "end\n";
