<?php
// A get hook returning an int for a float-typed property is not converted int to float, so var_dump prints float(5.0E-324).
// issue: #490
class A { public float $f { get => 1; } public float|string $u { get => 2; } }
$a = new A();
var_dump($a->f);
var_dump($a->u);
