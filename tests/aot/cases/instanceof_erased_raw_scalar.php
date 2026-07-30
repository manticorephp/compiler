<?php
// An erased carrier reaching `instanceof` / `is_*` can be a boxed cell, a raw
// object pointer, a raw array, a raw string — or a raw INT / BOOL / DOUBLE.
// The last group is what this pins down: `!= 0` is not a pointer test, so
// `inttoptr 1` and a load at ptr-8 read 0xFFFF…F9, and a raw double's bit
// pattern (3.14 = 0x40091EB851EB851F) is above every userspace address. The
// probe is now bounded on BOTH ends — > 0xFFFF and < 2^48 — so no erased scalar
// can be dereferenced, and containers still classify from the allocator tag.
//
// RESIDUAL, and it is a REPR problem, not a bounds problem: a raw int in the
// plausible-pointer window (e.g. 65536) is genuinely indistinguishable from a
// pointer, and the ptr-8 probe faults on it. No bound fixes that — the low bound
// cannot be raised past a real heap address (a static non-PIE Linux binary's
// heap sits well below 2^32). The fix is upstream: an array crossing into an
// erased/cell parameter must carry its element repr, so the consumer never has
// to guess. Until then, only an array whose elements are SMALL ints (< 2^16) or
// pointers is safe through an erased `instanceof` / `is_*`.
//
// KNOWN GAP (not asserted here): an enum CASE in an `obj<E>`-typed array is
// stored as its raw ORDINAL, so an erased slot cannot tell it from a small int
// and `instanceof` answers false. Boxing an enum case as it crosses into an
// erased/cell context is owed; the singleton already carries ENUM_TAG_MAGIC at
// data-8 so the raw classifier will recognise it once it does.

interface Marker
{
}

class Plain implements Marker
{
}

class Other
{
}

function classify(array $items): void
{
    foreach ($items as $it) {
        if ($it instanceof Other) { echo "OTHERCLS\n"; continue; }
        if ($it instanceof Marker) { echo "MARK\n"; continue; }
        if (\is_array($it)) { echo "ARR\n"; continue; }
        if (\is_object($it)) { echo "OBJ\n"; continue; }
        echo "SCALAR\n";
    }
}

classify([new Plain(), new Plain()]);
classify([new Other(), new Plain()]);
classify([1, 2, 0]);
classify([true, false]);
classify([[1], [2]]);
classify(['a', 'b']);
classify([1.5, 2.5]);
classify([-1, 65535]);
