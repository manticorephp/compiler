<?php

// `throw $e` where $e came off an ERASED channel.
//
// A value read out of an `array<int,mixed>` is a NaN-BOXED word, not an object
// address. emitThrow stored it straight through an inttoptr, so the tag bits
// became the pointer and the catch arm dereferenced 0xfff8_0000_xxxx_xxxx on the
// first ->getMessage(). It reproduced with nothing but a docblock: the SAME code
// with no `@var array<int,mixed>` annotation typed the element concretely and
// ran fine, which is what made it look like an FFI bug when ext/curl's callback
// trampolines first hit it.

final class Park
{
    /** @var array<int,mixed> slot => a Throwable parked for a later rethrow */
    public static array $err = [];
    /** @var array<string,mixed> */
    public static array $byName = [];
}

function park_it(): void
{
    try {
        throw new \RuntimeException('boom');
    } catch (\Throwable $e) {
        Park::$err[1] = $e;
        Park::$byName['later'] = $e;
    }
}

function rethrow_it(): void
{
    $e = Park::$err[1];
    unset(Park::$err[1]);
    throw $e;
}

/** Straight out of the array, with no local in between. */
function rethrow_direct(): void
{
    throw Park::$byName['later'];
}

\park_it();

try {
    \rethrow_it();
    echo "via local:  NO THROW\n";
} catch (\RuntimeException $x) {
    echo "via local:  ", $x->getMessage(), " (", \get_class($x), ")\n";
}

try {
    \rethrow_direct();
    echo "direct:     NO THROW\n";
} catch (\Throwable $x) {
    echo "direct:     ", $x->getMessage(), " (", \get_class($x), ")\n";
}

// A cell holding a SUBCLASS still has to match its parent's catch arm.
$mixed = [];
$mixed[0] = new \LogicException('logic');
try {
    throw $mixed[0];
} catch (\LogicException $x) {
    echo "subclass:   ", $x->getMessage(), "\n";
}

// And the catch-by-interface arm, which reads the same erased word.
$any = [];
$any['e'] = new \InvalidArgumentException('iface');
try {
    throw $any['e'];
} catch (\Throwable $x) {
    echo "interface:  ", $x->getMessage(), " (", \get_class($x), ")\n";
}
