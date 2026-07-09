<?php
// A `->prop` read on a receiver whose class is erased (a bare-`array` param
// element) must resolve $prop's REAL per-holder offset via the runtime class_id
// and render by the slot's declared type — NOT blind-read byte offset 16.

class A { public int $x = 10; public string $s = "aa"; }
class B { public string $s = "bee"; public int $x = 20; }

function f(array $items): void
{
    foreach ($items as $it) {
        var_dump($it->s);
        var_dump($it->x + 1);
    }
}

f([new A(), new B()]);

// Single-holder erased receiver (no class_id switch).
function g(array $items): void
{
    var_dump($items[0]->s);
}
g([new A()]);
