<?php
// An int stored into an array that holds floats (or the reverse) keeps its own
// type, as php keeps it: a raw element word is an i64 or a double, not both.
function num(int $t): int|float|null { return $t === 1 ? 7 : 2.5; }

class Bag
{
    public array $xs = [];
    public array $ys = [];
    public array $zs = [];

    public function fill(int $t): void
    {
        $this->xs[] = 1.5;
        $this->xs[] = $t;
        $this->ys[] = true;
        $this->ys[] = 1;
        $this->zs[] = null;
        $this->zs[] = 2;
    }
}

function locals(int $t): void
{
    $f = [1.5];
    $f[0] = 3;
    var_dump($f[0]);
    $b = [1];
    $b[0] = 2.5;
    var_dump($b[0]);
    $d = [1.5];
    $d[] = $t;
    var_dump($d);
    $w = [];
    if ($t === 0) { $w[0] = 1.5; }
    $w[1] = 7;
    var_dump($w);
    $v = [];
    if ($t === 0) { $v[0] = 1.5; }
    $v[1] = num(1);
    var_dump($v);
    $x = [];
    if ($t === 0) { $x[0] = 1.5; }
    $x[1] = $t === 4 ? 7 : null;
    var_dump($x);
}

locals(4);
$bag = new Bag();
$bag->fill(4);
var_dump($bag->xs, $bag->ys, $bag->zs);
