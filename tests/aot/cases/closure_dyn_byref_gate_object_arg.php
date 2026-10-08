<?php
// An object passed to a dynamic closure call stays an object when another closure in the module takes its first param by reference.
class Cmd { public function __construct(public string $name = 'c') {} }
class HelpCmd extends Cmd { public function __construct() { parent::__construct('help'); } }
class App {
    /** @var string[] */
    public array $seen = [];
    public function add(Cmd $c): ?Cmd { return null; }
    public function addCommand(callable|Cmd $c): ?Cmd { $this->seen[] = get_debug_type($c); return null; }
    /** @return Cmd[] */
    private function defaults(): array { return [new HelpCmd(), new Cmd('x')]; }
    public function init(bool $legacy): void {
        if ($legacy) {
            $adder = $this->add(...);
        } else {
            $adder = $this->addCommand(...);
        }
        foreach ($this->defaults() as $c) { $adder($c); }
    }
}
$bump = function (&$n) { $n++; };
$i = 1;
$bump($i);
$app = new App();
$app->init(false);
echo implode(',', $app->seen), "\n";
