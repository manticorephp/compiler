<?php
class DynamicFallbackShapes
{
    public function byref_command(int &$value): int
    {
        $value = $value + 4;
        return $value;
    }

    public function variadic_command(int ...$values): int
    {
        $sum = 0;
        foreach ($values as $value) { $sum = $sum + $value; }
        return $sum;
    }
}

function erased_byref_call(object $o, string $name, int &$value): int
{
    return $o->{$name}($value);
}

function erased_variadic_call(object $o, string $name, array $args): int
{
    return $o->{$name}(...$args);
}

$o = new DynamicFallbackShapes();
$x = 3;
$r1 = erased_byref_call($o, "byref_command", $x);
$r2 = erased_variadic_call($o, "variadic_command", [2, 5, 7]);
echo $r1, ":", $x, ":", $r2, "\n";
