<?php

// PHP echo / var_dump are binary-safe: a NUL byte in a string does NOT truncate
// the output. Native previously used printf("%s") (NUL-terminated) — it stopped
// at the first \x00. Now it writes exactly len bytes.

$s = "AB\x00CD";
echo strlen($s), "\n";
echo $s, "\n";
echo bin2hex($s), "\n";
var_dump($s);

// ordering must survive across mixed echo arms (string via write, the rest via
// buffered printf — a fflush keeps them in order)
echo "x", 1, "y\x00z", 2.5, "w", true, "\n";

// a NUL mid-string through concat and a built string
$b = "P" . "\x00" . "Q";
echo $b, "\n";
echo bin2hex($b), "\n";

// empty and null-ish
echo "", "|", "\n";
$n = null;
echo (string)$n, "|end\n";
