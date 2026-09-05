<?php
// `unset($o->prop)` lowers to a null store, so the slot releases what it held
// instead of keeping the pointer and vetoing the property's drop program-wide.
class UpBox {
    public ?string $s = 'x';
    /** @var string[] */
    public array $a = [];
}

$b = new UpBox();
$b->a[] = 'one';
$b->a[] = 'two';
var_dump(isset($b->s));
var_dump(count($b->a));
unset($b->s);
var_dump(isset($b->s));
$b->s = 'y';
var_dump($b->s);
unset($b->a);
var_dump(isset($b->a));
$b->a = ['three'];
var_dump(count($b->a));
var_dump($b->a[0]);
