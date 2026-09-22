<?php

// `unset()` on a name bound to storage the CALL does not own — a `static` local,
// a `global $x`, a superglobal — breaks the BINDING for the rest of that call
// and leaves the storage alone. The next call rebinds and sees the value still
// there; that is the whole point of `static`.
//
// The emitter used to release the cell and store 0 into it, which destroyed the
// shared storage: the counter below answered 1,0,1,0 where php answers 1,-2,3,4.

function counter(): int
{
    static $n = 0;
    if (!isset($n)) {
        return -1;
    }
    $n++;
    if ($n === 2) {
        unset($n);
        return -2;
    }
    return $n;
}

$G = 5;

function unbindGlobal(): string
{
    global $G;
    unset($G);
    return isset($G) ? 'still-bound' : 'unbound';
}

function readGlobal(): int
{
    global $G;
    return $G;
}

function unbindStatic(): string
{
    static $k = 'v';
    unset($k);
    return isset($k) ? 'still-bound' : 'unbound';
}

function readStatic(): string
{
    static $k = 'v';
    return $k;
}

echo counter(), ',', counter(), ',', counter(), ',', counter(), "\n";
echo unbindGlobal(), "\n";
echo readGlobal(), "\n";
echo unbindStatic(), "\n";
echo readStatic(), "\n";
