<?php
// var_dump of a typed float uses the shortest round-trip representation (PHP
// serialize_precision = -1), not the lossy cell box. Multiple args supported.
var_dump(0.1);
var_dump(0.1 + 0.2);
var_dump(1 / 3);
var_dump(1.5);
var_dump(100.0);
var_dump(1000000.0);
var_dump(0.0);
var_dump(-0.5);
var_dump(-3.14159);
var_dump(2.0 / 3.0);
var_dump(1.5, 0.25, 42, "x", true, null);
