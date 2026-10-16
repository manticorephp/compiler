<?php
// `(array)` of a value typed by an interface (Throwable or a user interface) answers a garbage array or crashes.
// issue: #255
interface I {}
class C implements I { public $a = 1; protected $b = 2; }
function mk(): I { return new C; }
function mt(): Throwable { return new RuntimeException("x"); }
echo count((array) mk()), "\n";
echo count((array) mt()), "\n";
