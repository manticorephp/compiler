<?php

// A callee that KEEPS one of its parameters keeps nothing of the OTHERS: the
// escape summary is per parameter. `merge($saved, $this->m)` below calls
// `pick`, which returns its first argument — a Val, never the map — so the
// property it was handed is no borrow, and `$this->m = $saved` releases the
// map it overwrites. With one verdict per function this was every
// `$this->localTypes = $this->mergeLocals($saved, $this->localTypes)` in the
// compiler: ~1M dead maps. memory_get_usage() answers the peak RSS here;
// 200 000 rounds of a ~300 B leak are ~60 MB, the bound is 3 MB.
// @serial: a memory measurement.

final class Val
{
    public function __construct(public int $n) {}
}

final class Merger
{
    /** @var array<string, Val> */
    public array $m = [];

    private function pick(Val $a, Val $b): Val { return $a->n >= $b->n ? $a : $b; }

    /**
     * @param array<string, Val> $a
     * @param array<string, Val> $b
     * @return array<string, Val>
     */
    private function merge(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $out[$k] = isset($b[$k]) ? $this->pick($v, $b[$k]) : $v;
        }
        return $out;
    }

    public function round(int $i): int
    {
        /** @var array<string, Val> $saved */
        $saved = ['a' => new Val($i), 'b' => new Val(1)];
        $this->m = ['a' . $i => new Val(2 * $i), 'a' => new Val($i + 1)];
        $merged = $this->merge($saved, $this->m);
        $this->m = $saved;
        return $merged['a']->n;
    }
}

$g = new Merger();
$sum = 0;
for ($i = 0; $i < 2000; $i++) { $sum = $sum + $g->round($i); }
$b = memory_get_usage();
for ($i = 0; $i < 200000; $i++) { $sum = $sum + $g->round($i); }
$d = memory_get_usage() - $b;
echo 'sum=', $sum, ' ', $d < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($d / 1048576, 1) . 'MB', "\n";
