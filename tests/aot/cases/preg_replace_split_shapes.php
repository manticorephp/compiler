<?php
$subs = ['', 'a', 'ab', 'abc', 'hello world foo bar', "a1b22c333", "ünï cödé ж", str_repeat('ab ', 21), "x\0y z", "  lead and trail  "];
function show($r, $c = null) { echo json_encode($r), ' ', json_encode($c), "\n"; }
foreach ($subs as $s) {
    show(preg_replace('/o/', '0', $s, -1, $c), $c);
    show(preg_replace('/(\w)(\w)/', '$2\1${1}x$0', $s, -1, $c), $c);
    show(preg_replace('/(\w)(\d)?/', '[$2|$1]', $s, 2, $c), $c);
    show(preg_replace('/a*/', '-', $s, -1, $c), $c);
    show(preg_replace('/\s+/', ' ', $s, -1, $c), $c);
    show(preg_replace('/x/u', 'y', $s, -1, $c), $c);
    show(preg_replace('/(?<w>\w+)/', '<$1>', $s, -1, $c), $c);
    show(preg_replace('/^/m', '> ', $s, -1, $c), $c);
    show(preg_replace(['/a/', '/b/'], ['b', 'c'], $s, -1, $c), $c);
    show(preg_replace_callback('/\w+/', fn($m) => strtoupper($m[0]), $s, -1, $c), $c);
    show(preg_replace_callback('/(\w)(\d)?/', fn($m) => count($m) . ':' . json_encode($m), $s, -1, $c), $c);
    show(preg_replace_callback('/(?<l>\w)(?<d>\d)?/', fn($m) => json_encode($m), $s, 3, $c), $c);
    show(preg_replace_callback('/a*/', fn($m) => '<' . $m[0] . '>', $s, -1, $c), $c);
    show(preg_split('/\s+/', $s), 0);
    show(preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY), 0);
    show(preg_split('/(\s)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE), 0);
    show(preg_split('/(\s)|(,)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY), 0);
    show(preg_split('//', $s, -1, PREG_SPLIT_NO_EMPTY), 0);
    show(preg_split('/o/', $s, 2), 0);
    show(preg_split('/\d/', $s, 3, PREG_SPLIT_NO_EMPTY), 0);
}
$big = str_repeat("word, other-word; x\n", 5000);
echo strlen(preg_replace('/o/', '0', $big, -1, $c)), " $c\n";
echo strlen(preg_replace('/(w)(o)/', '$2$1', $big, -1, $c)), " $c\n";
echo strlen(preg_replace_callback('/\w+/', fn($m) => strrev($m[0]), $big, -1, $c)), " $c\n";
echo count(preg_split('/[\s,;]+/', $big)), "\n";
@preg_replace('/./u', 'x', "a\xffb"); echo preg_last_error(), "\n";
preg_replace('/a/', 'b', 'a'); echo preg_last_error(), "\n";
// nested use of the same pattern inside a callback
echo preg_replace_callback('/\d/', fn($m) => preg_replace('/\d/', 'N', $m[0]) . preg_match('/\d/', '7'), 'a1b2'), "\n";
