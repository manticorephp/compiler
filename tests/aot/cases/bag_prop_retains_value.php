<?php

// A dynamic-property (bag) store must take the object's OWN reference on an
// rc-managed value. Both bag arms — the static name `$o->k = $v` and the
// runtime name `$o->$k = $v` — used to box and store without retaining, so the
// bag held a borrowed buffer: correct for as long as some local still named the
// value, garbage once the producing frame exited.

#[\AllowDynamicProperties]
class Bag
{
}

/** The string dies with this frame unless the bag co-owns it. */
function mkStatic(): \Bag
{
    $src = 'xxMissingyy';
    $o = new \Bag();
    $o->name = substr($src, 2, 7);
    return $o;
}

/** Same, through the runtime-name arm. */
function mkDynamic(string $key): \Bag
{
    $src = 'xxSecondyy';
    $o = new \Bag();
    $o->$key = substr($src, 2, 6);
    return $o;
}

/** An array value is rc-managed too. */
function mkArray(): \Bag
{
    $o = new \Bag();
    $o->rows = [1, 2, 3];
    return $o;
}

/** And an object. */
class Payload
{
    public int $v = 0;
}

function mkObject(): \Bag
{
    $p = new \Payload();
    $p->v = 42;
    $o = new \Bag();
    $o->inner = $p;
    return $o;
}

$a = mkStatic();
$b = mkDynamic('name');
$c = mkArray();
$d = mkObject();

// Churn the allocator: a freed buffer would be reused by now.
$junk = '';
$i = 0;
while ($i < 8) {
    $junk = $junk . (string)$i;
    $i = $i + 1;
}

var_dump($a->name);
var_dump($b->name);
var_dump($c->rows);
var_dump($d->inner->v);
