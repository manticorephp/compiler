<?php
// `Class::$name()` lowers to a VALUE block (name evaluation, a guard, a ternary over the
// candidates). The block released its last statement as a discarded result, so the object the
// call returned was freed before the assignment stored it (get_class / var_dump read freed memory).

class A
{
    public static function mk(): ?A { return new B(); }
    public static function mk2(): A { return new A(); }
    public function name(): string { return static::class; }
}
class B extends A {}

foreach (["mk", "mk2", "MK"] as $m) {
    $o = A::$m();
    echo get_class($o), " ", $o->name(), "\n";
}
A::$m();
echo count([A::$m(), A::{"mk2"}()]), "\n";
try { A::{"nope"}(); } catch (Error $e) { echo $e->getMessage(), "\n"; }
