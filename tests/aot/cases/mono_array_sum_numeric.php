<?php
// array_sum specializes per element type (P4): vec[int] keeps an i64
// accumulator, vec[float] a native double (no lossy box). The loop-carried
// `$sum` widens int->float on the first float element.
echo array_sum([1, 2, 3]), "\n";              // 6 (int)
echo array_sum([10, 20, 30, 40]), "\n";       // 100 (int)
echo array_sum([1.5, 2.5, 3.0]), "\n";        // 7 (float, exact)
echo array_sum([0.1, 0.2, 0.3]), "\n";        // 0.6 (float, full mantissa)
echo array_sum([0.5, 0.25, 0.125]), "\n";     // 0.875
$mixed = array_sum([1, 2, 3]) + array_sum([0.5, 0.5]);
echo $mixed, "\n";                             // 7
