<?php
// An array whose elements are OBJECTS, handed back through a by-ref array param
// and then read under a `vec[obj]` static type. The writeback cellifies (the
// `.sig` carries no element repr, so the caller must see self-describing cells),
// and the raw read then inttoptr'd the NaN tag — `$r[0] instanceof R` SIGSEGV'd
// while `var_dump($r[0])` (which decodes a cell) printed fine.

// NOTE: no __destruct here on purpose — the caller's ORIGINAL array is never
// released by the writeback, so the object outlives the program (a LEAK, not a
// correctness bug, and a separate open issue: tests/aot/cases/stream_select_loop).
class R
{
    public function __construct(public int $fd) {}
}

function idr(R $s): R { return $s; }

function rewrite(array &$read): int
{
    $nr = [];
    foreach ($read as $s) { $nr[] = idr($s); }
    $read = $nr;
    return count($nr);
}

$b = new R(7);
$r = [$b];
echo rewrite($r), "\n";
echo $r[0]->fd, "\n";
var_dump($r[0] instanceof R);

// The same local, rewritten twice — the second pass reads what the first wrote.
$r = [$b];
echo rewrite($r), "\n";
echo $r[0]->fd, "\n";

// A value-only foreach over the written-back array reads the same channel.
foreach ($r as $e) { echo "elem ", $e->fd, "\n"; }
echo $b->fd, "\n";
echo "done\n";
