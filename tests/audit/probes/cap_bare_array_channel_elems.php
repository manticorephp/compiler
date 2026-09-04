<?php

// The element-repr root, on the plainest shape there is — no union, no closure,
// no by-ref, no erased receiver. A bare `array` hint erases the ELEMENT channel
// (`array` lowers to KIND_UNKNOWN by design), so a concrete-element array
// crossing it is stored raw and read back as cells:
//
//   php:  array(2) { ["a"]=> int(1) ["expiry"]=> int(7) }
//   ours: array(2) { ["a"]=> float(5.0E-324) ["expiry"]=> float(3.5E-323) }
//
// 5.0E-324 is the double whose bit pattern is 1 — the int read through the
// wrong repr. This is the same wall the by-ref out-parameter work hit from
// three directions (byref-out-array-element-repr) and what the element-repr
// epic has to close: only retyping the erased element channel CELL does it.
//
// Found while fixing the array UNION operator, which turned out to be innocent:
// the identical value crosses correctly when it never touches a bare-`array`
// parameter or property.

final class Wrapper
{
    public array $metadata = [];

    public function __construct(array $m = [])
    {
        $this->metadata = $m;
    }
}

// (1) literal → bare-array ctor param → bare-array property → read
var_dump((new Wrapper(['a' => 1, 'expiry' => 7]))->metadata);

// (2) the same through a local first — the local's own type is concrete
$m = ['a' => 1, 'expiry' => 7];
var_dump((new Wrapper($m))->metadata);

// (3) CONTROL: never crossing the erased channel, so this one is correct today
var_dump(['a' => 1, 'expiry' => 7]);

// (4) CONTROL: a string-element array crosses the same channel — a raw char*
//     read as a cell is worse than a wrong number
var_dump((new Wrapper(['k' => 'v']))->metadata);
