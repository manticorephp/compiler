<?php
class Node
{
    public array $pad = [1, 2, 3];
    public ?\Closure $fn = null;
    public ?Node $peer = null;
    public function __construct(public string $name) {}
}
function mk(string $n, string $tag): Node {
    $a = new Node($n);
    $s = $tag . $n;
    $a->fn = function () use ($a, $s) { return $a->name . $s; };
    return $a;
}
// live closure survives a collection and still works
$live = mk('live', '-x');
for ($i = 0; $i < 200; $i++) { $t = mk('t' . $i, '-y'); unset($t); }
gc_collect_cycles();
echo ($live->fn)(), "\n";
// shared closure (rc 2): must not be freed under its second owner
$shared = mk('sh', '-z');
$other = new Node('o');
$other->fn = $shared->fn;
unset($shared);
gc_collect_cycles();
echo ($other->fn)(), "\n";
// cycle through a peer plus a closure
for ($i = 0; $i < 200; $i++) {
    $p = new Node('p'); $q = new Node('q');
    $p->peer = $q; $q->peer = $p;
    $p->fn = function () use ($q) { return $q->name; };
    $q->fn = fn() => $p->name;
    unset($p, $q);
}
gc_collect_cycles();
echo "ok\n";
// $this-bound closure cycle
class Holder {
    public ?\Closure $cb = null;
    public string $v = 'h';
    public function arm(): void { $this->cb = function () { return $this->v; }; }
}
for ($i = 0; $i < 200; $i++) { $h = new Holder(); $h->arm(); unset($h); }
echo gc_collect_cycles() >= 200 ? "freed\n" : "kept\n";
echo ($live->fn)(), "\n";
