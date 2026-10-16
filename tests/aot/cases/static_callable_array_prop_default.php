<?php
// Static callable arrays spelled only in property / constant defaults.
class Caster { public static function castIt(string $s, int $f = 0): string { return "cast:" . $s . ":" . $f; } }
class BaseC { public static function inherited(string $s): string { return "inh:" . $s; } }
class ChildC extends BaseC {}
class Cloner {
    public const MAP = ['k' => ['Caster', 'castIt']];
    public static array $defaults = ['x' => ['Caster', 'castIt'], 'y' => ['ChildC', 'inherited']];
    public array $inst = ['z' => ['Caster', 'castIt']];
    public array $casters = [];
    public function __construct() { foreach (self::$defaults as $t => $cb) { $this->casters[$t][] = $cb; } }
    public function cast(string $t, string $v): string { $r = ""; foreach ($this->casters[$t] as $cb) { $r = $cb($v, 1); } return $r; }
}
$c = new Cloner();
echo $c->cast('x', 'a'), "\n";
echo $c->cast('y', 'b'), "\n";
$cb = Cloner::$defaults['x'];
echo call_user_func($cb, 'c', 2), "\n";
echo call_user_func_array($cb, ['d', 3]), "\n";
$f = \Closure::fromCallable($cb);
echo $f('e'), "\n";
echo is_callable($cb) ? "callable\n" : "not\n";
$m = Cloner::MAP['k'];
echo $m('f', 4), "\n";
foreach ($c->inst as $cb2) { echo $cb2('g', 5), "\n"; }
echo implode(",", array_map(Cloner::$defaults['x'], ['h', 'i'])), "\n";
