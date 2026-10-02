<?php
// A foreach over an erased iterable binds a borrowed element; a guard that
// rebinds it to a fresh object (`if (!$x instanceof D) { $x = new D($x); }`)
// makes the name a cell at the merge. The borrowed and the owned path meet
// as one cell class: the fresh object is released on the next rebinding,
// the element stays with its array or generator.
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
function items(iterable $it): void
{
    foreach ($it as $x) {
        if (!$x instanceof D) { $x = new D('made-' . $x); }
        echo $x->n, "\n";
    }
    echo "end\n";
}
function gen(iterable $it)
{
    foreach ($it as $x) {
        if (!$x instanceof D) { $x = new D('gen-' . $x); }
        $k = $x->n;
        yield $k => $x;
    }
}
$keep = [new D('kept'), 'a', 'b'];
items($keep);
foreach (gen(['c', 'd']) as $k => $v) { echo $k, "\n"; }
echo "unset\n";
$keep = null;
echo "done\n";
