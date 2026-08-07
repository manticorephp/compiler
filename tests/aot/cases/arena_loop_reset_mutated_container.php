<?php

// A container bound BEFORE a loop and mutated INSIDE it. An in-place mutation
// reallocates: `$seen[$k] = v` on a packed array runs __mir_array_promote, which
// re-homes the whole buffer, and the runtime routes that allocation the way the
// SOURCE buffer is tagged. When the source is an arena buffer and the loop head
// restores the arena per iteration, the new buffer sits above the mark and the
// next iteration hands its bytes to the next temporary.
//
// The shape is emitVirtualDispatch's own `$seenCid[$cd->classId] = true`, which
// aborted the cold seed in libmalloc. This pins the READ-BACK: whatever the
// allocation verdict, the elements must survive the loop.

final class CD
{
    public function __construct(public readonly int $classId) {}
}

/** @param CD[] $cands */
function collect(array $cands): int
{
    $n = 0;
    $sum = 0;
    $seen = [];
    foreach ($cands as $c) {
        if (isset($seen[$c->classId])) { continue; }
        $seen[$c->classId] = $c->classId * 2;
        $label = 'vd.case.' . $c->classId . '.arm';
        if ($label === '') { echo "never\n"; }
        $n = $n + 1;
    }
    foreach ($seen as $k => $v) { $sum = $sum + $k + $v; }
    return $n * 1000000 + $sum;
}

$cands = [];
for ($i = 0; $i < 200; $i = $i + 1) { $cands[] = new CD($i * 3); }
echo collect($cands), "\n";

// An element unset is the other in-place mutation that reallocates.
function prune(int $upto): int
{
    $bag = [];
    for ($i = 0; $i < $upto; $i = $i + 1) {
        $bag[$i * 5] = $i;
        if ($i % 3 === 0) { unset($bag[$i * 5]); }
        $tag = 'k' . $i;
        if ($tag === '') { echo "never\n"; }
    }
    $t = 0;
    foreach ($bag as $k => $v) { $t = $t + $k - $v; }
    return $t;
}

echo prune(120), "\n";
