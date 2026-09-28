<?php
// A literal argument's ARRAY elements were released after the call only on a
// free-function call; a method, static or constructor call released the outer
// buffer alone, so `$tok->equalsAny([[T_STRING, 'get'], [T_STRING, 'set']])`
// leaked both inner arrays per call (php-cs-fixer's BraceTransformer).
final class Tok { public function __construct(public string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
final class T {
    public function cnt(array $others): int { return \count($others); }
    public static function scnt(array $others): int { return \count($others); }
}
final class K { public int $n; /** @param list<list<mixed>> $others */ public function __construct(array $others) { $this->n = \count($others); } }
function fcnt(array $others): int { return \count($others); }
$t = new T();
echo $t->cnt([[new Tok('m1'), 'x'], [new Tok('m2'), 'y']]), "\n";
echo "after method\n";
echo T::scnt([[new Tok('s1'), 'x'], [new Tok('s2'), 'y']]), "\n";
echo "after static\n";
echo (new K([[new Tok('c1'), 'x'], [new Tok('c2'), 'y']]))->n, "\n";
echo "after ctor\n";
echo fcnt([[new Tok('f1'), 'x'], [new Tok('f2'), 'y']]), "\n";
echo "after function\n";
// …and a literal REBUILT into a cell array for a `mixed[]` / `mixed` parameter
// co-owned each inner array on top of the literal's own reference.
final class M {
    /** @param list<mixed> $others */
    public function cm(array $others): int { return \count($others); }
    public function mx(mixed $others): int { return \count($others); }
}
$m = new M();
echo $m->cm([[new Tok('l1'), 'x'], [new Tok('l2'), 'y']]), "\n";
echo "after list<mixed>\n";
echo $m->mx([[new Tok('x1'), 'x'], [new Tok('x2'), 'y']]), "\n";
echo "after mixed\n";
