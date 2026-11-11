<?php
class K1 { const A = 1; }
class K2 { const A = 2; }
function get(string $c): int { return $c::A; }
echo get($argv[1] ?? ''), "\n";
