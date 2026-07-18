<?php

#[TypeDef]
final class U8
{
    public function __construct(public readonly int $value) {}
}

function cmp(U8 $a, U8 $b): bool
{
    return $a === $b;
}

echo cmp(new U8(5), new U8(5)) ? "y\n" : "n\n";
