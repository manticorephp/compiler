<?php

class A { public function __toString(): string { return 'a'; } }
class B implements Stringable { public function __toString(): string { return 'b'; } }
class C {}
class D extends A {}
class X extends A { public function __toString(): string { return 'x'; } }
class Y extends X {}
function viaBase(A $a): string { return 'base:' . $a; }

function show(Stringable $s): string { return 'show:' . $s; }

var_dump(new A() instanceof Stringable, new B() instanceof Stringable, new C() instanceof Stringable, new D() instanceof Stringable);
var_dump(interface_exists('Stringable'), interface_exists('Stringable', false));
echo show(new A()), ' ', show(new B()), ' ', show(new D()), "\n";
echo (string)(new A()), "\n";
echo viaBase(new A()), ' ', viaBase(new X()), ' ', viaBase(new Y()), ' ', show(new Y()), "\n";
var_dump(in_array('Stringable', class_implements(new A()), true));
var_dump(new Exception('x') instanceof Stringable);

function make(): RuntimeException { return new RuntimeException('boom', 3, new LogicException('inner')); }
$s = (string)make();
foreach (explode("\n", $s) as $line) { if ($line !== '' && $line[0] !== '#') { echo preg_replace('/ in \S+:\d+/', ' in F:N', $line), "\n"; } }
echo preg_replace('/ in \S+:\d+/', ' in F:N', strtok((string)new Error(''), "\n")), "\n";

if (!interface_exists('Stringable')) {
    interface Stringable {}
}
echo "ok\n";
