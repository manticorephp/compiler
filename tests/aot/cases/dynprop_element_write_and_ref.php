<?php
// `$this->$name[...] = v` and `$r = &$this->$name` write through to the declared property
final class Bag
{
    public array $a = [1 => [1]];
    public array $b = [];
    public int $n = 0;

    public function ref(string $p): void { $r = &$this->$p; $r[1][] = 2; $r[5] = []; $r[5][] = 3; }
    public function two(string $p): void { $x = &$this->$p; $y = &$this->$p; $x[7] = [4]; $y[8] = [5]; }
    public function set(string $p, int $k): void { $this->$p[$k] = [$k]; }
    public function staticRef(): void { $r = &$this->a; $r[9] = [6]; }
    public function bad(string $p): void { $r = &$this->$p; $r = [1]; }
}

foreach (['ref', 'two'] as $m) {
    $o = new Bag();
    $o->$m('a');
    $o->$m('b');
    echo json_encode($o->a), ' ', json_encode($o->b), "\n";
}
$o = new Bag();
$o->set('a', 4);
$o->set('b', 2);
$o->staticRef();
echo json_encode($o->a), ' ', json_encode($o->b), "\n";
try {
    $o->bad('n');
} catch (Error $e) {
    echo "caught\n";
}
