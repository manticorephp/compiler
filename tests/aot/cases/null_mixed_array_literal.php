<?php
// An array LITERAL with a null element beside a concrete one must ride a CELL
// element: unionTypes(null, string) collapses to bare string, dropping the null,
// so the null would store as a raw ptr 0 read back as float(0)/garbage. A local
// is rescued by the local-element scan; a literal straight into a cell prop is
// not. A WHOLE read of such a string-keyed cell-array mixed prop (var_dump /
// is_array / return) also works: the slot boxes as a tagged array cell (a VEC
// cell-array key buffer, the SPL shape, stays raw).

class C { public mixed $d; }

$c = new C();
$c->d = ["k" => null, "j" => "x"];
var_dump($c->d["k"]);        // NULL
var_dump($c->d["j"]);        // string "x"
echo gettype($c->d["k"]), " ", gettype($c->d["j"]), "\n";
echo ($c->d["k"] ?? "def"), "\n";
var_dump($c->d);             // array(2){ NULL, "x" }
var_dump(is_array($c->d));   // true
echo gettype($c->d), "\n";   // array

// whole read via a mixed return
class Box { public mixed $b; public function get(): mixed { return $this->b; } }
$bx = new Box();
$bx->b = ["a" => 1.5, "z" => null];
var_dump($bx->get());

// null beside int
$n = new C();
$n->d = ["p" => 5, "q" => null];
var_dump($n->d["q"]);        // NULL
echo ($n->d["q"] ?? "def"), "\n";
var_dump($n->d["p"]);        // int(5)

// null beside float (unmasks NaN-box)
$f = new C();
$f->d = ["r" => 1.5, "s" => null];
var_dump($f->d["r"]);        // float(1.5)
var_dump($f->d["s"]);        // NULL

// vec mixed prop, element reads
class V { public mixed $v; }
$vo = new V();
$vo->v = ["a", null, "c"];
var_dump($vo->v[0]);         // string "a"
var_dump($vo->v[1]);         // NULL
var_dump($vo->v[2]);         // string "c"

// LOCAL null-mix whole read (rescued by the local-element scan)
$loc = ["k" => null, "j" => "x", "m" => 3];
var_dump($loc);
$vloc = ["a", null, "c"];
var_dump($vloc);
