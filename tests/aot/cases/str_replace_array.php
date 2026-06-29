<?php
// str_replace with array search/replace (PHP array|string params). The array
// form was previously unsupported — the array pointer was walked as a string,
// an intermittent out-of-bounds strlen (the rebuild SIGBUS flake).
var_dump(str_replace(["(", ")"], "", "f(a, b)"));
var_dump(str_replace(["a", "b", "c"], ["1", "2", "3"], "abcabc"));
var_dump(str_replace(["x", "y"], "_", "xyzzy"));
var_dump(str_replace("o", "0", "foobar"));   // scalar form still works
var_dump(str_replace(["?", "\\"], "", "a?b\\c"));
