<?php
class UniformAbiBase {
    public function abi_m00(int $x): int { return $x + 0; }
    public function abi_m01(int $x): int { return $x + 1; }
    public function abi_m02(int $x): int { return $x + 2; }
    public function abi_m03(int $x): int { return $x + 3; }
    public function abi_m04(int $x): int { return $x + 4; }
    public function abi_m05(int $x): int { return $x + 5; }
    public function abi_m06(int $x): int { return $x + 6; }
    public function abi_m07(int $x): int { return $x + 7; }
    public function abi_m08(int $x): int { return $x + 8; }
    public function abi_m09(int $x): int { return $x + 9; }
    public function abi_m10(int $x): int { return $x + 10; }
    public function abi_m11(int $x): int { return $x + 11; }
    public function abi_m12(int $x): int { return $x + 12; }
    public function abi_m13(int $x): int { return $x + 13; }
    public function abi_m14(int $x): int { return $x + 14; }
    public function abi_m15(int $x): int { return $x + 15; }
}
class UniformAbiChild extends UniformAbiBase {
    public function abi_m07(int $x): int { return $x + 70; }
}
function uniform_abi_call(UniformAbiBase $o, string $name, array $args): int {
    return $o->$name(...$args);
}
echo uniform_abi_call(new UniformAbiChild(), 'abi_m07', [2]), "\n";
