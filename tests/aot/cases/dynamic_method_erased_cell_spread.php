<?php
class ErasedCellSpreadTarget
{
    public function call0(): int { return 0; }
    public function call1(int $a): int { return $a; }
    public function call2(int $a): int { return $a; }
    public function call3(int $a): int { return $a; }
    public function call4(int $a): int { return $a; }
    public function call5(int $a): int { return $a; }
    public function call6(int $a): int { return $a; }
    public function call7(int $a): int { return $a; }
    public function call8(int $a): int { return $a; }
    public function call9(int $a): int { return $a; }
    public function call10(int $a): int { return $a; }
    public function call11(int $a): int { return $a; }
    public function call12(int $a): int { return $a; }
    public function call13(int $a): int { return $a; }
    public function call14(int $a): int { return $a; }
    public function call15(int $a): int { return $a; }
}
function erased_cell_spread_call(object $o, string $name, mixed $args): int
{
    return $o->{$name}(...$args);
}
$o = new ErasedCellSpreadTarget();
$boxed = [72];
echo erased_cell_spread_call($o, "call1", $boxed), "\n";
