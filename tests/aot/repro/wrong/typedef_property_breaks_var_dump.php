<?php
// A class with a #[TypeDef] property makes any var_dump() in the program a compile error
// issue: #106
#[TypeDef(repr: 'u16')]
final class Kind { public function __construct(public readonly int $value) {} }
final class W { public function __construct(public Kind $k) {} }
$w = new W(new Kind(7));
echo $w->k->value, "\n";
var_dump(1);
