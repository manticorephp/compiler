<?php

// isset() asks whether a variable EXISTS, not whether it is truthy: 0, 0.0 and
// false are values and every one of them is set. The emitter tested the slot
// WORD against 0 — which is right for a pointer, where 0 is null, and wrong for
// a raw int/float/bool, so `$e = 0` and `static $a = 0` both answered "unset".
//
// The two cases a raw slot genuinely cannot tell apart keep the test: a name
// this function `unset()`s (that zeroes the slot), and a `static` declared null
// (MIR types it `int` and rides the null in the slot's zero).

function statZero(): string { static $a = 0; return isset($a) ? 'set' : 'unset'; }
function statSeven(): string { static $b = 7; return isset($b) ? 'set' : 'unset'; }
function statStr(): string { static $c = 'x'; return isset($c) ? 'set' : 'unset'; }
function statNull(): string { static $d = null; return isset($d) ? 'set' : 'unset'; }

function localZero(): string { $e = 0; return isset($e) ? 'set' : 'unset'; }
function localFalse(): string { $f = false; return isset($f) ? 'set' : 'unset'; }
function localFloatZero(): string { $g = 0.0; return isset($g) ? 'set' : 'unset'; }
function localEmptyStr(): string { $h = ''; return isset($h) ? 'set' : 'unset'; }

function afterUnset(): string
{
    $i = 0;
    unset($i);
    return isset($i) ? 'set' : 'unset';
}

function nullableStaysNull(): string
{
    $j = null;
    return isset($j) ? 'set' : 'unset';
}

echo 'static 0    : ', statZero(), "\n";
echo 'static 7    : ', statSeven(), "\n";
echo 'static str  : ', statStr(), "\n";
echo 'static null : ', statNull(), "\n";
echo 'local 0     : ', localZero(), "\n";
echo 'local false : ', localFalse(), "\n";
echo 'local 0.0   : ', localFloatZero(), "\n";
echo 'local ""    : ', localEmptyStr(), "\n";
echo 'after unset : ', afterUnset(), "\n";
echo 'local null  : ', nullableStaysNull(), "\n";
