<?php
// `&...$xs` packs REFERENCES: a write through `$xs[$i]` or a by-ref foreach
// over the pack reaches the caller's variables. The pack used to be a VALUE,
// so every write landed in a throwaway and vanished.
function many(string $p, &...$slots): void { foreach ($slots as &$s) { $s = $p . $s; } }
function first(&...$slots): void { $slots[0] = 'w'; }
function count_refs(int ...$n): int { return count($n); }
final class Box { public $v = 'p'; /** @var array<int, mixed> */ public array $a = ['e', 1]; }

$x = 'x'; $y = 'y';
many('v-', $x, $y); var_dump($x, $y);
first($x); var_dump($x);
$o = new Box();
many('o-', $o->v, $o->a[0]); var_dump($o->v, $o->a);
$arr = ['k' => 'a', 'm' => 'b', 'n' => 1];
many('e-', $arr['k'], $arr['m']); var_dump($arr);
many('none-');
echo count_refs(1, 2, 3), "\n";
// An array literal of references, iterated by reference, writes through too.
$p = 'p'; $q = 'q';
$refs = [&$p, &$q];
foreach ($refs as &$r) { $r .= '!'; }
unset($r);
var_dump($p, $q);
