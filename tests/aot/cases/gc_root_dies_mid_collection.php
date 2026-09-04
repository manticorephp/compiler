<?php
// A cycle root that reaches rc 0 while the collector still owns it must be
// freed, and its __destruct must run, exactly as php does. Two nodes of a dead
// cycle each hold the same Leaf in an ARRAY property, so the Leaf loses its last
// reference from inside collect_white.
class Leaf {
    public string $t = '';
    public function __destruct() { echo "bye ", $this->t, "\n"; }
}
class Node {
    public ?Node $next = null;
    /** @var Leaf[] */
    public array $bag = [];
}
function mk(): void {
    $a = new Node();
    $b = new Node();
    $a->next = $b; $b->next = $a;
    $l = new Leaf(); $l->t = "L";
    $a->bag[] = $l;
    $b->bag[] = $l;
    unset($a);
    unset($b);
    unset($l);
}
mk();
gc_collect_cycles();
echo "after collect\n";
echo "end\n";
