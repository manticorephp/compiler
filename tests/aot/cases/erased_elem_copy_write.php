<?php
// A copy of an element read through an erased (mixed) array owns its value:
// writing to the copy leaves the container alone, and growing it frees nothing.
function id(mixed $x): mixed { return $x; }

$row = [];
for ($i = 0; $i < 8; $i++) { $row["k$i"] = $i; }
$items = id([$row, ['a' => 1]]);
for ($r = 0; $r < 3; $r++) {
    $it = $items[0];
    $it['total'] = $r;
    $small = $items[1];
    $small['b'] = $r;
    echo count($it), ' ', count($items[0]), ' ', count($small), ' ', count($items[1]), "\n";
}

$rows = json_decode('[{"price":2,"quantity":3,"tags":["a"]},{"price":5,"quantity":7,"tags":[]}]', true);
$f = function (int $m) use ($rows): string {
    $out = [];
    for ($i = 0; $i < 2; $i++) {
        $it = $rows[$i];
        $it['total'] = $it['price'] * $it['quantity'] * $m;
        $out[] = $it;
    }
    return json_encode($out);
};
echo $f(1), "\n", $f(2), "\n", json_encode($rows), "\n";

final class Bag implements ArrayAccess
{
    public function __construct(private array $v) {}
    public function offsetExists(mixed $k): bool { return isset($this->v[$k]); }
    public function offsetGet(mixed $k): mixed { return $this->v[$k]; }
    public function offsetSet(mixed $k, mixed $x): void { $this->v[$k] = $x; }
    public function offsetUnset(mixed $k): void { unset($this->v[$k]); }
}
$bag = id(new Bag([['x' => 1]]));
$s = id('hello');
for ($r = 0; $r < 2; $r++) {
    $e = $bag[0];
    $e['y'] = $r;
    $c = $s[1];
    echo count($e), ' ', count($bag[0]), ' ', $c, "\n";
}

$base = memory_get_usage();
for ($r = 0; $r < 20000; $r++) {
    $it = $items[0];
    $it['total'] = $r;
    $e = $bag[0];
    $e['y'] = $r;
}
echo memory_get_usage() - $base < 1048576 ? "flat\n" : "grows\n";
