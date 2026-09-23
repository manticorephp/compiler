<?php

// An out-parameter whose variable another argument reads: php evaluates the
// arguments first, so the subject is the incoming string, and the variable
// becomes the out-array afterwards.
function graphemes(string $s): int
{
    preg_match_all('/./u', $s, $s);
    return count($s[0]);
}
echo graphemes('héllo'), "\n";

$m = 'a1b22c333';
if (preg_match('/(\d+)/', $m, $m)) {
    echo $m[1], "\n";
}

$t = 'x-y-z';
$n = preg_match_all('/[a-z]/', $t, $t, PREG_SET_ORDER);
echo $n, ' ', $t[2][0], "\n";
