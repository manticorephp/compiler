<?php

// A by-value `array` param the body stores into is COPIED on entry, so the
// store stays private to the callee. That copy is the frame's own +1: it must
// be released at scope exit, handed on by `return`, and released before a
// reassignment. It was never released — every call leaked the copy (~290 B a
// call here; the compiler's own InferTypes stranded a map per call).
// memory_get_usage() answers the peak RSS here; 200 000 calls leaking 290 B
// are ~55 MB, the bound is 3 MB. @serial: a memory measurement.

/** @param array<string, bool> $in @return array<string, bool> */
function modret(int $i, array $in): array { $in['d' . $i] = true; return $in; }

/** @param array<string, bool> $in */
function modonly(int $i, array $in): int { $in['d' . $i] = true; return count($in); }

/** @param array<string, bool> $in */
function modreassign(int $i, array $in): int
{
    $in['d' . $i] = true;
    if ($i % 2 === 0) { $in = ['e' . $i => false]; }
    return count($in);
}

/** @param array<string, bool> $in */
function modcallee(int $i, array $in): void { $in['d' . $i] = true; }

function once(int $i, int $mode): int
{
    $x = ['q' . $i => true];
    if ($mode === 1) { $x = modret($i, $x); }
    elseif ($mode === 2) { return modonly($i, $x); }
    elseif ($mode === 3) { return modreassign($i, $x); }
    elseif ($mode === 4) { modcallee($i, $x); }
    return count($x);
}

foreach ([1, 2, 3, 4] as $mode) {
    $sum = 0;
    for ($i = 0; $i < 2000; $i++) { $sum += once($i, $mode); }
    $b = memory_get_usage();
    for ($i = 0; $i < 200000; $i++) { $sum += once($i, $mode); }
    $g = memory_get_usage() - $b;
    echo $mode, ': sum=', $sum, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
}
$a = ['k' => true];
modcallee(1, $a);
echo count($a), "\n";

// A copied param is not the caller's argument any more, so handing a
// PROPERTY to such a callee is no borrow of the slot: its overwrite must
// still release what it replaces.
final class Holder
{
    /** @var array<string, bool> */
    public array $m = [];

    public function reset(int $i): int
    {
        $this->m = ['q' . $i => true];
        $this->m = modret($i, $this->m);
        return count($this->m);
    }
}
$h = new Holder();
$sum = 0;
for ($i = 0; $i < 2000; $i++) { $sum += $h->reset($i); }
$b = memory_get_usage();
for ($i = 0; $i < 200000; $i++) { $sum += $h->reset($i); }
$g = memory_get_usage() - $b;
echo 'prop: sum=', $sum, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
