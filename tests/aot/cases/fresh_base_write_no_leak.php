<?php

// A WRITE or a REFERENCE through a fresh base — `f()->arr[] = x`,
// `f()->n++`, `f()->arr['a'] .= 'b'`, `$r = &f()->arr`,
// `foreach (f()->arr as &$v)`, `[f()->x, f()->y] = …`, `unset(f()->arr[0])`,
// `sort(f()->arr)` — writes back through the base, and a write-back
// RE-EVALUATES the base expression. SpillFreshBases stored such a base into its
// owning local in place, so the store ran twice and freed the object the first
// write still went through (`mk()->arr[] = 3` aborted, `$r = &mo()->arr`
// SIGSEGVed). Such a chain is now pinned; when a statement evaluates it
// unconditionally its base is hoisted into a local BEFORE the statement (one
// evaluation, as in php) and released after it. The read-only neighbours
// (`[$a, $b] = f()->pair`, a by-value foreach) keep their in-place owner. A
// `mixed`-typed base is not hoisted (writing an array property through a mixed
// local is miscompiled on its own), so `mixedRef()` pins only correctness.
// The destructor lines pin that a temporary read in a statement is destroyed
// before the next statement runs, as in php.
// memory_get_usage() answers the peak RSS (ru_maxrss) here, which a leak can
// only raise; the bound is 3 MB over 20 000 calls. @serial: a memory
// measurement.

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

final class X
{
    /** @var array<int|string,mixed> */
    public array $arr = [1, 2, 'a' => 'x'];
    public int $n = 0;
    public string $x = '';
    public string $y = '';
    /** @var array<int,string> */
    public array $pair = ['p', 'q'];
    public function __construct(public string $tag = 't') {}
}

final class D
{
    public function __construct(public string $name) {}
    public function __destruct() { echo 'destruct ', $this->name, "\n"; }
}

function mk(): X { return new X(); }
function mo(): mixed { return new X(); }

measure('append', 20000, function (int $i): int { mk()->arr[] = $i; return 1; });
measure('keyed store', 20000, function (int $i): int { mk()->arr[0] = $i; return 1; });
measure('increment', 20000, function (int $i): int { mk()->n++; return 1; });
measure('compound concat', 20000, function (int $i): int { mk()->arr['a'] .= 'b'; return 1; });
measure('reference', 20000, function (int $i): int { $r = &mk()->arr; $r[] = $i; return count($r); });
measure('by-ref foreach', 20000, function (int $i): int { foreach (mk()->arr as &$v) { $v = $i; } unset($v); return 1; });
measure('destructure read', 20000, function (int $i): int { [$a, $b] = mk()->pair; return strlen($a . $b); });
measure('destructure write', 20000, function (int $i): int { [mk()->x, mk()->y] = ['1', '2']; return 1; });
measure('unset element', 20000, function (int $i): int { unset(mk()->arr[0]); return 1; });
measure('by-ref builtin arg', 20000, function (int $i): int { sort(mk()->arr); return 1; });
measure('by-value foreach', 20000, function (int $i): int { $t = 0; foreach (mk()->pair as $v) { $t = $t + strlen($v); } return $t; });

function mixedRef(): int
{
    $r = &mo()->arr;
    $r[] = 9;
    return count($r);
}
echo mixedRef(), "\n";

function withD(string $n): D { return new D($n); }

echo "A\n";
$name = withD('t1')->name;
echo $name, "\n";
echo "B\n";
$len = strlen(withD('t2')->name);
echo "C ", $len, "\n";
echo "done\n";
