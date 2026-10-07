<?php
use Manticore\Ds\Int32Array;
use Manticore\Ds\Float64Array;
use Manticore\Ds\BitArray;
function bump(Int32Array $a, int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) { $a[$i] = $a[$i] + 1; $s += $a[$i]; }
    return $s;
}
final class Grid {
    public static Int32Array $shared;
    public Int32Array $cells;
    public Float64Array $w;
    public function __construct(int $n) { $this->cells = new Int32Array($n); $this->w = new Float64Array($n); }
    public function fill(int $n): int {
        $s = 0;
        for ($i = 0; $i < $n; $i++) { $this->cells[$i] = $i * 2; $this->w[$i] = $i + 0.5; $s += $this->cells[$i]; }
        return $s;
    }
    public function dot(int $n): float {
        $d = 0.0;
        for ($i = 0; $i < $n; $i++) { $d += $this->w[$i] * $this->cells[$i]; }
        return $d;
    }
}
function viaStatic(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) { Grid::$shared[$i] = $i; $s += Grid::$shared[$i]; }
    return $s;
}
function outside(Grid $g): int {
    $s = 0;
    for ($i = 0; $i < 10; $i++) { $g->cells[$i] = $g->cells[$i] + 1; $s += $g->cells[$i]; }
    return $s;
}
$a = new Int32Array(1000);
echo bump($a, 1000), "\n";
$g = new Grid(1000);
echo $g->fill(1000), " ", $g->dot(1000), "\n";
Grid::$shared = new Int32Array(100);
echo viaStatic(100), " ", outside($g), "\n";
try { echo bump($a, 1001), "\n"; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
try { $g->cells[5] = 3000000000; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
try { echo $g->fill(1001), "\n"; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
try { echo viaStatic(101), "\n"; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
$b = new BitArray(130);
$c = 0;
for ($i = 0; $i < 130; $i++) { $b[$i] = ($i % 3) === 0; }
for ($i = 0; $i < 130; $i++) { if ($b[$i]) { $c++; } }
echo $c, "\n";
// a resize inside the loop must be seen by the next access
$r = new Int32Array(4);
$s = 0;
for ($i = 0; $i < 8; $i++) { if ($i === 4) { $r->setSize(8); } $r[$i] = $i; $s += $r[$i]; }
echo $s, "\n";
