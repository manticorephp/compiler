<?php
// A shape literal's array-valued field keeps its own element repr: the field
// type (`int[]`) is what its readers take.
final class M {
    /** @var array<string, array{name: string, counts: int[]}> */
    public array $map = [];
    public function __construct() { $this->map['a'] = ['name' => 'x', 'counts' => [1, 2, 3]]; }
    public function show(): void { foreach ($this->map as $k => $m) { echo $k, ' ', $m['name'], ' ', implode(',', $m['counts']), ' ', $m['counts'][1] + 1, "\n"; } }
}
(new M())->show();
/** @param array{name: string, counts: int[]} $s */
function sh(array $s): void { echo implode(',', $s['counts']), ' ', $s['counts'][0] * 2, "\n"; }
sh(['name' => 'y', 'counts' => [4, 5]]);
function makeRec($id) { $tags = ["a", "b"]; return ["id" => $id, "tags" => $tags]; }
$r = makeRec(7);
$t = $r["tags"];
var_dump($t);
echo $r["tags"][1], "\n";
