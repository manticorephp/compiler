<?php

// ucwords honours a custom-delimiter 2nd arg.
var_dump(ucwords("hello world-foo", " -"));
var_dump(ucwords("hello world"));
var_dump(ucwords("a-b_c", "-_"));
var_dump(ucwords("foo.bar.baz", "."));

// sprintf/printf `%'X` custom pad char (no C-snprintf equivalent — routed to the
// runtime __mc_format engine).
var_dump(sprintf("%'*10.2f", 3.14159));
var_dump(sprintf("%'.10d", 42));
var_dump(sprintf("%'-10s", "hi"));
var_dump(sprintf("%'x8d", 5));
// ordinary specs still go through the compile-time translator.
var_dump(sprintf("%05.2f", 3.14159));
var_dump(sprintf("%-8s|", "x"));
var_dump(sprintf("%+d %x", 7, 255));
printf("%'#6s\n", "ab");
