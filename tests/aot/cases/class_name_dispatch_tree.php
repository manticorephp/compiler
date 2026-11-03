<?php
// newInstanceWithoutConstructor / unserialize pick a class by NAME out of a balanced tree.
// Names that are prefixes of one another, namespaced, differing in case, and unknown ones.
namespace {
    class A { public int $v = 1; }
    class AB { public int $v = 2; }
    class ABC { public int $v = 3; }
    class A_ { public int $v = 4; }
    class B { public int $v = 5; }
    class Z { public int $v = 6; }
    class a1 { public int $v = 7; }
    class _under { public int $v = 8; }
    class Mid { public int $v = 9; }
    class Mid2 { public int $v = 10; }
    class Zebra { public int $v = 11; }
}
namespace N1 {
    class A { public int $v = 12; }
    class AB { public int $v = 13; }
}
namespace N1\Sub {
    class A { public int $v = 14; }
    class Zz { public int $v = 15; }
}
namespace N2 {
    class A { public int $v = 16; }
    class Last { public int $v = 17; }
}
namespace {
    $names = ['A', 'AB', 'ABC', 'A_', 'B', 'Z', 'a1', '_under', 'Mid', 'Mid2', 'Zebra',
        'N1\A', 'N1\AB', 'N1\Sub\A', 'N1\Sub\Zz', 'N2\A', 'N2\Last'];
    foreach ($names as $n) {
        $o = (new ReflectionClass($n))->newInstanceWithoutConstructor();
        $u = unserialize(serialize(new $n));
        echo str_pad($n, 10), get_class($o), ' ', $o->v, ' ', get_class($u), ' ', $u->v, "\n";
    }
    // unknown / near-miss names are not classes
    foreach (['', 'AA', 'ABCD', 'A\B', 'N1', 'N1\Sub', 'zebra2', 'N3\A'] as $n) {
        try { (new ReflectionClass($n))->newInstanceWithoutConstructor(); echo "$n: made\n"; }
        catch (ReflectionException $e) { echo "$n: ", get_class($e), "\n"; }
    }
}
