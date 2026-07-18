<?php
// (2) `?array` return type -> raw pointer as int
function f(bool $e): ?array { if ($e) { return null; } return explode("\n", "a\nb"); }
var_dump(f(false));
var_dump(f(true));
echo "done\n";
