<?php

function add(int $a, int $b): int
{
    return $a + $b;
}

class Counter
{
    private int $n = 0;

    public function bump(): int
    {
        return ++$this->n;
    }
}

$c = new Counter();
for ($i = 0; $i < 3; $i++) {
    echo add($c->bump(), $i), "\n";
}
