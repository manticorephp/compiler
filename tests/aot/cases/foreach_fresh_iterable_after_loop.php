<?php

// A foreach over a fresh array (a literal, a call result) and the loop
// variables read AFTER the loop: the value and key must stay alive however the
// loop is left. Regression guard for the owned-iterable release.
final class D { public function __construct(public string $n) {} }
function mk(string $p): array { return [$p . 'x', $p . 'y']; }
function objs(string $p): array { return [new D($p . '1'), new D($p . '2')]; }
function keyed(string $p): array { return [$p . 'k1' => 1, $p . 'k2' => 2]; }
function f1(string $p): string { foreach (mk($p) as $v) { } return $v; }
function f2(string $p): string { foreach (keyed($p) as $k => $n) { } return $k . $n; }
function f3(string $p): string { foreach (objs($p) as $o) { if ($o->n === $p . '1') { break; } } echo "after\n"; return $o->n; }
function f4(string $p): string { foreach ([$p . 'a', $p . 'b'] as $i => $v) { if ($i === 0) { continue; } } return $v . $i; }
echo f1('a'), "\n", f2('b'), "\n", f3('c'), "\n", f4('d'), "\n";
