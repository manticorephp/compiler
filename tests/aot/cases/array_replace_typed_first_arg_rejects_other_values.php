<?php
class Ex { public string $m = 'm'; protected string $t = 'x'; public int $line = 7; }
function f(object $o): int { $a = (array) $o; return \count(array_replace(['z' => 1], $a)); }
echo f(new Ex), "\n";
