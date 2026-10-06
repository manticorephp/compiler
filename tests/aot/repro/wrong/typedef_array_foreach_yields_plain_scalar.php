<?php
// foreach over a typed array bound to a #[TypeDef] yields the plain scalar, not the element type
// issue: #107
use Manticore\Ds\UInt16Array;

#[TypeDef(repr: 'u16')]
final class Kind
{
    public function __construct(public readonly int $value) {}
    public function isComment(): bool { return $this->value === 7; }
}

/** @var UInt16Array<Kind> $k */
$k = new UInt16Array(2);
$k[0] = new Kind(7);
foreach ($k as $kind) { echo $kind->isComment() ? 'C' : '.'; }
echo "\n";
