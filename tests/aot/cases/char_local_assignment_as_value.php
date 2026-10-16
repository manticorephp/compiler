<?php
// A character local whose assignment is used as a value stays a string (`$v .= $p = $s[$i]`).
function f(string $d): string { $v = ""; $p = $d[0]; for ($i = 0; $i < strlen($d); $i++) { $v .= $p = $d[$i]; if (" " === $p) { break; } } return $v; }
echo f("abc"), "\n";
function g(string $d): string { $v = ""; $x = ""; for ($i = 0; $i < strlen($d); $i++) { $x = $v . ($p = $d[$i]); $v = $x; if ("z" === $p) { break; } } return $v; }
echo g("abc"), "\n";
