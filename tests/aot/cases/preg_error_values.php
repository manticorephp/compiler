<?php
// Every preg_* answers its error value (false / null / partial) and sets preg_last_error() on a bad UTF-8 subject under /u, a bad offset, and a pattern that does not compile.
$bad = "a\xff";
function show($r) { echo var_export($r, true), ' ', preg_last_error(), ' ', preg_last_error_msg(), "\n"; }
show(preg_match('/a/u', $bad, $m)); var_dump($m);
show(preg_match('/a/u', 'a'));
show(preg_match_all('/a/u', $bad, $m)); var_dump($m);
show(preg_match_all('/a/u', 'aa'));
show(preg_replace('/a/u', 'b', $bad));
show(preg_replace('/a/u', 'b', 'a'));
show(preg_replace(['/a/u', '/b/'], ['b', 'c'], $bad));
show(preg_replace('/a/u', 'b', ['x' => 'a', 'y' => $bad, 'z' => 'aa']));
show(preg_replace_callback('/a/u', fn($m) => 'b', $bad));
show(preg_replace_callback('/a/u', fn($m) => 'b', ['a', $bad]));
show(preg_replace_callback_array(['/a/u' => fn($m) => 'b'], $bad));
show(preg_split('/a/u', $bad));
show(preg_grep('/a/u', ['a', $bad, 'ba']));
show(preg_match('/a/', $bad));
show(@preg_match('/a', 'a'));
show(@preg_replace('/a', 'b', 'a'));
show(@preg_split('/a', 'a'));
show(preg_match('/a/u', "\xffa", $m, 0, 1));
show(preg_match('/a/u', "é", $m, 0, 1));
show(preg_match("  /a/", 'a'));
show(preg_match("/a/S", 'a'));
show(@preg_match("/a/Q", 'a'));
show(@preg_match("", 'a'));
show(@preg_match("abca", 'b'));
show(@preg_match("(?<x>a)|(?<x>b)/J", 'b'));
show(preg_match("/(?<x>a)|(?<x>b)/J", 'b', $m)); var_dump($m);
show(preg_match("/(a)(?<y>b)/n", 'ab', $m)); var_dump($m);
show(preg_match("{a}i", 'A'));
show(preg_match("/a/u", 'a'));
