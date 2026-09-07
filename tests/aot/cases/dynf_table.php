<?php
// A dynamic function NAME reaching every argument carrier the dispatcher has to
// coerce: int, float, string, array, and a cell (mixed) parameter. One arm runs,
// so the args must be evaluated exactly once — `$n++` is the witness.

function dt_int(int $a, int $b): int { return $a * 10 + $b; }
function dt_float(float $f): float { return $f * 2.5; }
function dt_str(string $s): string { return strtoupper($s) . "!"; }
function dt_arr(array $a): int { return count($a) + $a[0]; }
function dt_mixed(mixed $m): string { return gettype($m); }
function dt_none(): string { return "none"; }

$n = 0;
function bump(): int { global $n; $n = $n + 1; return 7; }

$f = "dt_int";
echo $f(3, 4), "\n";

$f = "dt_float";
echo $f(2.0), "\n";

$f = "dt_str";
echo $f("ab"), "\n";

$f = "dt_arr";
echo $f([5, 6]), "\n";

$f = "dt_mixed";
echo $f(1), "\n";
echo $f("s"), "\n";
echo $f(1.5), "\n";

$f = "dt_none";
echo $f(), "\n";

// The argument expression must run once, not once per candidate arm.
$f = "dt_int";
echo $f(bump(), 1), "\n";
echo $n, "\n";

// A name that matches nothing arity-compatible: php fatals, so only the
// reachable half is exercised here.
$names = ["dt_int", "dt_str"];
foreach ($names as $nm) {
    if ($nm === "dt_int") { echo $nm(1, 2), "\n"; } else { echo $nm("q"), "\n"; }
}
