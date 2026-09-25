<?php

// `#[Struct]` values compare like php objects of the same shape (Zend ignores
// the attribute and runs a plain class, so it is the oracle): property by
// property in declaration order, a nested struct field recursively, under
// ==, !=, <=>, the orderings and switch. A struct has no header, so this is the
// compiler's static compare, not the runtime's.

#[\Manticore\Attr\Struct]
final class Pt
{
    public function __construct(public int $x, public int $y) {}
}

#[\Manticore\Attr\Struct]
final class Seg
{
    public function __construct(public Pt $a, public Pt $b, public string $tag = '') {}
}

function row(Pt $p, Pt $q): void
{
    var_dump($p == $q, $p != $q, $p <=> $q, $p < $q, $p > $q, $p <= $q, $p >= $q);
    $s = 'miss';
    switch ($p) { case $q: $s = 'hit'; break; }
    echo $s, ' ', match (true) { $p == $q => 'eq', default => 'ne' }, "\n";
}

row(new Pt(1, 2), new Pt(1, 2));
row(new Pt(1, 2), new Pt(1, 3));
row(new Pt(2, 0), new Pt(1, 9));
$same = new Pt(5, 5);
row($same, $same);

function seg(Seg $s, Seg $t): void
{
    var_dump($s == $t, $s <=> $t, $s != $t, $t <=> $s);
}
seg(new Seg(new Pt(0, 0), new Pt(1, 1)), new Seg(new Pt(0, 0), new Pt(1, 1)));
seg(new Seg(new Pt(0, 0), new Pt(1, 1)), new Seg(new Pt(0, 0), new Pt(1, 2)));
seg(new Seg(new Pt(0, 0), new Pt(1, 1), 'a'), new Seg(new Pt(0, 0), new Pt(1, 1), 'b'));
