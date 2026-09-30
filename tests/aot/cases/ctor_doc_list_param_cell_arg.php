<?php
// A doc-typed `list<int>` param of a ctor, an inherited method and an inherited
// static (its late-static-binding clone) handed range()'s cell-element buffer:
// each is a call site that floors the param, and the property stays honest.
final class Q {
    /** @var list<int> */ public array $j;
    /** @param list<int> $j */
    public function __construct(array $j) { $this->j = $j; }
}
function mk(): Q { return new Q(range(1, 4)); }

$q = new Q(range(1, 3)); var_dump(array_shift($q->j));
$r = range(1, 3); $q = new Q($r); var_dump(array_shift($q->j), $r[0]);
$q = mk(); var_dump(array_shift($q->j) + 1);
$q = mk(); var_dump(array_pop($q->j) + 1);
$q = mk(); var_dump(reset($q->j) + 1, end($q->j) + 1, current($q->j) + 1);
$q = mk(); $sum = 0; foreach ($q->j as $v) { $sum += $v; } var_dump($sum);
$q = mk(); $x = array_splice($q->j, 1, 2); var_dump($x[0] + 1, $q->j[1] + 1);
$q = mk(); array_unshift($q->j, 0); var_dump($q->j[0] + $q->j[1]);
$q = mk(); var_dump(max($q->j), min($q->j), array_sum($q->j), in_array(2, $q->j, true), array_search(3, $q->j, true));
$q = mk(); $q->j[] = 9; var_dump(array_pop($q->j), array_shift($q->j), $q->j);
$q = mk(); $j = $q->j; var_dump(array_shift($j) + 1);
$q = mk(); var_dump(array_key_first($q->j), array_slice($q->j, 1, 1)[0] + 1, array_reverse($q->j)[0] + 1);
// A literal site next to the cell one: both must read right.
$q = new Q([7, 8]); var_dump(array_shift($q->j) + 1, $q->j);

// The ctor body reading its own param, and a subclass inheriting the ctor.
final class W {
    public int $x = 0;
    /** @param list<int> $j */
    public function __construct(array $j) { $this->x = array_shift($j) + $j[0]; }
}
var_dump((new W(range(1, 3)))->x);
class Base { /** @var list<int> */ public array $j;
    /** @param list<int> $j */ public function __construct(array $j) { $this->j = $j; } }
final class Sub extends Base {}
$b = new Sub(range(5, 7)); var_dump(array_shift($b->j) + 1, array_pop($b->j) + 1);
// The same site through an INHERITED method and an inherited static.
class B2 { /** @var list<int> */ public array $j = [];
    /** @param list<int> $j */ public function set(array $j): void { $this->j = $j; }
    /** @param list<int> $j */ public static function mk(array $j): static { $o = new static(); $o->j = $j; return $o; } }
final class S2 extends B2 {}
$s = new S2(); $s->set(range(1, 3)); var_dump(array_shift($s->j) + 1);
$s = S2::mk(range(4, 6)); var_dump(array_shift($s->j) + 1);
