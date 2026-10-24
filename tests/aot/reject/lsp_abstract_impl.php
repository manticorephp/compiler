<?php
abstract class A { abstract public function f(string $s): array; }
class B extends A { public function f(string $s): iterable { return []; } }
