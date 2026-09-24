<?php

class A
{
    private array $map = ['x' => 'twice', 'y' => 'Label'];

    public static function twice(int $v): int { return $v * 2; }
    public static function label(int $v): string { return static::class . ':' . $v; }
    private static function hidden(int $v): int { return $v + 100; }

    public function viaMap(string $k, int $v): int|string
    {
        return self::{$this->map[$k]}($v);
    }

    public static function viaVar(string $n, int $v): int|string
    {
        return static::$n($v);
    }

    public static function viaSelfVar(string $n, int $v): int|string
    {
        return self::$n($v);
    }
}

class B extends A
{
    public static function extra(int $v): int { return $v - 1; }
}

$a = new A();
var_dump($a->viaMap('x', 21));
var_dump($a->viaMap('y', 3));
var_dump(B::viaVar('label', 4));
var_dump(B::viaVar('EXTRA', 10));
var_dump(A::viaSelfVar('hidden', 1));
$n = 'twice';
var_dump(B::$n(5));
var_dump(A::{'tw' . 'ice'}(6));
try {
    A::viaVar('nope', 1);
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
