<?php
// round() pre-rounds at 15 significant digits to cancel binary representation
// error (PHP php_round), so the classic 1.005 case rounds up.
echo round(1.005, 2), "\n";
echo round(2.675, 2), "\n";
echo round(1.255, 2), "\n";
echo round(-1.005, 2), "\n";
echo round(3.14159, 2), "\n";
echo round(2.0 / 3.0, 5), "\n";
echo round(1234.5678, 1), "\n";
echo round(2.5), " ", round(3.5), " ", round(-2.5), "\n";
var_dump(round(1.005, 2));
var_dump(round(0.285, 2));
