<?php
$x = 0;
for ($i = 0; $i < 3; $i++) { try { if ($i === 1) { continue; } if ($i === 2) { break; } } finally { $x++; } }
echo $x, "\n";
function r(): int { try { return 1; } finally { echo "fin\n"; } }
echo r(), "\n";
