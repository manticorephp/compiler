<?php

use Manticore\Ds\ByteBuffer;

// A tiny binary record: u16 id, i32 delta, f64 score.
$b = new ByteBuffer(14);
$b->setUInt16(0, 0xBEEF);
$b->setInt32(2, -5);
$b->setFloat64(6, 2.5);

echo bin2hex($b->toString()), "\n";           // little-endian by default
echo dechex($b->getUInt16(0)), "\n";
echo $b->getInt32(2), "\n";
echo $b->getFloat64(6), "\n";
echo dechex($b->getUInt16(0, true)), "\n";    // the same bytes, big-endian

$c = ByteBuffer::fromString("ABC");
echo $c[1], ' ', $c->toString(1, 2), "\n";

try {
    $b->getUInt32(12);                         // runs past the end
} catch (OutOfBoundsException $e) {
    echo $e->getMessage(), "\n";
}
try {
    $b->setUInt16(0, 70000);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
