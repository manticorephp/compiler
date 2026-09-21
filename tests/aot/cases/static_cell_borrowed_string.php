<?php
// A cell-typed `static` (two value kinds) stores through the box-back arm; the
// boxed string is BORROWED from a property whose owner dies before the next
// call reads the cell. The cell must co-own what it holds.
class Holder {
    public string $name;
    public function __construct(string $n) { $this->name = $n; }
}
function step(int $i): void {
    static $c;
    if ($c !== null) {
        echo "prev: ", is_string($c) ? $c : "int:" . $c, "\n";
    }
    if ($i % 2 === 0) {
        $h = new Holder('name-' . $i . '-' . str_repeat('x', 40));
        $c = $h->name;
        unset($h);
    } else {
        $c = $i;
    }
    $junk = [];
    for ($k = 0; $k < 64; $k++) { $junk[] = str_repeat('z', 40 + $k); }
}
for ($i = 0; $i < 6; $i++) { step($i); }
echo "done\n";
