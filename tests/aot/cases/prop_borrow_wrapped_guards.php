<?php
// A property value handed on through a wrapper — a same-kind cast, a spread,
// a conditional whose arm is the value — must stay alive while the call it
// feeds overwrites the property.
final class V {
    public function __construct(public string $s) {}
    public function __destruct() { echo "free ", $this->s, "\n"; }
}
final class Box {
    public string $str = '';
    /** @var V[] */
    public array $arr = [];
    public ?V $p = null;
    public function reset(): string { $this->str = 'k'; $this->junk = str_repeat('z', 24); return '|'; }
    public string $junk = '';
    public function take(string $a, string $sep): string { return $a . $sep; }
    public function spread(V $x, V $y): string { $this->arr = [new V('n')]; $this->junk = str_repeat('w', 24); return $x->s . $y->s; }
    public function swap(mixed $old): string { $this->p = new V('q'); return $old instanceof V ? $old->s : '-'; }
}
function erased(): mixed { return 'e'; }
$b = new Box();
$b->str = str_repeat('x', 24);
$r = $b->take((string)$b->str, $b->reset()); echo $r, "\n";
echo "--\n";
$b->arr = [new V('a'), new V('b')];
$r = $b->spread(...$b->arr); echo $r, "\n";
echo "--\n";
$b->p = new V('p');
$c = strlen($r) > 0;
$e = erased();
$r = $b->swap($c ? $b->p : $e); echo $r, "\n";
echo "end\n";
