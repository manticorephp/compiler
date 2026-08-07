<?php
// The same erased sum crossing a different cell channel: a `mixed` property.
// Here it did not crash — the raw i64 was stored into the cell slot without
// being boxed at all, so the read back rendered the integer's bit pattern as
// a denormal double. Silent, and invisible to any int-only probe.
class Counter
{
    public mixed $v = 0;
    public mixed $w = 0;
}

$c = new Counter();
$c->v = $c->v + 70000;
$c->w = $c->w + 7;
var_dump($c->v, $c->w);

$c->v = $c->v + 1;
var_dump($c->v);

// A static property is the third erased channel; same shape, same store.
class Tally
{
    public static mixed $n = 0;
}
Tally::$n = Tally::$n + 353307;
var_dump(Tally::$n);
