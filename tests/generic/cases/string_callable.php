<?php
// Callables named by string: builtins and user functions.
function my_cmp(string $a, string $b): int { return strcmp($b, $a); }
function shout(string $s): string { return strtoupper($s) . '!'; }
$w = ['pear', 'apple', 'fig'];
usort($w, 'my_cmp');
echo implode(',', array_map('shout', $w)), ' ', call_user_func('strrev', 'abc'), "\n";
usort($w, 'strcmp');
echo implode(',', $w), "\n";
