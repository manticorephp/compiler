<?php

// Pure-PHP inflate keeps its Huffman tables in a context object, rebuilds them
// into a local through a nested literal, and writes them back at the end of
// every call. Two compiler bugs made that a per-call leak:
//  - a bare-`array` function narrowed in the same NarrowReturns sweep as the
//    helper it calls (one with an erased `array` parameter) locked the
//    `vec[unknown]` it saw then, so `$lh = $t[0]` merged a cell against a
//    concrete table and every merge boxed a rebuilt copy nothing released;
//  - an ARRAY property read as an element of a literal (`[$c->lcount, …]`)
//    vetoed the property's release-before-overwrite, although the literal
//    retains it, so `$c->lcount = $lh[0]` stranded the table it replaced.
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

final class Ctx
{
    /** @var array<int,int> */
    public array $count = [];
    /** @var array<int,int> */
    public array $symbol = [];
}

$chunks = str_split(gzencode(str_repeat("The quick brown fox jumps over the lazy dog. ", 40)), 7);
measure('inflate_add in chunks', 500, function (int $i) use ($chunks): int {
    $z = inflate_init(ZLIB_ENCODING_GZIP);
    $n = 0;
    foreach ($chunks as $p) { $n = $n + strlen(inflate_add($z, $p)); }
    return $n + $i;
});

function fresh(int $i): array
{
    $a = [];
    for ($k = 0; $k < 200; $k++) { $a[$k] = $k + $i; }
    return $a;
}

function swap(Ctx $c, int $i): int
{
    $both = [$c->count, $c->symbol];
    $c->count = fresh($i);
    $c->symbol = fresh($i + 1);
    return \count($both[0]) + $c->count[0];
}

$ctx2 = new Ctx();
measure('property read in a literal', 100000, function (int $i) use ($ctx2): int {
    return swap($ctx2, $i);
});

function swapElem(Ctx $c, int $i): int
{
    $keep = [];
    $keep[0] = $c->count;
    $c->count = fresh($i);
    return \count($keep[0]);
}

$ctx3 = new Ctx();
measure('property read into an element', 100000, function (int $i) use ($ctx3): int {
    return swapElem($ctx3, $i);
});

function swapProp(Ctx $c, Ctx $d, int $i): int
{
    $d->count = $c->count;
    $c->count = fresh($i);
    return \count($d->count);
}

$ctx4 = new Ctx();
$ctx5 = new Ctx();
measure('property read into a property', 100000, function (int $i) use ($ctx4, $ctx5): int {
    return swapProp($ctx4, $ctx5, $i);
});
echo "done\n";
