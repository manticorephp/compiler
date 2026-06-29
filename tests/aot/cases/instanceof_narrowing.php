<?php
class A { public function tag(): string { return "A"; } }
class B { public function tag(): string { return "B"; } }
function describe(object $x): string {
    if ($x instanceof B) { return "got " . $x->tag(); }
    if ($x instanceof A) { return "got " . $x->tag(); }
    return "unknown";
}
echo describe(new A()), ",", describe(new B());
