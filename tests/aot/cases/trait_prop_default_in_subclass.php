<?php
trait HT { public $ts = true; public $n = 5; }
class A { use HT; }
abstract class M { use HT; public function __construct() {} }
class B extends M {}
abstract class M2 { use HT; }
class C extends M2 { }
class D extends M2 { public function __construct() { } }
var_dump((new A)->ts, (new B)->ts, (new C)->ts, (new D)->ts, (new B)->n);
