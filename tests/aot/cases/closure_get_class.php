<?php
// get_class / get_debug_type of a closure answer Closure — typed, and through a
// mixed or object slot, where the env (no class id) answered '' / "object".
function viaMixed(mixed $v): string { return get_class($v) . " " . get_debug_type($v); }
function viaObject(object $v): string { return get_class($v) . " " . get_debug_type($v); }
class K { public function m(): int { return 1; } }
$f = fn() => 1;
$x = 5;
$g = function () use ($x) { return $x; };
echo get_class($f), " ", get_debug_type($f), "\n";
echo viaMixed($f), "\n", viaMixed($g), "\n", viaObject($g), "\n";
echo viaMixed((new K())->m(...)), "\n";
echo viaMixed(new K()), " ", viaObject(new ArrayObject([])), "\n";
