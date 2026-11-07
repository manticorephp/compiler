<?php
// Invoking `[$class, $method]` built from runtime strings crashes (SIGBUS), directly and through call_user_func_array; `$class::$method()` works.
// issue: #582
class Z { public static function run($x = 'd') { return 'run ' . $x; } }
function direct(string $c, string $m, array $a) { return [$c, $m](...$a); }
function viaCufa(string $c, string $m, array $a) { return call_user_func_array([$c, $m], $a); }
echo direct('Z', 'run', ['v']), "\n";
echo viaCufa('Z', 'run', ['w']), "\n";
