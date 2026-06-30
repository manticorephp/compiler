<?php
// Mandelbrot — float math, nested loops, bailout. Counts in-set pixels on a
// fixed grid (deterministic int output, no float-format parity risk).

$w = 200; $h = 200;
$maxIter = 1000;
$inSet = 0;
$checksum = 0;

for ($py = 0; $py < $h; $py++) {
    $y0 = ($py / $h) * 2.0 - 1.0;
    for ($px = 0; $px < $w; $px++) {
        $x0 = ($px / $w) * 3.0 - 2.0;
        $x = 0.0; $y = 0.0;
        $iter = 0;
        while ($iter < $maxIter) {
            $x2 = $x * $x;
            $y2 = $y * $y;
            if ($x2 + $y2 > 4.0) { break; }
            $y = 2.0 * $x * $y + $y0;
            $x = $x2 - $y2 + $x0;
            $iter++;
        }
        if ($iter === $maxIter) { $inSet++; }
        $checksum += $iter;
    }
}

echo "inSet=", $inSet, "\n";
echo "checksum=", $checksum, "\n";
