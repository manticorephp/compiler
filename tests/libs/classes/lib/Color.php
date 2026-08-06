<?php

namespace Acme;

enum Color: string
{
    case Red = 'red';
    case Green = 'green';

    public function loud(): string
    {
        return \strtoupper($this->value);
    }
}
