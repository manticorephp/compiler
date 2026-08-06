<?php

namespace Acme;

final class Circle implements Shape
{
    public function __construct(private int $r)
    {
    }

    public function area(): int
    {
        return 3 * $this->r * $this->r;
    }

    public function name(): string
    {
        return 'circle';
    }
}
