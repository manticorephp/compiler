<?php
class P { public function __construct(public int $x = 1, public string $s = 'd') {} public function show(): string { return static::class . "($this->x,$this->s)"; } }
class Q extends P {}
class NoCtor { public string $v = 'plain'; public function show(): string { return 'NoCtor:' . $this->v; } }
class Two { public function __construct(public array $items, public ?P $p = null) {} public function show(): string { return 'Two:' . count($this->items) . ':' . ($this->p ? $this->p->show() : '-'); } }
function make(string $c, array $args): object { return new $c(...$args); }
function make2(string $c, int $x): object { return new $c($x); }
$m = 'show';
foreach (['P', 'Q', 'NoCtor'] as $c) {
    $o = new $c();
    echo $o->$m(), "\n";
}
echo make('P', [7, 'z'])->$m(), "\n";
echo make('Q', [8])->$m(), "\n";
echo make2('P', 9)->$m(), "\n";
echo make('Two', [[1, 2, 3], new P(4)])->$m(), "\n";
echo make('Two', [[]])->$m(), "\n";
$name = 'P';
$p = new $name(5, 'v');
echo $p->show(), ' ', $p instanceof P ? 'yes' : 'no', "\n";
