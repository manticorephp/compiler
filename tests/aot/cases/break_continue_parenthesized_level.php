<?php
// break(1); / continue(1); with a parenthesized level fails to parse: "expected ';' after break".
foreach ([1, 2, 3] as $i) { if ($i === 2) { break(1); } echo $i, "\n"; }
for ($i = 0; $i < 3; $i++) { foreach ([1, 2] as $j) { if ($j === 2) continue((2)); echo "$i$j "; } }
echo "\n";
