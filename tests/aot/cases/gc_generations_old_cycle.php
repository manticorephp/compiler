<?php
// A cycle through an object promoted to the old generation is collected by an automatic full collection and by gc_collect_cycles(); a young object referenced by a live old one survives young collections.
class N {
    public $peer = null;
    public $kid = null;
    public static array $dead = [];
    public function __construct(public string $name) {}
    public function __destruct() { N::$dead[$this->name] = (N::$dead[$this->name] ?? 0) + 1; }
}
class Pair { public $peer = null; }
function churn(int $n): void {
    for ($i = 0; $i < $n; $i++) {
        $a = new Pair; $b = new Pair;
        $a->peer = $b; $b->peer = $a;
    }
}
function dead(string $name): int { return N::$dead[$name] ?? 0; }
function age(N $o): void { $t = $o; unset($t); gc_collect_cycles(); }

// A: young objects reachable only through a live old object.
$keep = new N('keep');
age($keep);
$y = new N('y');
$y->peer = $keep;
$keep->kid = $y;
unset($y);
churn(60000);
echo "A: ", $keep->kid->name, " ", $keep->kid->peer->name, " dead=", dead('y') + dead('keep'), "\n";

// B: a garbage cycle young <-> old, left to automatic collection.
$o = new N('o');
age($o);
$y = new N('y2');
$y->peer = $o;
$o->peer = $y;
unset($y, $o);
$i = 0;
while (dead('o') === 0 && $i < 2000) { churn(1000); $i++; }
echo "B: ", dead('o') === 1 && dead('y2') === 1 ? "collected" : "leaked", "\n";

// C: the same cycle, both members old, freed by an explicit call.
$o = new N('o3');
age($o);
$y = new N('y3');
$y->peer = $o;
$o->peer = $y;
age($y);
unset($y, $o);
gc_collect_cycles();
echo "C: ", dead('o3') + dead('y3'), "\n";
echo "A again: ", $keep->kid->name, "\n";
