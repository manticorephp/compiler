<?php
// #502 sibling: a postfix `$o->p[$k]++` used as a value saves the old value in a typed temp block.
final class Box { public function __construct(public array $c) {} }
final class Call {
    /** @param Box[] $args */
    public function __construct(public array $args) {}
}
function bump(Call $c, string $k): int {
    $b = $c->args[0];
    $old = $b->c[$k]++;
    return $old * 10 + $b->c[$k];
}
for ($i = 0; $i < 3; $i++) {
    echo bump(new Call([new Box(['a' => $i]), $i > 100 ? "x" : $i]), 'a'), "\n";
}
