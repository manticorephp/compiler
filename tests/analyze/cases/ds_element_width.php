<?php

use Manticore\Ds\UInt8Array;

#[TypeDef(repr: 'u16')]
final class Kind
{
    public function __construct(public readonly int $value) {}
}

/** @var UInt8Array<Kind> $k */
$k = new UInt8Array(4);
$k[0] = new Kind(300);
echo count($k), "\n";
