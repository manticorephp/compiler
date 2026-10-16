<?php
// ReflectionMethod::IS_PUBLIC is an undefined constant
class A { public function f() {} private function g() {} }
$n = [];
foreach ((new ReflectionClass('A'))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) { $n[] = $m->name; }
echo implode(',', $n), "\n";
