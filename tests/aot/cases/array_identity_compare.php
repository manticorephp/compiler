<?php
$list = [1, 2];
function f(): void { global $list; var_dump($GLOBALS["list"] === $list, $GLOBALS["list"] == $list); }
f();
var_dump($GLOBALS["list"] === $list);
$a = ["a"]; $b = ["a"]; var_dump($a === $b, $a == $b, [1, 2] === [1, 2], [1, 2] === [2, 1]);
function g(mixed $m, array $n): bool { return $m === $n; }
var_dump(g([1], [1]), g("x", ["x"]));
