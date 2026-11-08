<?php
trait Defs {
    public const DEFAULTS = ['a' => 1, 'b' => 2];
}
class A {
    use Defs;
    /** @var array<string, mixed> */
    private static array $empty = self::DEFAULTS + ['c' => null];
    public static function get(): array { return self::$empty; }
}
echo json_encode(A::get()), "\n";
