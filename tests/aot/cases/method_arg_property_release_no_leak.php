<?php

// A property handed as an ARGUMENT to a method whose every dispatch target
// keeps nothing of it is no borrow of the slot: the slot's overwrite still
// releases what it replaces. Methods were never judged, so every property
// passed to one — `$this->mergeLocals($saved, $this->localTypes)` — leaked
// each value it was overwritten with. A target that DOES keep its argument
// (Keeper::merge) still makes the property a borrow, and the answers stay
// right either way. memory_get_usage() answers the peak RSS here; 200 000
// calls leaking ~220 B are ~43 MB, the bound is 3 MB. @serial: a memory
// measurement.
class Base {
    /** @param array<string, bool> $a @return array<string, bool> */
    public function merge(array $a, array $b): array { $out = []; foreach ($a as $k => $v) { $out[$k] = $v; } return $out; }
}
class Keeper extends Base {
    /** @var array<string, bool> */
    public array $kept = [];
    /** @param array<string, bool> $a @return array<string, bool> */
    public function merge(array $a, array $b): array { $this->kept = $a; return $a; }
}
final class S {
    /** @var array<string, bool> */
    public array $lt = [];
    /** @var array<string, bool> */
    public array $lt2 = [];
    public function __construct(public Base $b) {}
    public function once(int $i): int
    {
        $this->lt = ['q' . $i => true];
        $saved = ['x' => true];
        $m = $this->mergeLocals($saved, $this->lt);
        $this->lt2 = ['r' . $i => true];
        $n = [];
        return count($m) + count($n);
    }
    /** @param array<string, bool> $a @param array<string, bool> $b @return array<string, bool> */
    private function mergeLocals(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $k => $v) { $out[$k] = $v; }
        foreach ($b as $k => $v) { if (!isset($a[$k])) { $out[$k] = $v; } }
        return $out;
    }
}
$s = new S(new Base());
$sum = 0;
for ($i = 0; $i < 2000; $i++) { $sum += $s->once($i); }
$b = memory_get_usage();
for ($i = 0; $i < 200000; $i++) { $sum += $s->once($i); }
$g = memory_get_usage() - $b;
echo 'sum=', $sum, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
$k = new Keeper();
$r = $k->merge(['a' => true, 'b' => false], []);
echo count($r), ' ', count($k->kept), "\n";
