<?php

// A static call whose RECEIVER CLASS is declared nowhere is php's runtime
// `Error: Class "X" not found`, raised only if the call is REACHED. Emitting
// the call anyway put an undefined symbol in the module and clang refused the
// whole build -- strictly stricter than php.
//
// This is what an ext-only branch looks like from inside a closed world:
// symfony/var-dumper's FFICaster calls \FFI::cdef() in a path that can only run
// when an FFI\CData exists, which without ext-ffi it never does.

$never = getenv('MANTICORE_NEVER_SET_XYZ');

if ($never === 'yes') {
    echo NoSuchClass::doThing(1), "\n";
}
echo "unreached branch cost nothing\n";

// Reached, so it must throw -- with php's message, catchable as Error.
try {
    $r = AlsoMissing::compute(2, 3);
    echo 'no throw: ', $r, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

// A class that DOES exist but lacks the method reads differently, and that
// distinction is the whole point of the message split.
class Present
{
    public static function here(): string { return 'here'; }
}

echo Present::here(), "\n";

try {
    echo Present::absent(), "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
