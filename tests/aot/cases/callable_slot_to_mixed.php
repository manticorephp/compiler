<?php
// A `callable` parameter holding a callable array reaches a `mixed` consumer as the array it is.
final class S { public function m(string $x): string { return "m:$x"; } }
function ids(mixed $v): mixed { return $v; }
function kind(mixed $v): string { return get_debug_type($v); }
function pass(callable $c): string { return kind($c) . ' ' . (is_array($c) ? $c[1] : '-'); }
echo pass(ids([new S(), 'm'])), "\n";
echo pass(ids(fn () => 1)), "\n";
