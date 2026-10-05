<?php
// array_shift / array_pop / array_unshift on a property read through an ERASED
// receiver ($s is a cell: int first, then an object) write the relocated
// buffer back through the class_id writer, not a guessed slot of the tagged word.
final class Q {
    /** @var list<int> */ public array $j;
    /** @param list<int> $j */
    public function __construct(array $j) { $this->j = $j; }
}
function mk(): Q { return new Q(range(1, 4)); }
$q = mk(); $s = 0; foreach ($q->j as $v) { $s += $v; } var_dump($s);
class B2 { /** @var list<int> */ public array $j = [];
    /** @param list<int> $j */ public function set(array $j): void { $this->j = $j; }
    /** @param list<int> $j */ public static function mk(array $j): static { $o = new static(); $o->j = $j; return $o; } }
final class S2 extends B2 {}
$s = new S2(); $s->set(range(1, 3)); var_dump(array_shift($s->j) + 1);
$s = S2::mk(range(4, 6)); var_dump(array_shift($s->j) + 1);
$s->j = [1, 2]; array_unshift($s->j, 0); var_dump($s->j); var_dump(array_pop($s->j));
