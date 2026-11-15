<?php
// var_dump of an enum case returned from a function that read it out of an array param crashes past the first case.
// issue: #700
enum Level: int { case Debug = 100; case Info = 200; }
function first(array $x): Level { return $x[0]; }
var_dump(first([Level::Debug]));
var_dump(first([Level::Info]));
