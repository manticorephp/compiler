<?php
// #502 sibling: `$o->{$n}[i] = v` used as a value goes through a typed dyn-prop store block.
final class Bag { public function __construct(public int $tag) {} }
final class Call {
    /** @param stdClass[] $args */
    public function __construct(public array $args) {}
}
function put(Call $c, string $n, Bag $v): int {
    $o = $c->args[0];
    $o->{$n} = [];
    $r = ($o->{$n}[0] = $v);
    return $r->tag + \count($o->{$n});
}
for ($i = 0; $i < 3; $i++) {
    echo put(new Call([new stdClass(), $i > 100 ? "x" : $i]), 'p', new Bag($i)), "\n";
}
