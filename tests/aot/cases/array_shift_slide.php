<?php
// array_shift / array_unshift on a packed array move the header, not the data: every holder and every later operation must see a plain array.

function drain(int $n): string
{
    $a = [];
    for ($i = 0; $i < $n; $i++) { $a[] = $i * 3; }
    $s = 0;
    $c = 0;
    while ($a) { $s += array_shift($a); $c++; }
    return "$n:$c:$s:" . count($a);
}
foreach ([0, 1, 2, 3, 7, 8, 9, 31, 32, 33, 1000] as $n) { echo drain($n), "\n"; }

// BFS queue: push back, shift front, interleaved.
$adj = [];
for ($i = 0; $i < 200; $i++) { $adj[$i] = [($i * 2 + 1) % 200, ($i * 7 + 3) % 200]; }
$seen = [0 => true];
$q = [0];
$order = [];
while ($q) {
    $v = array_shift($q);
    $order[] = $v;
    foreach ($adj[$v] as $w) {
        if (!isset($seen[$w])) { $seen[$w] = true; $q[] = $w; }
    }
}
echo count($order), ' ', implode(',', array_slice($order, 0, 12)), "\n";

// Steady-state queue: one push and one shift per step.
$q = [1, 2, 3, 4, 5];
$sum = 0;
for ($i = 0; $i < 5000; $i++) { $q[] = $i; $sum += array_shift($q); }
echo count($q), ' ', $sum, ' ', implode(',', $q), "\n";

// Mixed shift / unshift / push / pop.
$m = [10, 20, 30];
array_unshift($m, 5);
echo array_shift($m), ' ', array_shift($m), "\n";
array_unshift($m, 1, 2);
$m[] = 99;
echo array_pop($m), ' ', implode(',', $m), "\n";
for ($i = 0; $i < 50; $i++) { array_unshift($m, $i); if ($i % 3 === 0) { array_shift($m); } $m[] = -$i; }
echo count($m), ' ', implode(',', array_slice($m, 0, 8)), ' ', implode(',', array_slice($m, -4)), "\n";
$u = [];
for ($i = 0; $i < 100; $i++) { array_unshift($u, $i); $u[] = $i + 1000; }
echo count($u), ' ', $u[0], ' ', $u[99], ' ', $u[100], ' ', $u[199], "\n";

// Keys renumber; the next append continues from count.
$k = ['a', 'b', 'c', 'd'];
array_shift($k);
$k[] = 'e';
var_dump($k);
echo json_encode($k), "\n";
echo serialize($k), "\n";
var_dump(unserialize(serialize($k)) === $k);

// Strings and nested arrays as elements.
$s = [];
for ($i = 0; $i < 20; $i++) { $s[] = str_repeat(chr(97 + $i % 26), $i % 5 + 1) . $i; }
$t = array_shift($s);
array_shift($s);
echo $t, ' ', count($s), ' ', $s[0], ' ', end($s), "\n";
$nested = [[1, 2], [3, 4], [5, 6]];
$first = array_shift($nested);
$nested[] = [7];
echo json_encode($first), json_encode($nested), "\n";

// COW: shift a copy while the original lives, and the other way round.
$orig = [1, 2, 3, 4];
$copy = $orig;
array_shift($copy);
echo implode(',', $orig), ' / ', implode(',', $copy), "\n";
array_shift($orig);
array_shift($orig);
echo implode(',', $orig), ' / ', implode(',', $copy), "\n";
$c2 = $copy;
array_unshift($c2, 0);
echo implode(',', $copy), ' / ', implode(',', $c2), "\n";

// Lvalues: property, static property, global, by-ref param, array element, static local, by-ref capture.
class Q
{
    public array $items = [1, 2, 3, 4, 5];
    public static array $st = ['x', 'y', 'z'];
    public function take(): mixed { return array_shift($this->items); }
}
$o = new Q();
echo $o->take(), $o->take(), ' ', implode(',', $o->items), "\n";
echo array_shift(Q::$st), ' ', implode(',', Q::$st), "\n";
array_unshift(Q::$st, 'w');
echo implode(',', Q::$st), "\n";

$g = [7, 8, 9];
function shiftGlobal(): mixed { global $g; return array_shift($g); }
echo shiftGlobal(), ' ', implode(',', $g), "\n";

function shiftRef(array &$r): mixed { return array_shift($r); }
$br = [4, 5, 6];
echo shiftRef($br), shiftRef($br), ' ', implode(',', $br), "\n";

$el = ['q' => [1, 2, 3], 'r' => [9]];
echo array_shift($el['q']), ' ', json_encode($el), "\n";

function counter(): int
{
    static $pool = [1, 2, 3, 4, 5, 6];
    array_shift($pool);
    return count($pool);
}
echo counter(), counter(), counter(), "\n";

$cap = [1, 2, 3];
$f = function () use (&$cap) { return array_shift($cap); };
echo $f(), $f(), ' ', implode(',', $cap), "\n";

// foreach over an array being shifted iterates the original value.
$fe = [1, 2, 3, 4];
foreach ($fe as $x) { array_shift($fe); echo $x; }
echo ' ', count($fe), "\n";

// Internal pointer after a shift.
$p = [1, 2, 3, 4];
next($p);
next($p);
array_shift($p);
echo current($p), key($p), next($p), "\n";

// Hashed arrays keep the in-place path: int keys renumber, string keys stay.
$h = [5 => 'a', 'k' => 'b', 9 => 'c'];
echo array_shift($h), ' ', json_encode($h), "\n";
$h[] = 'd';
var_dump($h);

// Other array operations after shifts.
$z = range(1, 20);
for ($i = 0; $i < 5; $i++) { array_shift($z); }
sort($z);
rsort($z);
echo implode(',', array_splice($z, 2, 3)), ' ', implode(',', $z), "\n";
echo implode(',', array_merge($z, [100])), "\n";
echo implode(',', array_reverse($z)), ' ', array_sum($z), ' ', max($z), "\n";
$z['s'] = 1;
array_shift($z);
echo json_encode($z), "\n";

// Generator holding the array.
function gen(array $a): Generator { while ($a) { yield array_shift($a); } }
foreach (gen([1, 2, 3]) as $v) { echo $v; }
echo "\n";
