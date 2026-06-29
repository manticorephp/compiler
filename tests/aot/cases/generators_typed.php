<?php
function words() { yield "alpha"; yield "beta"; yield "gamma"; }
foreach (words() as $w) { echo $w, "\n"; }

function lines(int $n) { $i = 0; while ($i < $n) { yield "line" . $i; $i = $i + 1; } }
foreach (lines(3) as $l) { echo $l, "\n"; }

class Pt { public function __construct(public int $x, public int $y) {} }
function pts() { yield new Pt(1, 2); yield new Pt(3, 4); }
foreach (pts() as $p) { echo $p->x, ",", $p->y, "\n"; }

function halves(int $n) { $i = 1; while ($i <= $n) { yield $i / 2; $i = $i + 1; } }
foreach (halves(4) as $h) { echo $h, " "; }
echo "\n";
