<?php

// A by-value object or string PARAMETER the body reassigns from an owned
// producer was never released: every param was blanket-blocked from the
// release-before-overwrite and the scope-exit release, so `$o = $o ?? new O()`
// (Http\WebSocket\connect()'s own prologue) leaked the object it built — and,
// through the conditional's retained borrowed arm, the CALLER's object too —
// once per call. The param now takes the entry retain + scope-exit release
// that pair for exactly this shape. The destructor lines pin that nothing is
// freed early: the caller's object outlives the call, the frame's own dies
// with the frame.
// memory_get_usage() answers the peak RSS (ru_maxrss) here, which a leak can
// only raise; the bound is 3 MB over 100 000 calls. @serial: a memory
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

final class O
{
    public string $pad;
    public function __construct(public int $v = 7) { $this->pad = str_repeat('p', 64); }
}

final class D
{
    public function __construct(public string $name) {}
    public function __destruct() { echo 'destruct ', $this->name, "\n"; }
}

function coalesce(?O $o = null): int { $o = $o ?? new O(); return $o->v; }
function ifNull(?O $o = null): int { if ($o === null) { $o = new O(); } return $o->v; }
function always(O $o): int { $o = new O($o->v + 1); return $o->v; }
function trimmed(string $s): int { $s = trim($s); return strlen($s); }
function loop(?O $o, int $n): int
{
    $t = 0;
    for ($k = 0; $k < $n; $k++) { $o = new O($k); $t = $t + $o->v; }
    return $t;
}

measure('?? default', 100000, fn (int $i): int => coalesce());
measure('?? passed', 100000, fn (int $i): int => coalesce(new O($i)));
measure('if null default', 100000, fn (int $i): int => ifNull());
measure('if null passed', 100000, fn (int $i): int => ifNull(new O($i)));
measure('always reassigned', 100000, fn (int $i): int => always(new O($i)));
measure('string reassigned', 100000, fn (int $i): int => trimmed(' ' . str_repeat('s', 40 + $i % 7) . ' '));
measure('reassigned in a loop', 20000, fn (int $i): int => loop(new O(), 5));

function keep(?D $d, string $name): D
{
    $d = $d ?? new D($name);
    return $d;
}

function swap(D $d): int
{
    $d = new D('inner');
    return strlen($d->name);
}

$outer = new D('outer');
$same = keep($outer, 'unused');
echo $same === $outer ? "same object\n" : "different object\n";
$made = keep(null, 'made');
echo 'kept ', $made->name, "\n";
$made = null;
echo swap($outer), "\n";
echo 'still ', $outer->name, "\n";
$same = null;
$outer = null;
echo "done\n";
