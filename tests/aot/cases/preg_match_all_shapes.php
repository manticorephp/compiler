<?php
function show($r, $m) { echo json_encode($r), ' ', json_encode($m), "\n"; }
$subs = ['', 'a', 'ab', 'abc', 'hello world foo bar', "a1b22c333", "ünï cödé ж", str_repeat('ab ', 21), "x\0y z"];
foreach ($subs as $s) {
    show(preg_match_all('/\w+/', $s, $m), $m);
    show(preg_match_all('/(\w)(\d)?/', $s, $m), $m);
    show(preg_match_all('/(\w)(\d)?/', $s, $m, PREG_SET_ORDER), $m);
    show(preg_match_all('/(\w)(\d)?/', $s, $m, PREG_PATTERN_ORDER | PREG_UNMATCHED_AS_NULL), $m);
    show(preg_match_all('/(\w)(\d)?/', $s, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL), $m);
    show(preg_match_all('/(?<first>\w)(\d)?/', $s, $m), $m);
    show(preg_match_all('/(?<first>\w)(\d)?/', $s, $m, PREG_SET_ORDER), $m);
    show(preg_match_all('/(\w)(\d)?/', $s, $m, PREG_OFFSET_CAPTURE), $m);
    show(preg_match_all('/(\w)(\d)?/', $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE), $m);
    show(preg_match_all('/a*/', $s, $m), $m);
    show(preg_match_all('/(a)|(b)/', $s, $m), $m);
    show(preg_match_all('/(a)|(b)/', $s, $m, PREG_SET_ORDER), $m);
    show(preg_match_all('/./u', $s, $m), $m);
    show(preg_match_all('/\w*/u', $s, $m), $m);
    if ($s !== '') show(preg_match_all('/\w/', $s, $m, PREG_PATTERN_ORDER, 1), $m);
}
$big = str_repeat('word ', 20000);
echo preg_match_all('/\w+/', $big, $m), ' ', count($m[0]), ' ', $m[0][19999], "\n";
echo preg_match_all('/(\w)(o)/', $big, $m, PREG_SET_ORDER), ' ', count($m), ' ', $m[19999][2], "\n";
// invalid utf-8 under /u
var_dump((int)@preg_match_all('/./u', "a\xffb", $m));
echo preg_last_error(), ' ', preg_last_error_msg(), "\n";
var_dump(preg_match_all('/x/', 'abc', $m), $m, preg_last_error());
// reuse the out parameter
preg_match_all('/a/', 'aaa', $m); $k = $m; preg_match_all('/b/', 'b', $m); echo json_encode($k), json_encode($m), "\n";
