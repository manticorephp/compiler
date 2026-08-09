<?php
// php: array_unshift(array &$array, mixed ...$values) — every value is
// prepended, in source order, and the return is the NEW count.
$a = [3, 4];
$n = array_unshift($a, 1, 2);
echo $n, ": ", implode(",", $a), "\n";

$b = ["x"];
echo array_unshift($b, "p", "q", "r"), ": ", implode(",", $b), "\n";

// mixed value kinds through one call
$c = [1];
array_unshift($c, "s", 2, true, null);
var_dump($c[0], $c[1], $c[2], $c[3], $c[4]);

// an ARRAY value stays one element
$d = [["last"]];
array_unshift($d, ["first"], ["second"]);
echo count($d), ": ", $d[0][0], ",", $d[1][0], ",", $d[2][0], "\n";

// a property base
class Holder
{
    /** @var array<int,string> */
    public array $stack = [];

    public function pushFront(string ...$vals): int
    {
        return array_unshift($this->stack, ...$vals);
    }
}

$h = new Holder();
$h->stack = ["tail"];
echo $h->pushFront("one", "two", "three"), ": ", implode(",", $h->stack), "\n";

// value semantics: the alias keeps the pre-call contents
$e = [7, 8];
$f = $e;
array_unshift($e, 6, 5);
echo implode(",", $e), " | ", implode(",", $f), "\n";

// in a loop, so the realloc happens repeatedly
$g = [];
for ($i = 0; $i < 4; $i = $i + 1) {
    array_unshift($g, $i, $i * 10);
}
echo implode(",", $g), "\n";
