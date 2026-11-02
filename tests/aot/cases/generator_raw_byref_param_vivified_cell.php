<?php
// A generator's raw-typed by-ref param loses the lazy write when the caller's variable is an undefined (vivified) local.
function gen(?array &$r = null) { $r = [1]; yield 1; }
function h() { foreach (gen($y) as $v) { echo count($y), $v, "\n"; } }
h();
function cnt(int &$n) { for ($i = 0; $i < 3; $i++) { $n = $n + 1; yield $i; } }
function k() { $c = 0; foreach (cnt($c) as $i) { echo $i, ':', $c, "\n"; } echo $c, "\n"; }
k();
function bag(array &$out) { $out[] = 'x'; yield 1; $out[] = 'y'; yield 2; }
function b() { $acc = []; foreach (bag($acc) as $v) { echo $v, count($acc), "\n"; } echo implode(',', $acc), "\n"; }
b();
