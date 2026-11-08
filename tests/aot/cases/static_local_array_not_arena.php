<?php
// A static local's initial array outlives the call: it was allocated in the frame's arena, and the next
// arena allocation overwrote it (Doctrine's date hydration crashed in __mc_dt_slot_store).
function slot(int $op, int $idx, int $v): int
{
    static $s = [0, 0, 0, 0, 0, 0, 0, 0, 0];
    if ($op === 1) { $s[$idx] = $v; return $v; }
    return $s[$idx];
}
function noise(int $k): int
{
    $a = [$k, $k, $k, $k, $k, $k, $k, $k, $k];
    $b = [$k + 1, $k + 1, $k + 1, $k + 1, $k + 1, $k + 1, $k + 1, $k + 1, $k + 1];
    return \count($a) + $b[0];
}
function names(): array { static $n = ['a', 'b']; $n[] = 'c' . \count($n); return $n; }
$out = [];
for ($k = 0; $k < 4; $k++) {
    slot(1, 3, 40 + $k);
    $out[] = noise(1000 + $k);
    $out[] = slot(0, 3, 0) . '/' . slot(0, 0, 0);
}
echo implode(' ', $out), "\n";
names(); noise(7); echo implode(',', names()), "\n";
