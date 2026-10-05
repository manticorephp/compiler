<?php

// A generator primed inside a try, then a throw: the catch reads values the try
// built after the generator started. Its suspended frame must not leave an
// arena mark the landing mistakes for an unwound frame.
function gen(string $p) { $n = strlen($p . str_repeat('g', 50)); yield $n; yield $n + 1; }
function f(string $p): string {
  $g = null;
  try {
    $g = gen($p); $g->current();
    $b = $p . '-after';
    $c = [$p, $p . '!'];
    throw new Exception('t');
  } catch (Exception $e) {
    $n = 0;
    for ($i = 0; $i < 3; $i++) { $n += strlen($p . str_repeat('z', 300) . $i); }
    return $b . '|' . $c[1] . '|' . $n;
  }
}
echo f('a'), "\n", f('b'), "\n";
