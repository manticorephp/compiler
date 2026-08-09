<?php

// A dynamic method name called with a SPREAD: `$o->$m(...$args)`. The argument
// COUNT at the call site is one Spread_ node, never the callee's arity, so the
// dispatch's arity filter must not read it as "exactly one argument" — doing so
// dropped every arm and the call silently answered null.

class Closer
{
    public function __construct(public string $name) {}
    public function close(): void { echo "closed ", $this->name, "\n"; }
    public function tag(string $t, int $n): void { echo "tag $t$n ", $this->name, "\n"; }
}

function call_any(mixed $cb, array $args): mixed
{
    if (\is_array($cb)) {
        $o = $cb[0];
        $m = $cb[1];
        return $o->$m(...$args);
    }
    return $cb(...$args);
}

$c = new Closer("db");

call_any([$c, "close"], []);
call_any([$c, "tag"], ["x", 7]);

// The same shape through the queue php's shutdown handler uses.
register_shutdown_function([$c, "close"]);
register_shutdown_function([$c, "tag"], "late", 1);

echo "body done\n";
