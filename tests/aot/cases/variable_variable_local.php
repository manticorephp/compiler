<?php
function f($name, $v) { $a = 1; $b = 2; $$name = $v; $k = 'b'; return "$a $b " . $$k . ' ' . ${'a'}; }
echo f('a', 9), "\n", f('b', 7), "\n", f('zz', 5), "\n";
class C { function set($n, $v) { $x = 0; $y = 0; $$n = $v; return [$x, $y]; } }
echo implode(',', (new C)->set('y', 4)), "\n";
function g() { $months = ['a', 'b']; $weekdays = ['c']; foreach (['months', 'weekdays'] as $v) { $l = ['x', 'y']; foreach ($$v as $i => &$name) { $name .= '|' . $l[$i]; } unset($name); } return implode(',', $months) . ';' . implode(',', $weekdays); }
echo g(), "\n";
