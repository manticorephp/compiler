<?php

#[TypeDef(repr: 'u8')]
final class U8
{
    public function __construct(public readonly int $value) {}

    public function add(U8 $other): U8
    {
        return new U8(($this->value + $other->value) & 0xFF);
    }
}

/** @var U8[] $bytes */
$bytes = [];
$bytes[] = new U8(200);
$bytes[] = new U8(100);
$bytes[] = new U8(30);

// The loop-carried accumulator must keep its TypeDef tag across the merge —
// widened to a bare int, the `$sum->value` below reads the scalar as an object.
$sum = new U8(0);
foreach ($bytes as $byte) {
    $sum = $sum->add($byte);
}
echo $sum->value, "\n";

echo $bytes[0]->value, "\n";
echo count($bytes), "\n";

$running = new U8(1);
for ($i = 0; $i < count($bytes); $i = $i + 1) {
    $running = $running->add($bytes[$i]);
}
echo $running->value, "\n";
