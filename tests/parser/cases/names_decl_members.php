<?php
namespace App;

enum Suit { case Hearts; }

class Box
{
    public static int $n = 0;
    public function __construct(private int $q, public ?string $label = null, readonly int $z = 0) {}
    public function run(Box $o, ...$rest): void
    {
        global $g;
        static $calls = 0;
        $o->q;
        $o?->label;
        $o->run($o);
        self::$n;
        static::peek();
        Suit::Hearts;
        Box::class;
        $o::VERSION;
        try {
            echo "{$o->label}";
        } catch (\Exception $e) {
        }
        echo "v $o->label z";
        $f = fn(int $x) => $x;
    }
}
