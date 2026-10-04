<?php
// A cell-element array handed to a ctor's concrete-element doc param stays the
// caller's value: binding it must not rewrite the caller's buffer, and the
// callee must still read its own claim right.
final class F {
    /** @var list<float> */ public array $f;
    /** @param list<float> $f */
    public function __construct(array $f) { $this->f = $f; }
}
final class Q {
    /** @var list<int> */ public array $j;
    /** @param list<int> $j */
    public function __construct(array $j) { $this->j = $j; }
}
$r = range(1, 3);
$f = new F($r);
var_dump($r);

$l = [1, null, 'z'];
$q = new Q($l);
var_dump($l);

$m = range(4, 6);
$q = new Q($m);
var_dump($m, array_shift($q->j), $m);
