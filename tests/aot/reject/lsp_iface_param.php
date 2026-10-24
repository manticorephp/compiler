<?php
interface I { public function f(int $a): void; }
class B implements I { public function f(string $a): void {} }
