<?php
class C { public array $rows = []; }
function grow(C $c): array { $r = $c->rows; $r[] = 9; return $r; }
$c = new C(); $c->rows[] = 1;
$out = grow($c);
echo count($c->rows), " ", count($out), " ", implode(",", $out), "\n";
