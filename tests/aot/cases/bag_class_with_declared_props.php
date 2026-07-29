<?php

// An #[AllowDynamicProperties] class that ALSO declares properties puts its bag
// after them, so the bag offset is the class's — not stdClass's. Reading it at
// stdClass's offset loaded a declared property as an assoc pointer and
// SIGSEGV'd, which took var_dump, serialize and var_export down with it.
//
// Note the declared slots and the bag are composed by each printer: a bare
// `(array)$obj` here yields the BAG ONLY, which is not php's answer, so this
// case never asserts the cast directly.

#[\AllowDynamicProperties]
class Loose
{
    public int $declared = 7;
    public string $label = 'l';
}

#[\AllowDynamicProperties]
class BagOnly
{
}

class Deep extends Loose
{
}

// Each in its own scope: var_dump's object id is a fixed `#1` here, and php
// only agrees while it has no second live object to number.

function showLoose(): void
{
    $l = new Loose();
    $l->extra = 'dyn';
    $l->count = 2;
    var_dump($l);
    echo serialize($l), "\n";
    var_export($l);
    echo "\n";
}

function showBagOnly(): void
{
    $b = new BagOnly();
    $b->only = 1;
    var_dump($b);
}

function showDeep(): void
{
    $d = new Deep();
    $d->added = true;
    var_dump($d);
    echo serialize($d), "\n";
}

showLoose();
showBagOnly();
showDeep();
