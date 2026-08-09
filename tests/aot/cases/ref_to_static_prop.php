<?php

// `$r = &C::$s` — a reference whose SOURCE is a static property.
//
// A static property is an external-linkage global, so it is addressable exactly
// like an instance property or an array element, both of which already bind
// correctly. It simply had no arm: lowerRefAssign fell through to a value COPY
// and emitRefAddr's "not addressable" branch did the same, so the bind was
// silently a snapshot. Every write through the alias was lost, and nothing said
// so — a wrong answer, not a refusal.
//
// The other three shapes are here as CONTROLS: they were already correct, and a
// change to the address path must not disturb them.

class Reg
{
    public static int $count = 1;
    public static string $name = 'a';
    public static mixed $any = 1;
    /** @var array<string,int> */
    public static array $map = ['k' => 1];
}

// write THROUGH the alias — the direction that was broken
$c = &Reg::$count;
$c = 40;
var_dump(Reg::$count, $c);

// write through the PROPERTY — the alias must observe it
Reg::$count = 7;
var_dump(Reg::$count, $c);

// a string slot
$n = &Reg::$name;
$n = 'zzz';
var_dump(Reg::$name, $n);

// A `mixed` static is a CELL slot: its own store side NaN-boxes and its read
// side decodes by tag. A reference has to agree with the slot's
// REPRESENTATION, not just its address — a raw write here made the next read a
// denormal. Both directions, because they take different paths.
$m = &Reg::$any;
$m = 'now a string';
var_dump(Reg::$any, $m);
Reg::$any = 'from the property side';
var_dump(Reg::$any, $m);

// an ELEMENT of a static array, reached through the alias
$mp = &Reg::$map;
$mp['k'] = 99;
$mp['fresh'] = 5;
var_dump(Reg::$map);

// ── CONTROLS: already correct before this change ──
$b = 1;
$a = &$b;
$a = 10;
var_dump($b, $a);

class P { public int $n = 1; }
$o = new P();
$r = &$o->n;
$r = 20;
var_dump($o->n, $r);

$arr = ['k' => 1];
$e = &$arr['k'];
$e = 30;
var_dump($arr['k'], $e);

// a class CONSTANT is a StaticAccess too, and is NOT a slot — it must keep
// taking the plain copy path rather than being treated as addressable
class K { const V = 5; }
$k = K::V;
var_dump($k);
