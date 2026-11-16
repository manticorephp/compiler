<?php
// A dynamic method call `$o->$m(...$args)` or call_user_func_array([$o, $m], $args) with a string key naming no parameter returns instead of throwing "Unknown named parameter"
// issue: #705
final class P { public function __construct(public int $a = 0) {} public function m(int $x = 0): int { return $x; } }
function try_(string $label, callable $f): void {
    try { $r = $f(); echo $label, " ok ", var_export($r, true), "\n"; }
    catch (\Error $e) { echo $label, " ", get_class($e), ": ", $e->getMessage(), "\n"; }
}
$cls = $argc > 5 ? 'X' : 'P';
$m = $argc > 5 ? 'n' : 'm';
$args = ['zz' => 1];
try_('new $cls', fn() => (new $cls(...$args))->a);
try_('$o->$m', fn() => (new P())->$m(...$args));
try_('[$o,$m]', fn() => call_user_func_array([new P(), $m], $args));
