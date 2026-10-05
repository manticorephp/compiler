<?php
// A conditional whose arm is a live local, stored into a cell container: the
// container owns the arm's word with a count of its own, so freeing the
// container leaves the local readable — erased array, concrete array, object.
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
function erase(array $a): array { return $a; }
function erased(bool $f): void
{
    $r = erase(['k' => new D('e' . ($f ? 't' : 'f'))]);
    $c = [1, "x"];
    $c[] = $f ? $r : [];
    unset($c);
    echo "erased ", $r['k']->n, "\n";
}
function concrete(bool $f): void
{
    $r = ['k' => new D('c' . ($f ? 't' : 'f'))];
    $c = [1, "x"];
    $c[] = $f ? $r : [];
    unset($c);
    echo "concrete ", $r['k']->n, "\n";
}
function objArm(bool $f): void
{
    $o = new D('o' . ($f ? 't' : 'f'));
    $c = [1, "x"];
    $c[] = $f ? $o : null;
    unset($c);
    echo "object ", $o->n, "\n";
}
foreach ([true, false] as $f) {
    erased($f);
    concrete($f);
    objArm($f);
}
echo "done\n";
