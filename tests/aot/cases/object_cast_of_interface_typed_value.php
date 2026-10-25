<?php
interface I {}
class C implements I { public $a = 1; protected $b = 2; }
class D extends C { public $c = 3; private $d = 4; }
abstract class AB { public $x = 1; }
class CB extends AB { public $y = 2; }
function mk(): I { return new D; }
function mkC(): C { return new D; }
function mkAB(): AB { return new CB; }
function mt(): Throwable { return new RuntimeException("x"); }
echo count((array) mk()), "\n";
echo count((array) mkC()), "\n";
echo count((array) mkAB()), "\n";
echo count((array) mt()) > 0 ? "ok" : "empty", "\n";
echo count(array_replace(["x" => 1], (array) mk())), "\n";
