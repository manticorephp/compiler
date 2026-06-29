<?php
// String builtins on a `mixed`/cell value must unbox the NaN tag to a ptr
// (was a SIGSEGV: strlen/etc. deref'd the boxed bits).
function f(mixed $x): int {
    if (is_int($x)) { return $x + 1; }
    if (is_string($x)) { return strlen($x); }
    return 0;
}
echo f(5), "\n";
echo f("hello"), "\n";
echo f(3.2), "\n";

function g(mixed $s): string { return strtoupper($s); }
echo g("abc"), "\n";

function h(mixed $s): int { return ord($s); }
echo h("A"), "\n";
