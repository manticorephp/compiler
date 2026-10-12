<?php
class Svc { public function get(string $host, string $key): string { return "$host/$key"; } }
function two(int $a, int $b): int { return $a + $b; }

function attempt(string $label, callable $f): void {
    try { $v = var_export($f(), true); echo $label, ': ', $v, "\n"; }
    catch (ArgumentCountError $e) { echo $label, ": ArgumentCountError\n"; }
}

$s = new Svc();
$m = 'get';
$args = [];
$fn = 'two';
attempt('direct', fn() => two(1));
attempt('method', fn() => $s->get('h'));
attempt('dyn-method', fn() => $s->$m('h'));
attempt('dyn-method-spread', fn() => $s->$m(...['h']));
attempt('dyn-method-lead-spread', fn() => $s->$m('h', ...$args));
attempt('dyn-func', fn() => $fn(1));
attempt('call_user_func', fn() => call_user_func([$s, 'get'], 'h'));
