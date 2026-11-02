<?php
// Overwriting an element of a declared string[] whose buffer holds boxed cells (sort() of numeric-like strings out of a docblock-typed array<string, string[]>) releases the NaN-boxed word and segfaults.
/** @return array<string, string[]> */
function grp(string $s): array
{
    $out = [];
    foreach (explode('|', $s) as $p) {
        $out[substr($p, -1)][] = $p;
    }
    return $out;
}
$cli = grp('3a|2b|1a|0a');
$theirs = $cli['a'];
sort($theirs);
echo implode(',', $theirs), ' ', implode(',', $cli['a']), "\n";
$more = $cli['b'];
$more[] = '9b';
rsort($more);
echo implode(',', $more), ' ', implode(',', $cli['b']), "\n";
