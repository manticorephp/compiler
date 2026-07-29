<?php

class Node_
{
    public int $v = 0;
    public ?Node_ $next = null;
}

// The object is registered in the slot table BEFORE its properties are read,
// which is what makes a cycle terminate instead of recursing forever.
$c = new Node_();
$c->v = 1;
$c->next = $c;
$c2 = unserialize(serialize($c));
var_dump($c2->v, $c2->next === $c2);

$d = new Node_();
$e = new Node_();
$d->v = 1;
$e->v = 2;
$d->next = $e;
$e->next = $d;
$d2 = unserialize(serialize($d));
var_dump($d2->v, $d2->next->v, $d2->next->next === $d2);

// Sharing survives the round trip: one object, two slots pointing at it.
$a = new Node_();
$a->v = 9;
$arr = unserialize(serialize([$a, $a]));
var_dump($arr[0] === $arr[1], $arr[0]->v);

$mixed = unserialize(serialize([1, 2, $a, $a]));
var_dump($mixed[0], $mixed[2] === $mixed[3], $mixed[2]->v);

$named = unserialize(serialize(['x' => $a, 'y' => $a]));
var_dump($named['x'] === $named['y']);

// R: (php's reference marker) is accepted on input and read as a value copy.
$ref = unserialize('a:2:{i:0;O:5:"Node_":2:{s:1:"v";i:7;s:4:"next";N;}i:1;R:2;}');
var_dump($ref[0]->v, $ref[1]->v);
