<?php
// unset() of a local that a callee stored by reference does not disconnect it: later writes through the stored reference revive the variable
// issue: #340
class S {
    /** @var array<int,mixed> */
    public array $r = [];
    function bc(mixed &$v): void { $this->r[0] = &$v; }
    function set(): void { foreach ($this->r as &$r) { $r = 5; } unset($r); }
}
function f() { $s = new S; $s->bc($a); unset($a); $s->set(); var_dump(isset($a)); }
f();
