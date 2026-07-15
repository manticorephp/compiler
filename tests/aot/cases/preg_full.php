<?php

// preg_match_all — PATTERN_ORDER (default)
$n = preg_match_all('/(\d)(\d)/', 'a12b34c56', $m);
echo $n, "\n";
echo $m[0][0], " ", $m[0][1], " ", $m[0][2], "\n";   // full matches
echo $m[1][0], " ", $m[2][0], "\n";                    // groups of first match

// preg_match_all — SET_ORDER
preg_match_all('/(\d)(\d)/', 'a12b34', $ms, PREG_SET_ORDER);
echo count($ms), "\n";
echo $ms[0][0], " ", $ms[0][1], " ", $ms[0][2], "\n";
echo $ms[1][0], "\n";

// preg_replace with backreferences
echo preg_replace('/(\w+)\s(\w+)/', '$2 $1', 'hello world'), "\n";
echo preg_replace('/\d+/', '#', 'a1b22c333'), "\n";
echo preg_replace('/o/', '0', 'foo boo', 2), "\n";

// preg_replace_callback
echo preg_replace_callback('/\d+/', function ($mm) {
    return (string)((int)$mm[0] * 2);
}, 'a3b10c'), "\n";

// preg_split
$parts = preg_split('/,/', 'a,b,c');
echo count($parts), " ", $parts[0], $parts[1], $parts[2], "\n";
$p2 = preg_split('/\s+/', 'one  two   three');
echo count($p2), " ", $p2[0], $p2[2], "\n";
$p3 = preg_split('/,/', 'a,,b', -1, PREG_SPLIT_NO_EMPTY);
echo count($p3), "\n";

// preg_grep
$g = preg_grep('/^\d+$/', ['a', '12', 'b3', '45']);
echo count($g), " ", $g[1], " ", $g[3], "\n";
$gi = preg_grep('/^\d+$/', ['a', '12', 'b3'], PREG_GREP_INVERT);
echo count($gi), "\n";

// preg_last_error
preg_match('/x/', 'abc');
echo preg_last_error(), " ", preg_last_error_msg(), "\n";

// named groups (numeric access)
preg_match('/(?<y>\d{4})-(?<m>\d{2})/', '2026-07', $nm);
echo $nm[1], " ", $nm[2], "\n";

// preg_split with delimiter capture + empty-match global replace
$dc = preg_split('/([,;])/', 'a,b;c', -1, PREG_SPLIT_DELIM_CAPTURE);
echo count($dc), " ", $dc[1], $dc[3], "\n";
echo preg_replace('/x*/', '-', 'abc'), "\n";
