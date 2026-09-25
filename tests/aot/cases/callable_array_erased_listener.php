<?php
final class P { public int $n = 0; public function on(string $e, string $name): void { $this->n++; echo "on $e $name {$this->n}\n"; } public static function st(string $e): void { echo "st $e\n"; } }
final class Disp {
    /** @var list<callable|array> */
    private array $ls = [];
    public function add(callable|array $l): void { $this->ls[] = $l; }
    public function fire(string $e): void { foreach ($this->ls as $l) { $l($e, 'ev'); } }
}
$p = new P();
$d = new Disp();
$d->add([$p, 'on']);
$d->add(static fn (string $e, string $n) => print("fn $e $n\n"));
$d->add([$p, 'on']);
$d->fire('x'); $d->fire('y');
echo $p->n, "\n";
