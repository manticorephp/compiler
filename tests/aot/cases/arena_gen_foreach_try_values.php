<?php

// A generator driven by a foreach inside a try: the values the try built while
// the generator was suspended must survive the catch landing, and so must a
// value the generator itself built before a yield and reads after it.
function gen(string $p) {
  $keep = $p . str_repeat('k', 40);
  for ($i = 0; $i < 3; $i++) { yield $i => $p . $i; }
  yield 9 => strlen($keep) . substr($keep, 0, 2);
}
function f(string $p): string {
  $seen = [];
  try {
    foreach (gen($p) as $k => $v) {
      $seen[] = $v . '-' . str_repeat('s', 30);
      if ($k === 9) { throw new \RuntimeException($v); }
    }
  } catch (\RuntimeException $e) {
    $n = 0;
    for ($i = 0; $i < 3; $i++) { $n += strlen($p . str_repeat('z', 300) . $i); }
    return substr($seen[0], 0, 5) . '|' . substr($seen[2], 0, 5) . '|' . $e->getMessage() . '|' . $n;
  }
  return 'none';
}
function suspended(string $p): string {
  $out = '';
  for ($i = 0; $i < 3; $i++) {
    $g = gen($p . $i); $g->current(); $g->next();
    $out .= $g->current() . ',';
  }
  $tail = $p . str_repeat('t', 20);
  try { throw new \LogicException('x'); } catch (\LogicException $e) { $out .= strlen($tail); }
  return $out;
}
echo f('a'), "\n", f('b'), "\n", suspended('q'), "\n";
