<?php
// Three emitter bugs one erased method call walked into:
// - the dispatch fallback was the FIRST holder of the name, so `$o->next(3)` with an Iterator's
//   `next()` declared first dropped the argument and `A::next(?int)` read its default;
// - a `void` arm of that dispatch answered int(0) instead of null;
// - `$x->y->prop ?? ''` kept a null `?string` / `?Obj` property (a null pointer) instead of the
//   default, so the result was a zero-length value `=== ''` rejected.

class Z { public function next(): void { echo "Z\n"; } }
class A { public function next(?int $offset = null): int { return $offset === null ? -7 : $offset * 2; } }
class B extends A {}
foreach ([new A, new Z, new B] as $o) { var_dump($o->next(3)); }
foreach ([new A, new Z] as $o) { var_dump($o->next()); }

final class T { public ?string $class = null; public ?T $next = null; }
final class N { public function __construct(public T $type) {} }
function f(N $n): void
{
    $s = $n->type->class ?? "";
    $t = $n->type->next ?? "none";
    var_dump($s === "", strlen($s), is_string($t) ? $t : get_class($t));
}
f(new N(new T));
$t = new T; $t->class = "A"; $t->next = new T; f(new N($t));
