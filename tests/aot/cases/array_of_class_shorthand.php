<?php
class Pt {
    public int $x;
    public function __construct(int $x) { $this->x = $x; }
}
function sumXs(Pt[] $pts): int {
    $t = 0;
    foreach ($pts as $p) { $t = $t + $p->x; }
    return $t;
}
echo sumXs([new Pt(10), new Pt(20), new Pt(30)]);
