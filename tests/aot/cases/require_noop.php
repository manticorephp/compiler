<?php
// Whole-program AOT: a `require`/`include` of an already-compiled (or absent)
// file is a no-op yielding null; declarations come from the compilation, not a
// runtime load. Manticore-only semantics (php would fatal on the missing paths).
function localHelper(): int { return 42; }
require __DIR__ . '/__manticore_require_noop_absent__.php';
$v = include '__manticore_include_noop_absent__.php';
var_dump($v);
echo localHelper(), "\n";
echo "done\n";
