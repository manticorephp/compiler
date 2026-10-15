<?php
// Writing an undeclared property on BcMath\Number inside a closure SIGSEGVs instead of throwing Error.
use BcMath\Number;
function t(callable $f): void { try { $f(); echo "no throw\n"; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; } }
t(function () { $n = new Number('1'); $n->foo = 2; });
echo "end\n";
