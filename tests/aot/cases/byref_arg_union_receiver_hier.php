<?php
// By-ref arguments on a Class|false receiver whose method has a parent declaration and an override
class P {
    /** @var array<int,mixed> */
    public array $r = [];
    function bc(int $c, mixed &$v): void { $this->r[$c] = &$v; }
    function set(mixed $x): void { foreach ($this->r as &$r) { $r = $x; } unset($r); }
}
class C extends P {
    function bc(int $c, mixed &$v): void { $this->r[$c] = &$v; }
}
function mk(bool $child): P|false { return $child ? new C : new P; }
function run(bool $child): void {
    $s = mk($child);
    $s->bc(1, $out);
    $v = 1;
    $s->bc(2, $v);
    $v = 2;
    $s->set('x');
    var_dump($out, $v);
}
run(false);
run(true);
