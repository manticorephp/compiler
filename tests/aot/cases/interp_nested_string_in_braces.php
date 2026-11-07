<?php
function w(string $s): string { return "[$s]"; }
$line = 'x';
$w = fn($s) => "[$s]";
$o = new class { function f($v) { return "<$v>"; } };
echo "a {$o->f("b {$line} c")} d\n";
echo "k {$o->f('}')} z\n";
echo " {$w(" {$line} ")}
";
