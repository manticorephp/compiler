<?php
$x = [1, 2, 3];
var_dump(is_array($x));
var_dump(is_int(5));
var_dump(is_string("a"));
var_dump(is_array("no"));
var_dump(is_bool(true));
$m = ["k" => [1, 2]];
var_dump(is_array($m["k"]));
echo is_array($x) ? "yes\n" : "no\n";
