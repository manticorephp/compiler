<?php
class A {
  public function m0($x = 0) { return "A.m0:" . $x; }
  public function m1($x = 0) { return "A.m1:" . $x; }
  public function m2($x = 0) { return "A.m2:" . $x; }
  public function m3($x = 0) { return "A.m3:" . $x; }
  public function m4($x = 0) { return "A.m4:" . $x; }
  public function m5($x = 0) { return "A.m5:" . $x; }
  public function m6($x = 0) { return "A.m6:" . $x; }
  public function m7($x = 0) { return "A.m7:" . $x; }
  public function m8($x = 0) { return "A.m8:" . $x; }
  public function m9($x = 0) { return "A.m9:" . $x; }
  public function m10($x = 0) { return "A.m10:" . $x; }
  public function m11($x = 0) { return "A.m11:" . $x; }
  public function m12($x = 0) { return "A.m12:" . $x; }
  public function m13($x = 0) { return "A.m13:" . $x; }
  public function m14($x = 0) { return "A.m14:" . $x; }
  public function m15($x = 0) { return "A.m15:" . $x; }
  public function m16($x = 0) { return "A.m16:" . $x; }
  public function m17($x = 0) { return "A.m17:" . $x; }
  public function m18($x = 0) { return "A.m18:" . $x; }
  public function m19($x = 0) { return "A.m19:" . $x; }
  public function sum(...$xs) { return "A.sum:" . array_sum($xs); }
  public function inc(&$x) { $x = $x + 1; return "A.inc:" . $x; }
  public function tag($a, ...$rest) { return "A.tag:" . $a . count($rest); }
}
class B {
  public function m0($x = 0) { return "B.m0:" . $x; }
  public function m1($x = 0) { return "B.m1:" . $x; }
  public function m2($x = 0) { return "B.m2:" . $x; }
  public function m3($x = 0) { return "B.m3:" . $x; }
  public function m4($x = 0) { return "B.m4:" . $x; }
  public function m5($x = 0) { return "B.m5:" . $x; }
  public function m6($x = 0) { return "B.m6:" . $x; }
  public function m7($x = 0) { return "B.m7:" . $x; }
  public function m8($x = 0) { return "B.m8:" . $x; }
  public function m9($x = 0) { return "B.m9:" . $x; }
  public function m10($x = 0) { return "B.m10:" . $x; }
  public function m11($x = 0) { return "B.m11:" . $x; }
  public function m12($x = 0) { return "B.m12:" . $x; }
  public function m13($x = 0) { return "B.m13:" . $x; }
  public function m14($x = 0) { return "B.m14:" . $x; }
  public function m15($x = 0) { return "B.m15:" . $x; }
  public function m16($x = 0) { return "B.m16:" . $x; }
  public function m17($x = 0) { return "B.m17:" . $x; }
  public function m18($x = 0) { return "B.m18:" . $x; }
  public function m19($x = 0) { return "B.m19:" . $x; }
  public function sum(...$xs) { return "B.sum:" . array_sum($xs); }
  public function inc(&$x) { $x = $x + 1; return "B.inc:" . $x; }
  public function tag($a, ...$rest) { return "B.tag:" . $a . count($rest); }
}
function fwd(object $o, $m, $args) { return $o->$m(...$args); }
foreach ([new A, new B] as $ob) {
  $a = [10]; echo fwd($ob, "inc", $a), " ", $a[0], "\n";
  $a = [1, 2, 3]; echo fwd($ob, "sum", $a), "\n";
  $a = [7, 8, 9]; echo fwd($ob, "tag", $a), "\n";
  $a = [5]; echo fwd($ob, "m4", $a), "\n";
}
