<?php
var_dump(array_is_list([1, 2, 3]));
var_dump(array_is_list([]));
var_dump(array_is_list(["a" => 1]));
var_dump(array_is_list([1 => "x", 0 => "y"]));
var_dump(array_is_list(["x", "y", "z"]));
