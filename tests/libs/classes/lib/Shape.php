<?php

namespace Acme;

interface Shape
{
    public const SIDES_UNKNOWN = -1;

    public function area(): int;
    public function name(): string;
}
