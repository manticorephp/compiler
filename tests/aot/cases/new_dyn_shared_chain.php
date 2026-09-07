<?php

// `new $cls(...)` compares the name against every class in the module. The
// chain is one shared body per ARGUMENT SHAPE now, so two sites that construct
// with the same argument kinds must still each get their own answer — and a
// spread site, which expands the pack against each candidate's own parameter
// list, must keep working alongside them.

class A
{
    public function __construct() {}
    public function who(): string { return 'A'; }
}

class B
{
    public function __construct(public string $s = 'b') {}
    public function who(): string { return 'B:' . $this->s; }
}

class C
{
    public function __construct(public int $n, public string $s) {}
    public function who(): string { return 'C:' . (string)$this->n . $this->s; }
}

function mk0(string $c): object { return new $c(); }
function mk1(string $c, string $s): object { return new $c($s); }
function mk2(string $c, int $n, string $s): object { return new $c($n, $s); }
/** @param array<int, mixed> $a */
function mkSpread(string $c, array $a): object { return new $c(...$a); }

echo mk0('A')->who(), "\n";
echo mk0('B')->who(), "\n";
echo mk1('B', 'zz')->who(), "\n";
echo mk2('C', 7, 'q')->who(), "\n";
echo mkSpread('C', [3, 'x'])->who(), "\n";

// A second zero-argument site: same shape as mk0, so it shares mk0's body.
$cls = 'A';
$o = new $cls();
echo $o->who(), "\n";

// The result is a boxed cell here — get_class reads the tag, not the pointer.
var_dump(get_class(mk0('B')));

// A name no class matches is php's "Class not found"; we degrade to a null
// object, and get_class of it is the empty-name arm.
