<?php
class Pt {
    public function __construct(public int $x) {}
}
/**
 * @param Pt[] $pts
 */
function sumXs(array $pts): int {
    $t = 0;
    foreach ($pts as $p) { $t = $t + $p->x; }
    return $t;
}
echo sumXs([new Pt(10), new Pt(20), new Pt(30)]);
