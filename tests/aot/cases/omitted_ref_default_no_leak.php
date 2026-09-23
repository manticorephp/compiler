<?php

// A by-ref parameter the call omits is backed by a throwaway slot that holds
// the default; the callee writes its own +1 through it, and that write is the
// call site's to drop — php discards it with the temporary. Nothing released
// it: `preg_match($re, $s)` leaked its whole `$matches` on every hit (~160 B),
// and a static or constructor call with an omitted by-ref default passed the
// default VALUE where an address belonged, so the callee's write SIGSEGVed.
// memory_get_usage() answers the peak RSS (ru_maxrss) here, which a leak can
// only raise; the bound is 3 MB over 100 000 calls. @serial: a memory
// measurement.

/** @param callable(int): int $body */
function measure(string $label, callable $body): void
{
    $sum = 0;
    for ($i = 0; $i < 2000; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $before = memory_get_usage();
    for ($i = 0; $i < 100000; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $growth = memory_get_usage() - $before;
    echo $label, ': sum=', $sum, ' ', $growth < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($growth / 1048576, 1) . 'MB', "\n";
}

/** @param ?string[] $out */
function fill(int $n, ?array &$out = null): int
{
    $out = ['a' . $n, 'b' . $n];
    return 1;
}

final class Box
{
    public int $v;

    /** @param ?string[] $out */
    public function __construct(int $v = 0, ?array &$out = null)
    {
        $this->v = $v;
        $out = ['c' . $v];
    }

    /** @param ?string[] $out */
    public static function make(string $s, ?array &$out = null): int
    {
        $out = [$s . '!', $s . '?'];
        return 2;
    }

    /** @param ?string[] $out */
    public function put(string $s, ?array &$out = null): int
    {
        $out = [$s . '#'];
        return 3;
    }
}

$subject = str_repeat('z', 200);
measure('preg_match hit', function (int $i) use ($subject): int {
    return preg_match('/z(z)/', $subject);
});
measure('preg_match named', function (int $i) use ($subject): int {
    return preg_match('/(?<n>z)(y)?/', $subject);
});
measure('function', function (int $i): int {
    return fill($i);
});
measure('static method', function (int $i): int {
    return Box::make('s' . $i);
});
measure('constructor', function (int $i): int {
    $b = new Box($i % 7);
    return $b->v;
});
measure('method', function (int $i): int {
    $b = new Box(1);
    return $b->put('m' . $i);
});

fill(5, $got);
echo implode(',', $got), "\n";
preg_match('/z(z)/', $subject, $m);
echo count($m), ' ', $m[1], "\n";
new Box(4, $c);
echo $c[0], ' ', Box::make('x', $s), ' ', $s[1], "\n";
echo "done\n";
