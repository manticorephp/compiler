<?php

// A statically-false operand must drop the OTHER side at LOWERING, not merely
// skip it at run time: every call below names a function that does not exist,
// so the case only compiles at all if the dead arm never reached codegen.

function have(): bool { return true; }

// && with a false left — the right is never lowered.
$a = \function_exists('no_such_fn_xyz') && no_such_fn_xyz(1, 2);
echo $a ? "1" : "0", "\n";

// && with a false right — the left still runs (it is a pure guard here).
$b = \function_exists('have') && \function_exists('no_such_fn_xyz');
echo $b ? "1" : "0", "\n";

// || with a true left — the right is never lowered.
$c = \function_exists('have') || no_such_fn_xyz(3);
echo $c ? "1" : "0", "\n";

// || with a false left — the expression is the right operand alone.
$d = \function_exists('no_such_fn_xyz') || \function_exists('have');
echo $d ? "1" : "0", "\n";

// Ternary on a compile-time condition — only the live arm is lowered.
$e = \function_exists('no_such_fn_xyz') ? no_such_fn_xyz(4) : 7;
echo $e, "\n";

$f = \function_exists('have') ? 8 : no_such_fn_xyz(5);
echo $f, "\n";

// The guard still composes with a runtime operand: a false compile-time
// conjunct wins regardless of what sits beside it.
$n = 3;
$g = \function_exists('no_such_fn_xyz') && $n > 1;
echo $g ? "1" : "0", "\n";

// And a live guard leaves the runtime operand doing the deciding.
$h = \function_exists('have') && $n > 1;
echo $h ? "1" : "0", "\n";
$i = \function_exists('have') && $n > 9;
echo $i ? "1" : "0", "\n";
