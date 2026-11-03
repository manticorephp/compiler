<?php
// new $class(...$args) where $args is an untyped array, a bare array parameter, or a property
class P0 { public function __construct() {} public function d() { return 'P0'; } }
class P1 { public function __construct(public int $a) {} public function d() { return "P1($this->a)"; } }
class P2 { public function __construct(public string $a, public string $b = 'dflt') {} public function d() { return "P2($this->a,$this->b)"; } }
class P3 { public function __construct(public $a, public $b, public $c) {} public function d() { return "P3(" . json_encode([$this->a, $this->b, $this->c]) . ")"; } }
class Maker {
    public array $held = ['x', 'y'];
    public function make($class, $args) { return new $class(...$args); }
    public function makeTyped(string $class, array $args) { return new $class(...$args); }
    public function makeHeld(string $class) { return new $class(...$this->held); }
    public function makeCall(string $class, array $args) { return new $class(...array_values($args)); }
    public function makeCast(string $class, $v) { return new $class(...(array) $v); }
    public function makeTern(string $class, bool $c, array $a, array $b) { return new $class(...($c ? $a : $b)); }
}
$m = new Maker;
echo $m->make('P0', [])->d(), ' ', $m->make('P1', [7])->d(), ' ', $m->make('P2', ['q'])->d(), ' ', $m->make('P3', [1, 'two', [3]])->d(), "\n";
echo $m->makeTyped('P1', [9])->d(), ' ', $m->makeTyped('P2', ['a', 'b'])->d(), "\n";
echo $m->makeHeld('P2')->d(), "\n";
echo $m->makeCall('P1', ['k' => 3])->d(), ' ', $m->makeCast('P1', 4)->d(), ' ', $m->makeCast('P2', ['u', 'v'])->d(), ' ', $m->makeTern('P2', true, ['t'], ['f'])->d(), ' ', $m->makeTern('P2', false, ['t'], ['f', 'g'])->d(), "\n";
for ($i = 0; $i < 3; $i++) { $m->makeCall('P3', [$i, [$i], 'x']); }
echo "done\n";
// a dynamic method call in the program is what makes the module carry constructor trampolines
class Echoer { public function say(...$a) { return implode('+', $a); } }
$meth = 'say';
echo (new Echoer)->$meth(...['p', 'q']), "\n";
