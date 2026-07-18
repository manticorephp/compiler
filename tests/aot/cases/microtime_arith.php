<?php
// microtime(true)/hrtime(true) return a concrete float/int, so arithmetic on them
// is a real float/int op — not cell-cell truncated to int(0).
$a = microtime(true); $b = microtime(true);
var_dump(is_float($b - $a));
var_dump(($b - $a) >= 0.0 && ($b - $a) < 5.0);
$h0 = hrtime(true); $h1 = hrtime(true);
var_dump(is_int($h1 - $h0));
var_dump(($h1 - $h0) >= 0);
var_dump(is_string(microtime()));       // string form intact
var_dump(is_array(hrtime()));           // pair form intact
echo "done\n";
