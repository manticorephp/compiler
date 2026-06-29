<?php
class A {
    public function whoAmI(): string { return static::class; }
    public static function make(): static { return new static(); }
    public static function who(): string { return static::class; }
}
class B extends A {}
class C extends B {}
echo A::who(), "\n";
echo B::who(), "\n";
echo (new B())->whoAmI(), "\n";
echo (new C())->whoAmI(), "\n";
echo C::make()->whoAmI(), "\n";
$objs = [new A(), new B(), new C()];
foreach ($objs as $o) { echo $o->whoAmI(); }
echo "\n";
var_dump(B::make() instanceof B);
