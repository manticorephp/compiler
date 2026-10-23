<?php
// class_exists() of a built-in class the program never names statically answers false
// issue: #409
$n = 'Date' . 'Time';
var_dump(class_exists($n));
