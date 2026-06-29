<?php
// Binary-safe strings: a NUL byte must NOT truncate concat or .= — length
// comes from the len-prefix header (len@-16), not a libc strlen scan.
$nul = chr(0);
$a = "x" . $nul . "y";
$b = "z" . $nul . "w";
echo strlen($a), "\n";              // 3
echo strlen($a . $b), "\n";        // 6  (fused/runtime concat)
echo strlen($a . $b . "!"), "\n";  // 7  (fused 3-way)
$s = "";
for ($i = 0; $i < 3; $i++) { $s .= "a" . $nul; }
echo strlen($s), "\n";             // 6  (in-place append)
