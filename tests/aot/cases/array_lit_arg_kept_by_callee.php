<?php
// a nested array literal handed to a callee that keeps it must outlive the call
class Holder {
    public array $rows;
    function __construct(array $r) { $this->rows = $r; }
    function set(array $r): void { $this->rows = $r; }
}
function keep(array $r): void { global $kept; $kept = $r; }

$n = (int)($argv[1] ?? 3);
$h = new Holder([[$n]]);
$g = new Holder([]);
$g->set([[$n + 1]]);
keep([[$n + 2]]);
$m = new Holder([['a' => null]]);
$junk = [];
for ($i = 0; $i < 50; $i++) {
    $junk[] = [$i + 100];
    $junk[] = ['z' => $i + 200];
}
echo json_encode($h->rows), json_encode($g->rows), json_encode($kept), json_encode($m->rows), "\n";
echo json_encode(array_map(fn($x) => $x, $m->rows)), "\n";
