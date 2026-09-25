<?php
// A closure read back out of a cell element and handed on as a `\Closure`
// (a return, an argument) is unboxed like an object, not passed tagged.
final class Resolver
{
    private array $normalizers = [];

    public function set(string $o, \Closure $f): static { $this->normalizers[$o] = [$f]; return $this; }

    public function add(string $o, \Closure $f, bool $prepend = false): static
    {
        if ($prepend) {
            $this->normalizers[$o] ??= [];
            array_unshift($this->normalizers[$o], $f);
        } else {
            $this->normalizers[$o][] = $f;
        }
        return $this;
    }

    public function first(string $o): \Closure { return $this->normalizers[$o][0]; }

    public function apply(string $o, mixed $v): mixed
    {
        foreach ($this->normalizers[$o] ?? [] as $f) { $v = $f($v); }
        return $v;
    }
}

function run(\Closure $f, string $v): string { return $f($v); }

$r = new Resolver();
$r->set('a', fn($v) => $v . '1')->add('a', fn($v) => $v . '2')->add('a', fn($v) => '0' . $v, true);
$r->add('b', fn($v) => $v * 2);
echo $r->apply('a', 'x'), "\n";
echo $r->apply('b', 21), "\n";
echo $r->apply('c', 'z'), "\n";
$f = $r->first('a');
echo $f('y'), "\n";
echo run($r->first('a'), 'w'), "\n";
