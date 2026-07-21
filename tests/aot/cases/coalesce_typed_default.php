<?php

// `$present ?? $default` where the present value is a raw int/float/bool and the
// default carries a DIFFERENT repr (a string). The node types a cell; the
// present-value short-circuit must box it, or var_dump reads the raw scalar as
// the wrong type.

class Bag {
    public int $n = 3;
    public float $f = 2.5;
    public bool $b = true;
    public ?string $s = null;
}
$o = new Bag();
var_dump($o->n ?? "od");   // int(3)
var_dump($o->f ?? "od");   // float(2.5)
var_dump($o->b ?? "od");   // bool(true)
var_dump($o->s ?? "od");   // od  (null prop -> default)
$x = $o->n ?? 99;          // same repr -> int(3)
var_dump($x);

// a plain int local ?? a string default
$i = 7;
var_dump($i ?? "str");     // int(7)
$fl = 1.5;
var_dump($fl ?? "str");    // float(1.5)

// echo (not just var_dump) of a typed-default coalesce
echo ($o->n ?? "od"), "\n";  // 3
