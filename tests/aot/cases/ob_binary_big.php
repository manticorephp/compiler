<?php

// Binary-safe: NULs and high bytes survive a round trip through the buffer.
ob_start();
echo "a\x00b\xff\xfe";
echo "\x00";
$b = ob_get_clean();
echo "len=", strlen($b), " ord0=", ord($b[1]), " ord3=", ord($b[3]), "\n";

// Past every small-string size class, so the accumulator takes its grow path
// many times over.
ob_start();
for ($i = 0; $i < 20000; $i = $i + 1) {
    echo "0123456789";
}
$big = ob_get_clean();
echo "big=", strlen($big), "\n";
echo "head=", substr($big, 0, 10), " tail=", substr($big, -10), "\n";

// A peek mid-growth must not alias the accumulator: the borrow is +1, which
// forces the next append onto the copy path.
ob_start();
echo str_repeat("x", 100);
$snap = ob_get_contents();
echo str_repeat("y", 100);
$full = ob_get_clean();
echo "snap=", strlen($snap), " full=", strlen($full), "\n";
echo "snap-unchanged=", (strlen($snap) === 100 ? "yes" : "no"), "\n";
