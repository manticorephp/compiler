<?php
class Alias { public function __construct(private string $id) {} public function __toString(): string { return $this->id; } }
class Finder {
    public function find(string $id): string { return "[$id]"; }
    public static function make(string $id): string { return "<$id>"; }
}
function wrap(string $s): string { return "($s)"; }
$f = new Finder;
$a = new Alias('abc.def');
echo $f->find($a), "\n";
echo Finder::make($a), "\n";
echo wrap($a), "\n";
echo wrap(new Alias('fresh')), "\n";
function each(array $m, Finder $f) { foreach ($m as $v) { echo $f->find($v), "\n"; } }
each([new Alias('x1'), new Alias('x2')], $f);
