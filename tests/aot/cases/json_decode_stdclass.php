<?php
// php's DEFAULT decode builds stdClass, not assoc arrays. `$associative` was
// declared and ignored, and its default was `true` rather than php's `null`, so
// every property read below was unreachable.
//
// The class is asserted with get_class(), not get_debug_type(): over a CELL the
// latter loses the class name and answers a bare "object", because it resolves
// through a prelude instanceof chain while get_class() resolves off the class
// id. That is a get_debug_type gap, not a decode one — see
// tests/aot/cases/get_debug_type_cell_object.php.

$doc = '{"a":1,"b":"z","c":[1,2],"d":{"e":true,"f":null}}';

$o = json_decode($doc);
echo get_class($o), "\n";
echo $o->a, " ", $o->b, "\n";
echo $o->c[0], $o->c[1], "\n";
echo get_class($o->d), " ", var_export($o->d->e, true), " ", var_export($o->d->f, true), "\n";
echo is_object($o) ? "obj" : "notobj", "\n";
echo $o instanceof stdClass ? "std" : "notstd", "\n";

// Explicit true keeps arrays; explicit false and null both build objects.
$a = json_decode($doc, true);
echo get_debug_type($a), " ", $a["a"], " ", $a["d"]["e"] ? "t" : "f", "\n";

$f = json_decode($doc, false);
echo get_class($f), " ", $f->a, "\n";

$n = json_decode($doc, null);
echo get_class($n), " ", $n->a, "\n";

// JSON_OBJECT_AS_ARRAY makes the null default mean arrays.
$oa = json_decode($doc, null, 512, JSON_OBJECT_AS_ARRAY);
echo get_debug_type($oa), " ", $oa["a"], "\n";

// A top-level array stays an array under every setting.
echo get_debug_type(json_decode('[1,2]')), "\n";
echo json_encode(json_decode($doc, true)), "\n";
