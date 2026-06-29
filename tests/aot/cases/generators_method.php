<?php
class Range {
    public function __construct(private int $lo, private int $hi) {}
    public function gen() { $i = $this->lo; while ($i <= $this->hi) { yield $i; $i = $i + 1; } }
}
$r = new Range(1, 4);
foreach ($r->gen() as $v) { echo $v, " "; } echo "\n";

class Words {
    /** @return Generator<string> */
    public function each() { yield "a"; yield "bb"; yield "ccc"; }
}
$w = new Words();
foreach ($w->each() as $s) { echo strtoupper($s), " "; } echo "\n";

function empty_gen() { if (false) { yield 1; } }
foreach (empty_gen() as $v) { echo "X"; } echo "(empty)\n";
