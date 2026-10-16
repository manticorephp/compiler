<?php
#[Attribute] class Z { function __construct(...$a){} }
#[Attribute] class A {}
#[Z(null, true, 1.5, "abcdefghijklmnopqrstuvwxyz", [1,2], k: false, m: "s")] #[A] class T {}
foreach ((new ReflectionClass("T"))->getAttributes() as $a) { echo $a; var_dump($a instanceof Stringable, $a instanceof Reflector); }
echo strlen((string)(new ReflectionClass("T"))->getAttributes()[1]), "
";
