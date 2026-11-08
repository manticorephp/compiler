<?php
// A subclass that overrides a factory but reaches the parent's version via `parent::` from a
// DIFFERENT method: the parent body runs with `static` bound to the called subclass.
class Base {
    public static function make(): static { return new static; }
}
class Mid extends Base {
    public static function make(): static { return new static; }
    public static function viaParent(): static { return parent::make(); }
}
class Leaf extends Mid {}
echo get_class(Mid::viaParent()), ' ', get_class(Leaf::viaParent()), ' ', get_class(Leaf::make()), ' ', get_class(Base::make()), "\n";
