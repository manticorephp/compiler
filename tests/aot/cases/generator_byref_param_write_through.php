<?php
function gen(int &$r) { $r = 5; yield 1; $r = 6; yield 2; }
function f() { $x = 0; foreach (gen($x) as $v) { echo $x, $v, "\n"; } }
f();
function gen2(&$r) { $r = 'a'; yield 1; }
function g() { foreach (gen2($y) as $v) { echo $y, $v, "\n"; } }
g();
