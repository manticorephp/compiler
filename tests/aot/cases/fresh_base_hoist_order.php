<?php

// SpillFreshBases hoists the fresh base of a write chain (`mk()->arr[] = 1`)
// into a local BEFORE its statement, so the base is evaluated once and owned.
// Lifting it ahead of an operand php evaluates first reordered visible side
// effects: `byref(g(), mk()->arr)` ran mk() before g(). A base
// is now hoisted only when nothing the statement evaluates before it has a side
// effect, and never out of an erased callee's argument list (`$o->m(g(),
// rows())` on a mixed receiver ran rows() first). Each line below prints the
// order the calls ran in (a write used as a VALUE — `$s = g() . (mk()->arr[] =
// 5)` — and `unset($u[g()], mk()->arr[0])` are left out: when not hoisted they
// re-evaluate their base, a separate open bug, whatever the order); the last shape IS hoisted (its base comes first) and
// must still not leak. memory_get_usage() answers the peak RSS here; the bound
// is 3 MB over 20 000 calls. @serial: a memory measurement.

final class X
{
    /** @var array<int|string,int> */
    public array $arr = [1];
    public int $n = 0;
    public function m(int $a, array $b): int { echo "m "; return $a + count($b); }
}

function mk(string $t): X { echo "mk$t "; return new X(); }
function g(): int { echo "g "; return 1; }
/** @return array<int,int> */
function rows(): array { echo "rows "; return [1, 2]; }
function my(): mixed { echo "my "; return new X(); }
/** @param array<int|string,int> $b */
function byref(int $a, array &$b): void { echo "byref "; $b[] = $a; }

byref(g(), mk('c')->arr);
echo "|\n";
foreach ([1, 2] as $i) {
    byref(g(), mk('f' . $i)->arr);
}
echo "|\n";
$o = my();
echo $o->m(g(), rows()), "\n";
mk('first')->arr[] = g();
echo "|\n";

/** @param callable(int): int $body */
function measure(string $label, int $n, callable $body): void
{
    $sum = 0;
    for ($i = 0; $i < 200; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $before = memory_get_usage();
    for ($i = 0; $i < $n; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $growth = memory_get_usage() - $before;
    echo $label, ': sum=', $sum, ' ', $growth < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($growth / 1048576, 1) . 'MB', "\n";
}

function quiet(): X { return new X(); }
function one(int $i): int { return $i; }
measure('hoisted first', 20000, function (int $i): int { quiet()->arr[] = one($i); return 1; });
