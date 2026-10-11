<?php
// `self::$counter++` used as an expression yields the incremented value instead of the old one
// issue: #192
class C
{
    private static int $counter = 0;

    public function next(): int
    {
        return self::$counter++;
    }

    public function label(string $name): string
    {
        return $name . '_' . self::$counter++;
    }
}

$c = new C();
echo $c->next(), $c->next(), ' ', $c->label('a'), ' ', $c->label('a'), "\n";
