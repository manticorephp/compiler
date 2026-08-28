<?php
class ErasedMagicReceiver
{
    public function __call(string $name, array $arguments): int
    {
        return $name === 'missing_command' && count($arguments) === 2 ? 99 : -1;
    }
    public function abi_m00(): int { return 0; }
    public function abi_m01(): int { return 1; }
    public function abi_m02(): int { return 2; }
    public function abi_m03(): int { return 3; }
    public function abi_m04(): int { return 4; }
    public function abi_m05(): int { return 5; }
    public function abi_m06(): int { return 6; }
    public function abi_m07(): int { return 7; }
    public function abi_m08(): int { return 8; }
    public function abi_m09(): int { return 9; }
    public function abi_m10(): int { return 10; }
    public function abi_m11(): int { return 11; }
    public function abi_m12(): int { return 12; }
    public function abi_m13(): int { return 13; }
    public function abi_m14(): int { return 14; }
    public function abi_m15(): int { return 15; }
}

function erased_magic_call(object $o, string $name, array $args): int
{
    return $o->{$name}(...$args);
}

echo erased_magic_call(new ErasedMagicReceiver(), 'missing_command', [1, 2]), "\n";
