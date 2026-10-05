<?php
namespace App;
class A { public $x = 1; }
var_dump(new A());
echo var_export(new A(), true), "\n", serialize(new A()), "\n";
