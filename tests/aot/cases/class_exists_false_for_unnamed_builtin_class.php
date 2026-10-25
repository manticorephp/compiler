<?php
// class_exists() of a built-in class the program never names statically answers false
$n = 'Date' . 'Time';
var_dump(class_exists($n));
