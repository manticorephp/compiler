<?php
// ReflectionMethod::invokeArgs on __construct of a newInstanceWithoutConstructor() object does not run the promoted constructor
// issue: #570
class K { public function __construct(public int $a = 0, public string $b = "") { } }
$o = (new ReflectionClass("K"))->newInstanceWithoutConstructor();
(new ReflectionMethod($o, "__construct"))->invokeArgs($o, [2, "y"]);
echo $o->a, $o->b, "\n";
