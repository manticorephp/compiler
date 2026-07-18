<?php

// class_exists / interface_exists / trait_exists / enum_exists with a name known
// only at RUN TIME. These used to fold to `false` — a silent wrong answer —
// because there was no runtime class table to ask. The global_ctors registry is
// that table.
//
// php's answers here are deliberately NOT uniform, and the rmeta flags encode
// exactly that: an ENUM *is* a class, while an interface and a trait are not.

interface I {}
trait T {}
enum E { case A; }
class C {}
class D extends C {}

$i = "I"; $t = "T"; $e = "E"; $c = "C"; $no = "Nope";

// The whole point: names known only at run time.
var_dump(class_exists($c));
var_dump(class_exists($i));      // false: an interface is not a class
var_dump(class_exists($t));      // false: nor a trait
var_dump(class_exists($e));      // TRUE: an enum IS a class
var_dump(class_exists($no));
var_dump(interface_exists($i));
var_dump(interface_exists($c));
var_dump(trait_exists($t));
var_dump(trait_exists($c));
var_dump(enum_exists($e));
var_dump(enum_exists($c));
// literals must keep folding, unchanged
var_dump(class_exists("D"));
var_dump(interface_exists("I"));
