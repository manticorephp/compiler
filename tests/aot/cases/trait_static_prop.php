<?php
// A trait's STATIC properties were never registered: the slot loop walked the
// class's OWN declarations only, so `self::$traitStatic` had no registration at
// all and lowering refused the whole expression ("unsupported expression kind
// StaticAccess"). It hard-blocked tier 2 — symfony/cache's AbstractAdapter
// keeps its closures in statics inherited from AbstractAdapterTrait.
//
// php gives every USING CLASS its own slot: a trait is a compile-time copy, not
// shared storage. Two classes using one trait therefore get two counters.

trait Counter
{
    private static int $count = 0;
    protected static string $label = 'none';

    public static function bump(): int
    {
        self::$count = self::$count + 1;
        return self::$count;
    }

    public static function label(): string
    {
        return static::$label;
    }
}

class A
{
    use Counter;
}

class B
{
    use Counter;

    public static function setLabel(string $s): void
    {
        self::$label = $s;
    }
}

// Each using class has its OWN slot.
echo A::bump(), A::bump(), A::bump(), "\n";
echo B::bump(), "\n";
echo A::bump(), "\n";

// And its own copy of every other static the trait brought.
echo A::label(), "\n";
B::setLabel('bee');
echo B::label(), "\n";
echo A::label(), "\n";

// NOT tested here on purpose: a class REDECLARING a trait's static property.
// php rejects that at composition time ("define the same property … the
// definition differs and is considered incompatible") unless the declarations
// match exactly, so there is no observable "class wins" behaviour to pin.

// A trait static reached through an instance method, and written through it.
trait Registry
{
    private static array $items = [];

    public function add(string $k): int
    {
        self::$items[] = $k;
        return count(self::$items);
    }

    public function all(): string
    {
        return implode(',', self::$items);
    }
}

class Box
{
    use Registry;
}

$b1 = new Box();
$b2 = new Box();
echo $b1->add('x'), "\n";
echo $b2->add('y'), "\n";
echo $b1->all(), "\n";
