<?php
class AcRow { public int $id; public string $n;
    public function __construct(int $i, string $n) { $this->id = $i; $this->n = $n; } }
enum AcId: string { case A = 'a'; case B = 'b'; }
enum AcNum: int { case X = 7; case Y = 9; }

$rows = [['id' => 1, 'n' => 'x'], ['id' => 2, 'n' => 'y']];
echo \implode(',', \array_column($rows, 'n')), "\n";
echo \implode(',', \array_keys(\array_column($rows, 'n', 'id'))), "\n";
echo \implode(',', \array_column(AcId::cases(), 'value')), "\n";
echo \implode(',', \array_column(AcNum::cases(), 'value')), "\n";
echo \implode(',', \array_column(AcId::cases(), 'name')), "\n";
$assoc = ['p' => ['id' => 3, 'n' => 'z'], 'q' => ['id' => 4, 'n' => 'w']];
echo \implode(',', \array_column($assoc, 'n')), "\n";
$objs = [new AcRow(5, 'o'), new AcRow(6, 'p')];
echo \implode(',', \array_column($objs, 'n')), "\n";
echo \implode(',', \array_keys(\array_column($objs, 'n', 'id'))), "\n";
$missing = [['id' => 1], ['id' => 2, 'n' => 'q']];
echo \implode(',', \array_column($missing, 'n')), "\n";
$whole = \array_column($rows, null, 'id');
echo \implode(',', \array_keys($whole)), "\n";
