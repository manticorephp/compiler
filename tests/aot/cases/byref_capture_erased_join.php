<?php
// A word two frames share — a by-reference capture, a by-reference param —
// reads UNKNOWN in the frame that did not write it last. That is the other
// frame's raw value, not an erased one: a join in one frame must not box the
// word alone, or the other frame reads the tagged bits as its int.
function captured(): void
{
    $ticks = 0;
    $inc = function () use (&$ticks) { for ($i = 0; $i < 3; $i++) { $ticks++; } };
    $outer = function () use (&$ticks, $inc) {
        $inc();
        while ($ticks < 5) { $ticks++; }
        if ($ticks > 4) { $ticks = $ticks + 1; }
    };
    $outer();
    echo "captured ", $ticks === 6 ? 'yes' : 'no', " ", $ticks, "\n";
}
function bump(&$n, int $to): void
{
    while ($n < $to) { $n++; }
    if ($n === $to) { $n = $n * 2; }
}
function byRefParam(): void
{
    $n = 1;
    bump($n, 4);
    echo "param ", $n, "\n";
}
captured();
byRefParam();
echo "done\n";
