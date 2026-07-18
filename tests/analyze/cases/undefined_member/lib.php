<?php

class Box
{
    const SIZE = 10;

    public function open(): void {}
    public function width(int $w): void {}
    public static function make(): Box { return new Box(); }
}
