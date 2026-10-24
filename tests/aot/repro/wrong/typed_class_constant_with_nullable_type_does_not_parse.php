<?php
// A typed class constant with a nullable type (PHP 8.3) is a parse error here
// issue: #418
class A { const ?int X = null; const string|int Y = 5; }
var_dump(A::X, A::Y);
