<?php

// __debugInfo() REPLACES var_dump's property walk with the array it returns.
// The keys are ARRAY keys, so an int key prints bare.

class Temp
{
    private float $celsius = 0.0;

    public function __construct(float $c)
    {
        $this->celsius = $c;
    }

    /** @return array<string|int,mixed> */
    public function __debugInfo(): array
    {
        return [
            'celsius' => $this->celsius,
            'fahrenheit' => $this->celsius * 9.0 / 5.0 + 32.0,
            0 => 'positional',
        ];
    }
}

class Empty_
{
    public int $hidden = 7;

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return [];
    }
}

class Plain
{
    public int $a = 1;
    public string $b = 'two';
}

var_dump(new Temp(100.0));
var_dump(new Empty_());

// A class without __debugInfo keeps the declared-slot walk.
var_dump(new Plain());

// Inside an array — the indent has to keep working.
var_dump([new Temp(0.0)]);
