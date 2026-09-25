<?php
// Ordering a CELL against a string / bool literal goes through the tagged
// compare: `$c < "€"` answered false for every byte, and polyfill-mbstring's
// case loop stepped by 0 forever.
function h($a, $b) { var_dump($a < $b, $a > $b, $a <= $b, $a == $b, $a <=> $b); }
h('A', "\x80"); h('b', 'a'); h('10', '9'); h('abc', 'abd'); h('1e1', '10');
function k($a) { var_dump($a < "\x80", $a < 'B', 'B' > $a, $a < '10'); }
k('A'); k('9');
