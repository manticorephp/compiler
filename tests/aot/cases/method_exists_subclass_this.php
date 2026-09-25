<?php
// method_exists($this, 'm') in a parent asks about the RUNTIME class: a method
// only a subclass declares exists for that subclass's instances.

abstract class Base
{
    public function run(): string
    {
        if (!method_exists($this, 'hook')) {
            return 'none';
        }
        return $this->hook(2);
    }
}

final class WithHook extends Base
{
    public function hook(int $n): string { return "hook$n"; }
}

final class Plain extends Base {}

interface Shape {}
final class Sq implements Shape { public function area(): int { return 4; } }
final class Pt implements Shape {}

function hasArea(Shape $s): bool { return method_exists($s, 'area'); }

echo (new WithHook())->run(), ' ', (new Plain())->run(), "\n";
var_dump(hasArea(new Sq()), hasArea(new Pt()), method_exists('Base', 'hook'), method_exists('WithHook', 'hook'));
