<?php
class Pt {
    public function __construct(public int $x) {}
    public function show(): string { return "p" . $this->x; }
}
function dump(array<Pt> $pts): string {
    $out = "";
    foreach ($pts as $p) { $out = $out . $p->show() . ";"; }
    return $out;
}
echo dump([new Pt(1), new Pt(2), new Pt(3)]);
