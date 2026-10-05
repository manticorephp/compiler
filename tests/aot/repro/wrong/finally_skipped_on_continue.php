<?php
// `continue` / `break` out of a `try` skips its `finally`
// issue: #40
$x = 0;
for ($i = 0; $i < 2; $i++) { try { continue; } finally { $x++; } }
echo $x, "\n";
foreach ([1] as $_) { try { break; } finally { $x += 10; } }
echo $x, "\n";
