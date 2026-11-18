<?php
// typed_coerce — typed call boundaries fed a CELL (mixed) value: the per-site argument coercion
// docs/design/table-coercion.md moves behind one descriptor-driven routine. `typed int` is the
// control (no coercion at all, must not move); every other row coerces a cell at the boundary.
final class Box { public int $n = 0; public string $s = ''; public function get(int $k): int { return $this->n + $k; } }

/** Hides the static type: every value read back out of it is a cell. */
function erase(mixed $v): mixed { return $v; }
function takeInt(int $x): int { return $x + 1; }
function takeStr(string $s): int { return \strlen($s); }
function takeFloat(float $f): float { return $f * 0.5; }
function takeObj(Box $b): int { return $b->n; }
function retInt(mixed $v): int { return $v; }

function bench(string $label, callable $f, int $reps): void
{
    $t = hrtime(true);
    $r = $f($reps);
    $ms = (int)((hrtime(true) - $t) / 1000000);
    echo $label, ' ', $r, "\n";
    fwrite(STDERR, \sprintf("%-22s %6d ms\n", $label, $ms));
}

$reps = 20000000 * $argc;
$box = new Box();
/** @var array<int, mixed> $cells */
$cells = [erase(7), erase('abc'), erase(2.5), erase($box), erase('12')];

bench('typed int', function (int $n): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += takeInt($i); } return $s; }, $reps);
bench('cell->int arg', function (int $n) use ($cells): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += takeInt($cells[0]); } return $s; }, $reps);
bench('cell->int numstr', function (int $n) use ($cells): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += takeInt($cells[4]); } return $s; }, $reps);
bench('cell->string arg', function (int $n) use ($cells): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += takeStr($cells[1]); } return $s; }, $reps);
bench('cell(int)->string', function (int $n) use ($cells): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += takeStr($cells[0]); } return $s; }, $reps);
bench('cell->float arg', function (int $n) use ($cells): int { $s = 0.0; for ($i = 0; $i < $n; $i++) { $s += takeFloat($cells[2]); } return (int)$s; }, $reps);
bench('cell->object arg', function (int $n) use ($cells): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += takeObj($cells[3]); } return $s; }, $reps);
bench('cell receiver', function (int $n) use ($cells): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += $cells[3]->get($i); } return $s; }, $reps);
bench('cell->int prop', function (int $n) use ($cells, $box): int { for ($i = 0; $i < $n; $i++) { $box->n = $cells[0]; } return $box->n; }, $reps);
bench('cell->int return', function (int $n) use ($cells): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += retInt($cells[0]); } return $s; }, $reps);
