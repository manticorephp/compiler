<?php

// #[AllowDynamicProperties] is a compiler MARKER (it gives the class a property
// bag). Declaring the class must not disturb that — and the attribute must now
// also be reflectable like any other.

#[AllowDynamicProperties]
class Bag
{
    public int $known = 1;
}

$b = new Bag();
$b->extra = 'dyn';
$b->n = 7;
echo $b->known, " ", $b->extra, " ", $b->n, "\n";

$r = new ReflectionClass('Bag');
foreach ($r->getAttributes() as $a) {
    echo $a->getName(), "\n";
    $inst = $a->newInstance();
    echo get_class($inst), "\n";
}
