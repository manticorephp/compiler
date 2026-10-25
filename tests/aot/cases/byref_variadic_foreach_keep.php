<?php
// A reference taken to a by-ref foreach variable (`$out[$k] = &$v`, `[&$v]`) points at the loop's copy, not the element.
final class Stmt {
    /** @var array<int, mixed> */
    private array $bound = [];
    public function keep(mixed &...$vars): bool { $this->bound = []; foreach ($vars as $i => &$v) { $this->bound[$i] = &$v; } return true; }
    public function fill(int $n): void { foreach ($this->bound as $i => &$v) { $v = $n === 1 ? $i : ($n === 2 ? "s$i" : null); } }
}
$s = new Stmt();
$s->keep($a, $b);
$s->fill(3); var_dump($a, $b);
$s->fill(1); var_dump($a, $b);
$s->fill(2); var_dump($a, $b);
/** @param array<int, mixed> $vars @return array<int, mixed> */
function keepl(array $vars): array { $out = []; foreach ($vars as $i => &$v) { $out[$i] = &$v; } $vars[0] = 'w'; var_dump($out[0]); return $out; }
keepl([1, 2]);
function lit(): void { $arr = [1, 2]; foreach ($arr as &$v) { $l = [&$v]; $l[0] = 'y'; } unset($v); var_dump($arr[0], $arr[1]); }
lit();
