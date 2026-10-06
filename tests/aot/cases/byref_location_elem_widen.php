<?php
// The location must agree with the callee's representation: an element of a
// by-value PARAM array, of a PROPERTY array, or a typed PROPERTY itself handed
// to a by-ref param of another kind. The param array converts at entry, the
// property array's element channel becomes a cell, and a typed property is a
// typed reference — the write comes back coerced to the property's type.
function app(string &$x): void { $x .= 'a'; }
function toInt(string &$c): void { $c = 7; }
function retype(mixed &$v): void { $v = 'now a string'; }

/** @param int[] $p */
function viaParam(array $p): void { app($p[0]); var_dump($p); }
viaParam([1, 2]);

final class P {
    public string $s = 'q';
    /** @var int[] */
    public array $a = [1, 2];
}
$o = new P();
app($o->a[0]); var_dump($o->a);
retype($o->a[1]); var_dump($o->a);
toInt($o->s); var_dump($o->s);
app($o->s); var_dump($o->s);

// A BY-REF param's element: the buffer is the caller's, so the param becomes a
// cell-element array and the caller's array follows it.
/** @param int[] $p */
function viaRefParam(array &$p): void { app($p[1]); }
$q = [3, 4];
viaRefParam($q);
var_dump($q);
