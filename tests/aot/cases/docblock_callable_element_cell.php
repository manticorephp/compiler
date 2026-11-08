<?php
class Cl {
    /** @var array<string, list<callable>> */
    private array $casters = [];
    public function addCasters(array $casters): void {
        foreach ($casters as $type => $callback) { $this->casters[$type][] = $callback; }
    }
    public function n(): string { return json_encode(array_map('count', $this->casters)); }
    public function run($t): string { $o = ''; foreach ($this->casters[$t] ?? [] as $cb) { $o .= is_string($cb) ? $cb : (is_array($cb) ? implode('::', $cb) : 'closure'); } return $o; }
}
$c = new Cl;
$c->addCasters(['A' => 'f::a', 'B' => ['X', 'b'], 'C' => fn() => 1]);
$c->addCasters(['A' => 'g::a']);
echo $c->n(), ' ', $c->run('A'), ' ', $c->run('B'), ' ', $c->run('C'), "\n";
