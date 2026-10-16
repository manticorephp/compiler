<?php
// ->{$c ? 'a' : 'b'}() on a call-chain receiver typed as the parent class whose method lives only on a subclass returned null
abstract class Node0
{
    public array $log = [];
    public function info(string $s): static { $this->log[] = $s; return $this; }
    public function rest(...$xs): static { return $this; }
}
final class Leaf extends Node0 {}
final class Branch extends Node0
{
    public function on(): static { $this->log[] = 'on'; return $this; }
    public function off(): static { $this->log[] = 'off'; return $this; }
    public function kids(): string { return implode(',', $this->log); }
}
final class Bld
{
    private Branch $b;
    public function __construct() { $this->b = new Branch(); }
    public function make(string $n): Node0 { return $this->b->info($n); }
}
function run(Bld $bld, callable $avail): string
{
    return $bld->make('x')->info('y')->{$avail('p') ? 'on' : 'off'}()->kids();
}
echo run(new Bld(), fn ($p) => $p === 'p'), "\n";
echo run(new Bld(), fn ($p) => $p !== 'p'), "\n";
