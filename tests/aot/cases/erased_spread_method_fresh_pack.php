<?php
// `$erased->m(...f())` over many declaring classes: an owned pack is unpacked once and released.
interface I { public function m(...$a); }
class A implements I { public function m(...$a) { return 'A' . implode(',', $a); } }
class B implements I { public function m(...$a) { return 'B' . count($a); } }
class C implements I { public function m(...$a) { return 'C' . ($a[0] ?? 'd') . ($a[1] ?? 'e'); } }
class D implements I { public function m(...$a) { return 'D' . json_encode($a); } }
class E implements I { public function m(...$a) { return 'E'; } }
class F implements I { public function m(...$a) { return 'F' . ($a[0] ?? '-'); } }
class G implements I { public function m(...$a) { return 'G' . count($a); } }
class H implements I { public function m(...$a) { return 'H' . strrev((string)($a[0] ?? '')); } }
class R { public function m(&$x = 0, ...$a) { $x = 'changed'; return 'R' . $x . count($a); } }
function pick(int $i): object { return [new A, new B, new C, new D, new E, new F, new G, new H, new R][$i]; }
function pack1(): array { return [1, 2, 3]; }
function viaFuncGetArgs($o, $x = 0, $y = 0) { return $o->m(...\array_slice(\func_get_args(), 1)); }
for ($i = 0; $i < 9; $i++) {
    echo pick($i)->m(...pack1()), ' ', pick($i)->m(...['x' . $i, 'y']), ' ', viaFuncGetArgs(pick($i), 7, 8), "\n";
}
$kept = pick(0);
$before = memory_get_usage();
for ($n = 0; $n < 200000; $n++) { $kept->m(...pack1()); }
echo memory_get_usage() - $before < 8000000 ? "flat\n" : "leak\n";
