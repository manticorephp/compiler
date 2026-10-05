<?php
// A param floored to vec[cell] by one call site still receives a RAW int
// buffer from another; binding it to a concrete `list<int>` local rebuilds by
// the buffer's own hint, not by assuming every word is a cell.
final class M {
    /** @param list<int> $a */
    public function first(array $a): int {
        /** @var list<int> $t */
        $t = $a;
        return $t[0] + 1;
    }
    /** @param list<bool> $a */
    public function flag(array $a): string {
        /** @var list<bool> $t */
        $t = $a;
        return $t[0] ? 'yes' : 'no';
    }
}
$m = new M();
$d = [1, 2]; $d[] = "x"; array_pop($d);
var_dump($m->first($d), $m->first([7, 8]), $m->first([-5, 1]), $m->first([PHP_INT_MAX - 1]), $m->first(range(4, 5)));
$c = [true]; $c[] = 'x'; array_pop($c);
var_dump($m->flag($c), $m->flag([false]), $m->flag([true]));
