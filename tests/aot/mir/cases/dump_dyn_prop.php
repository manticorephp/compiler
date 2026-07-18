<?php
class Bag {
    public int $a = 1;
    public int $b = 2;
}
function pick(Bag $bag, string $field): int {
    $bag->$field = 9;
    return $bag->$field;
}
echo pick(new Bag(), "a");
