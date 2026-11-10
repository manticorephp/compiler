<?php
abstract class A { abstract function f(); }
class B extends A {}
class P { final function g() {} }
class Q extends P { function g() {} }
