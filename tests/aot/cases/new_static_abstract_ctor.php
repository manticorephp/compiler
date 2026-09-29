<?php
// `new static` in an abstract class whose constructor is itself abstract: the
// unspecialised copy called a constructor body that does not exist and the
// program failed to assemble (symfony/string AbstractString).
abstract class A {
    abstract public function __construct(string $s = '');
    public static function mk(string $s): static { return new static($s); }
    public function again(): static { return new static('again'); }
}
final class B extends A { public function __construct(public string $s = '') { echo "B {$this->s}\n"; } }
final class C extends A { public function __construct(public string $s = '') { echo "C {$this->s}\n"; } }
$b = B::mk('x'); $c = C::mk('y'); $b->again(); $c->again();
function make(string $cls): A { return new $cls('dyn'); }
make('B'); make('C');
