<?php
// A `...$pack` whose pack is an erased (mixed) value unpacks into a dynamic closure, a function name and sprintf.
function ids(mixed $v): mixed { return $v; }
function join3(string $a, string $b, string $c): string { return "$a|$b|$c"; }
$pack = ids(['x', str_repeat('y', 2), 'z']);
$cl = ids(fn (string $a, string $b, string $c): string => "$c$b$a");
echo $cl(...$pack), "\n";
$name = ids('join3');
echo $name(...$pack), "\n";
echo sprintf('%s/%s/%s', ...$pack), "\n";
