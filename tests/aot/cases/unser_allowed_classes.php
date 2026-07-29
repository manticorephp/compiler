<?php

// `allowed_classes`: true (default) restores anything the build knows, false
// restores none, an array names the ones allowed. Anything excluded — and any
// class the closed-world table does not know, which is php's answer for a class
// that does not exist — becomes __PHP_Incomplete_Class.
//
// Asserted through get_class() and the name property, NOT var_dump: php keeps
// the property names MANGLED on an incomplete object and we store them
// demangled, and the object id is a fixed #1 here.

class Allowed
{
    public int $a = 1;
}

class Blocked
{
    public int $b = 2;
}

$sa = serialize(new Allowed());
$sb = serialize(new Blocked());

$d = unserialize($sa);
var_dump(get_class($d), $d->a);

$t = unserialize($sa, ['allowed_classes' => true]);
var_dump(get_class($t), $t->a);

// The name and the carried properties are read through an ARRAY CAST, never
// `$o->prop`: php warns on any property access on an incomplete object, and a
// php CLI warning goes to STDOUT, which would poison this file's expected out.
$f = unserialize($sa, ['allowed_classes' => false]);
var_dump(get_class($f));
$fa = (array)$f;
var_dump($fa['__PHP_Incomplete_Class_Name']);

$l = unserialize($sa, ['allowed_classes' => ['Allowed']]);
var_dump(get_class($l), $l->a);

$n = unserialize($sb, ['allowed_classes' => ['Allowed']]);
var_dump(get_class($n));
$na = (array)$n;
var_dump($na['__PHP_Incomplete_Class_Name']);

// A class this build has never heard of.
$u = unserialize('O:7:"Missing":1:{s:1:"q";i:9;}');
var_dump(get_class($u));
$ua = (array)$u;
var_dump($ua['__PHP_Incomplete_Class_Name']);
var_dump($ua['q']);

// stdClass is a class too.
$o = new stdClass();
$o->k = 1;
$so = unserialize(serialize($o), ['allowed_classes' => false]);
var_dump(get_class($so));

// Scalars and arrays are unaffected by the option.
var_dump(unserialize(serialize([1, 'a', 2.5]), ['allowed_classes' => false]));
