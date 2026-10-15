<?php
// A write of an undeclared property, or an unset of a declared one, on a readonly class does not throw.
final readonly class R { public function __construct(public string $v) {} }
function t(callable $f): void { try { $f(); echo "no throw\n"; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; } }
t(function () { $n = new R('1'); $n->foo = 2; });
t(function () { $n = new R('1'); unset($n->v); });
t(function () { $n = new R('1'); $n->v = 'x'; });
