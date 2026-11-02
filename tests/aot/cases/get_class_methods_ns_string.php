<?php
namespace Tests;

final class FooTest
{
    public function testOne(): void {}
    public function testTwo(): void {}
}

$class = FooTest::class;
var_dump(get_class_methods($class));
var_dump(get_class_methods(new FooTest()));
var_dump(get_class_methods('Tests\FooTest'));
