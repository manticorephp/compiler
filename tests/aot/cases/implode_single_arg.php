<?php

// implode has a one-arg form: implode($array) with the separator defaulting to
// "". Reading the (absent) 2nd arg used to SIGSEGV the compiler.
var_dump(implode([1, 2, 3]));
var_dump(implode(["a", "b", "c"]));
var_dump(implode([1.5, "two", 3, true]));
var_dump(implode([]));
var_dump(join(["x", "y", "z"]));
// the two-arg form still works
var_dump(implode(",", [1, 2, 3]));
var_dump(implode("-", ["a", "b"]));
var_dump(implode("", []));
