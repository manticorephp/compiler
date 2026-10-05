<?php
// A property value still in use while the same slot is overwritten must stay
// alive until its use ends — the read co-owns across a writing call.
final class V {
    public function __construct(public string $s) {}
    public function __destruct() { echo "free ", $this->s, "\n"; }
    public function detach(Box $b): string { $b->cur = new V('n'); return $this->s . '!'; }
}
final class Box {
    public ?V $cur = null;
    /** @var V[] */
    public array $items = [];
    public string $str = '';
    public function reset(): string { $this->str = str_repeat('y', 3); $this->cur = new V('r'); $this->items = []; return '|'; }
    public function take(V $a, string $sep): string { return $a->s . $sep; }
    public function first(V $v): string { $this->items = [new V('z')]; return $v->s; }
}
$b = new Box();
$b->cur = new V('a');
$r = $b->cur->detach($b); echo $r, "\n";
echo "--\n";
$b->str = str_repeat('x', 3);
$r = $b->str . $b->reset(); echo $r, "\n";
echo "--\n";
$b->cur = new V('c');
$r = $b->take($b->cur, $b->reset()); echo $r, "\n";
echo "--\n";
$b->items = [new V('e0'), new V('e1')];
foreach ($b->items as $it) { $b->items = []; echo "it ", $it->s, "\n"; }
$it = null;
echo "--\n";
$b->items = [new V('f0')];
$r = $b->first($b->items[0]); echo $r, "\n";
echo "end\n";
