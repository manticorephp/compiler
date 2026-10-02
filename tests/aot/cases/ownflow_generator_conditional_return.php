<?php
// A conditional return inside a generator: the arm that is returned stays in
// the frame's return slot (getReturn() still reads it), the Own arm that is not
// returned and every other owned local die when the generator finishes. Objects
// still alive at the end are kept silent (the frame itself outlives this test).
final class D
{
    public static bool $on = true;
    public function __construct(public string $n) {}
    public function __destruct() { if (self::$on) { echo "~", $this->n, "\n"; } }
}
function gen(D $p, bool $c): Generator
{
    $x = new D($c ? 'x-taken' : 'x-untaken');
    $y = new D('y');
    yield 1;
    echo "returning\n";
    return $c ? $x : $p;
}
$keep = [];
foreach ([true, false] as $c) {
    echo "-- ", $c ? 'own arm' : 'borrow arm', "\n";
    $p = new D('param');
    $g = gen($p, $c);
    foreach ($g as $v) { echo "y", $v, "\n"; }
    echo "finished\n";
    $r = $g->getReturn();
    echo "ret ", $r->n, "\n";
    echo "again ", $g->getReturn()->n, "\n";
    $keep[] = $g;
    $keep[] = $r;
    $keep[] = $p;
}
D::$on = false;
echo "done\n";
