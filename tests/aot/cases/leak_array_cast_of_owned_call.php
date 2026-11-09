<?php
// `(array)` over an owned call result does not keep the result alive (Laravel Router::resolveMiddleware).
class R { public static function resolve($n) { $o = []; foreach ([1, 2] as $i) { $o[] = str_repeat('x', 100) . $n . $i; } return $o; } }
function run(): int { $c = array_map(fn ($n) => (array) R::resolve($n), ['a', 'b', 'c']); return count($c); }
echo run(), "\n";
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 30000; $i++) { run(); }
echo ((memory_get_peak_usage() - $m0) >> 20) < 8 ? "flat\n" : "LEAK\n";
