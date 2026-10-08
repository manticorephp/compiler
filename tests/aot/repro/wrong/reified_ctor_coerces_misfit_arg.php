<?php
// A bound `new Box(...)` coerces a constructor argument that does not fit the binding's template type.
// issue: #136
/** @template T */
final class Box
{
    public string $kind;
    /** @param T $v */
    public function __construct(mixed $v) { $this->kind = get_debug_type($v); }
    /** @param T $v */
    public function same(mixed $v): string { return get_debug_type($v); }
}
/** @var Box<string> $b */
$b = new Box(5);
echo $b->kind, "\n";
/** @var Box<int> $c */
$c = new Box(2.5);
echo $c->kind, "\n";
