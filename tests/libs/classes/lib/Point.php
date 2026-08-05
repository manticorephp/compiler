<?php

namespace Acme;

class Point
{
    public const NAME = 'point';
    /** Folds to 'point!' at export — the dependent evaluates nothing. */
    public const LABEL = self::NAME . '!';
    public const ORIGIN = [0, 0];

    public static int $count = 0;

    public function __construct(public int $x = 0, public int $y = 0)
    {
        self::$count = self::$count + 1;
    }

    public function sum(): int
    {
        return $this->x + $this->y;
    }

    public function label(): string
    {
        return self::LABEL . ':' . $this->sum();
    }

    public static function made(): int
    {
        return self::$count;
    }
}
