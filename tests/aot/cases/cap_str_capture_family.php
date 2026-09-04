<?php
// @epic: bare-alias-capture
// @why: symfony and doctrine use strstr/strcmp/strncmp throughout; the bare-name
//       alias binds them to the raw C functions in Runtime\Libc (see
//       tests/audit/data/alias-capture.tsv). C returns a pointer where PHP
//       returns a string, and C stops at the first NUL where PHP does not.

// Return shape: PHP gives a string, C gives a char* that lands as an int.
var_dump(strstr('user@example.com', '@'));
var_dump(strstr('hello world', 'lo w'));
var_dump(strstr('a@b', '@', true));     // third arg does not exist in C
var_dump(strchr('a/b', '/'));
var_dump(strrchr('a/b/c', '/'));

// NUL semantics: PHP strings are length-counted, C strings are NUL-terminated.
$a = "a\x00b";
$b = "a\x00c";
var_dump(strcmp($a, $b));
var_dump(strncmp($a, $b, 3));
var_dump(strcasecmp($a, $b));
var_dump(strncasecmp("AB\x00X", "ab\x00y", 4));
