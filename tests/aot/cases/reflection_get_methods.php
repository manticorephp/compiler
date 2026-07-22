<?php

class Base
{
    public function greet(): string { return 'hi'; }
}

class Widget extends Base
{
    public int $w = 3;

    public function render(): string { return 'W'; }
    protected static function make(): int { return 1; }
    final public function seal(): void {}
}

$rc = new ReflectionClass('Widget');

$names = [];
foreach ($rc->getMethods() as $m) {
    $names[] = $m->getName();
}
sort($names);
echo 'methods: ', implode(',', $names), "\n";

$render = $rc->getMethod('render');
echo 'render mods=', $render->getModifiers(),
     ' decl=', $render->getDeclaringClass()->getName(), "\n";

$make = $rc->getMethod('make');
echo 'make static=', $make->isStatic() ? '1' : '0', ' mods=', $make->getModifiers(), "\n";

$seal = $rc->getMethod('seal');
echo 'seal final=', $seal->isFinal() ? '1' : '0', ' mods=', $seal->getModifiers(), "\n";

$props = [];
foreach ($rc->getProperties() as $p) {
    $props[] = $p->getName();
}
sort($props);
echo 'props: ', implode(',', $props), "\n";
