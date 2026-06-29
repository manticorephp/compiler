<?php
// vec[obj] returned from an `: array` function: NarrowReturns types it
// vec[obj<Foo>] and the caller's scope-exit release walks + drops each
// obj element before freeing the buffer (recursive: Foo->next too).
class Foo {
    public int $x;
    public ?Foo $next;
    function __construct(int $x) { $this->x = $x; $this->next = null; }
}
function make(): array {
    $out = [];
    $a = new Foo(1);
    $a->next = new Foo(10);
    $out[] = $a;
    $out[] = new Foo(2);
    return $out;
}
$acc = 0;
$i = 0;
while ($i < 1000) {
    $v = make();
    $acc = $acc + $v[0]->x + $v[0]->next->x + $v[1]->x;
    $i = $i + 1;
}
echo $acc, "\n";
