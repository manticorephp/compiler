<?php
// One local is an owned raw array in one loop and a boxed int in the next: the
// int path meets the array at the loop head as a mismatch (the array is dropped
// on the way in), not as an empty slot the array's drop would run on.
$probe = [['a', 1], ['b', 2]];
foreach ($probe as $p) {
    echo $p[0], $p[1], "\n";
}
foreach (['EEST', 'GMT', 'LMT'] as $s) {
    $p = strlen($s) * 1000;
    echo $s, " ", $p, "\n";
}
echo "done\n";
