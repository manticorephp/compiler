<?php
// A ternary between a fresh array and a co-owned cell-element property read,
// stored into a concrete-element slot: the de-cellify rebuild moved the
// elements out of the property's own buffer (over-release, SIGTRAP).
final class T { public function __construct(public string $k) {} }
final class I {
    /** @var array<string, T> */
    private array $lt = [];
    /** @var array<int, array<string, T>> */
    private array $conts = [];
    /** @var array<int, bool> */
    private array $set = [];
    /** @param mixed $m */
    public function poke(mixed $m): void { $this->lt['m'] = $m; }
    /** @param array<string,T> $a @param array<string,T> $b @return array<string,T> */
    private function join(array $a, array $b): array { $o = []; foreach ($b as $k => $t) { $o[$k] = isset($a[$k]) ? $a[$k] : $t; } return $o; }
    public function step(int $i): int {
        $this->lt['a' . ($i % 4)] = new T('x' . $i);
        $j = $i % 2;
        $this->conts[$j] = ($this->set[$j] ?? false) ? $this->join($this->conts[$j], $this->lt) : $this->lt;
        $this->set[$j] = ($i % 5) !== 0;
        $n = 0; foreach ($this->conts as $c) { foreach ($c as $t) { $n += strlen($t->k); } }
        return $n;
    }
}
$o = new I(); $o->poke(new T('p')); $s = 0;
for ($i = 0; $i < 3000; $i++) { $s += $o->step($i); }
echo $s, "\n";
