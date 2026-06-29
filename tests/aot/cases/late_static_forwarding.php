<?php
class Base {
    public function create(): static { return new static(); }
    public function label(): string { return "I am " . static::class; }
    public static function tmpl(): string { return static::tag(); }
    public static function tag(): string { return "base"; }
}
class Mid extends Base {
    public function label(): string { return "Mid:" . parent::label(); }
    public static function tag(): string { return "mid"; }
}
class Leaf extends Mid {}
echo (new Leaf())->label(), "\n";
echo (new Leaf())->create()->label(), "\n";
echo Leaf::tmpl(), "\n";
echo Base::tmpl(), "\n";
