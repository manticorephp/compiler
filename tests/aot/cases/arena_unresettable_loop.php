<?php

// Allocations in a loop that cannot reset the arena per iteration.
//
// The escape analysis proves such a value frame-confined and the memory-mode
// overlay routes it to the arena; the emitter then refuses the per-iteration
// reset, because the value outlives the iteration. Nothing used to reconcile
// those two answers, so the temporaries piled up for the whole frame —
// 300 000 iterations of `$s = $long . $long` with one read after the loop cost
// 2.7 GB of RSS against 1 MB once the allocation is taken back out of the arena.
//
// Memory is not observable from a test that compares stdout, so what this case
// pins is the OWNERSHIP half: the demotion turns arena values into refcounted
// ones, and a wrong retain/release there is a double free or a stale read. Each
// shape below is one the demotion applies to.

$long = str_repeat("L", 6);

// (1) read AFTER the loop — the value the pre-exit reset would have freed.
$s = '';
for ($i = 0; $i < 4; $i++) {
    $s = $long . $i;
}
echo $s, " ", strlen($s), "\n";

// (2) the same local written by TWO loops — each one's reads are outside the
//     other, so neither may reset.
$t = '';
for ($i = 0; $i < 3; $i++) { $t = $long . "a" . $i; }
for ($i = 0; $i < 3; $i++) { $t = $long . "b" . $i; }
echo $t, "\n";

// (3) nested: the inner loop resets and keeps its arena, the outer does not.
//     "Innermost" is what decides, so the inner value must still be right.
$outer = '';
for ($i = 0; $i < 3; $i++) {
    $acc = 0;
    for ($j = 0; $j < 3; $j++) {
        $inner = "x" . $j;
        $acc += strlen($inner);
    }
    $outer = $long . "-" . $acc;
}
echo $outer, "\n";

// (4) arrays, not just strings — a confined vec built in a non-resettable loop.
$arr = [];
for ($i = 0; $i < 4; $i++) {
    $arr = [$i, $i * 2, $i * 3];
}
echo implode(",", $arr), " ", count($arr), "\n";

// (5) the value escapes into a container, so it was rc-owned all along —
//     the demotion must not release what the container now co-owns.
$bag = [];
for ($i = 0; $i < 3; $i++) {
    $v = $long . "#" . $i;
    $bag[] = $v;
}
echo implode("|", $bag), " last=", $v, "\n";

// (6) foreach over a real array, value read after the loop.
$src = ["p", "q", "r"];
$last = '';
foreach ($src as $item) {
    $last = $item . "!";
}
echo $last, "\n";

// (7) a while loop whose accumulator is read afterwards.
$n = 0;
$w = '';
while ($n < 3) {
    $w = $long . ":" . $n;
    $n++;
}
echo $w, "\n";

// (8) the demoted value handed to a function — ownership crosses a call.
function tail(string $x): string { return substr($x, -3); }
$r = '';
for ($i = 0; $i < 3; $i++) {
    $r = tail($long . $i . "zz");
}
echo $r, "\n";

echo "done\n";
