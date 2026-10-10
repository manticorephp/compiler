<?php
declare(strict_types=1);

final class Box
{
    public function __construct(public int $n, public string $label = '') {}
    public function double(): int { return $this->n * 2; }
}

/** @extends SplFixedArray<Box> */
final class Boxes extends SplFixedArray {}

/** @extends SplFixedArray<Box> */
class LoudBoxes extends SplFixedArray
{
    public function offsetGet(mixed $index): mixed { return new Box(100 + parent::offsetGet($index)->n); }
}

function sumDoubles(Boxes $b): int
{
    $t = 0;
    for ($i = 0; $i < $b->count(); $i++) { $t += $b[$i]->double(); }
    return $t;
}

$b = new Boxes(4);
for ($i = 0; $i < 4; $i++) { $b[$i] = new Box($i + 1, 'b' . $i); }

echo $b[0]->n, ' ', $b[3]->label, ' ', $b[2]->double(), "\n";
echo sumDoubles($b), "\n";

$x = $b[1];
$x->n = 50;
echo $b[1]->n, "\n";

$b[2] = new Box(7);
echo $b[2]->n, ' ', $b[2]->double(), "\n";

$k = 3;
echo $b[$k]->n, ' ', $b["1"]->n, "\n";

foreach ($b as $i => $box) {
    echo $i, ':', $box->n, ' ';
}
echo "\n";

try {
    echo $b[4]->n;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo $b[-1]->n;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo "-- override\n";
$l = new LoudBoxes(2);
$l[0] = new Box(1);
$l[1] = new Box(2);
echo $l[0]->n, ' ', $l[1]->n, "\n";
foreach ($l as $i => $box) { echo $i, ':', $box->n, ' '; }
echo "\n";

echo "-- shared object\n";
$shared = new Box(9);
$s = new Boxes(2);
$s[0] = $shared;
$s[1] = $shared;
$s[0]->n = 10;
echo $s[1]->n, ' ', $shared->n, "\n";
unset($shared);
$s[0] = new Box(1);
echo $s[1]->n, "\n";

echo "-- clone is deep for the buffer, shallow for the objects\n";
$c = clone $b;
$c[0] = new Box(99);
echo $b[0]->n, ' ', $c[0]->n, ' ', $c[1]->n, "\n";
