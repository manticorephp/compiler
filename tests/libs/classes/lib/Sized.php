<?php

namespace Acme;

abstract class Sized
{
    public int $size = 0;

    public function grow(int $by): int
    {
        $this->size = $this->size + $by;
        return $this->size;
    }

    abstract public function unit(): string;
}

final class Boxed extends Sized
{
    public string $tag = 'box';

    public function unit(): string
    {
        return $this->tag . '/' . (string)$this->size;
    }
}
