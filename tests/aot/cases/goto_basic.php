<?php
// goto with forward jumps, backward jumps, and jumping out of a loop.

// forward jump skipping code
echo "a\n";
goto skip;
echo "b\n";     // skipped
skip:
echo "c\n";

// jump out of a loop
for ($i = 0; $i < 10; $i++) {
    if ($i == 3) goto afterloop;
    echo $i;
}
afterloop:
echo "\n";

// backward jump forming a retry loop
$n = 0;
retry:
$n++;
if ($n < 3) goto retry;
echo "n=$n\n";

// goto inside a function, label as an early-exit target
function classify(int $x): string {
    if ($x < 0) goto neg;
    if ($x == 0) goto zero;
    return "pos";
    neg:
    return "neg";
    zero:
    return "zero";
}
echo classify(5), " ", classify(-2), " ", classify(0), "\n";

// goto out of a while(true)
$k = 0;
while (true) {
    $k++;
    if ($k > 5) goto out;
    continue;
}
out:
echo "k=$k\n";
