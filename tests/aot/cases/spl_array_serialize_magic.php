<?php

// ArrayIterator / ArrayObject carry php's __serialize/__unserialize pair, whose
// shape is [flags, storage, extraProps, iteratorClass]. symfony/var-exporter's
// Hydrator calls __unserialize directly to rebuild an SPL instance, and passes
// only THREE elements for an ArrayIterator -- so the tail has to be optional.

$a = new ArrayIterator(['x' => 1, 'y' => 2]);
var_dump($a->__serialize());

$b = new ArrayIterator();
$b->__unserialize([0, ['k' => 'v'], []]);
var_dump($b->getArrayCopy(), $b->getFlags(), count($b));

// The rebuilt instance must iterate, i.e. __unserialize rebuilt the key list.
foreach ($b as $k => $v) { echo $k, '=', $v, "\n"; }

$o = new ArrayObject([1, 2, 3]);
var_dump($o->__serialize());
var_dump($o->getIteratorClass(), $o->getFlags());

$c = new ArrayObject();
$c->__unserialize([2, ['z' => 9], [], 'ArrayIterator']);
var_dump($c->getArrayCopy(), $c->getFlags(), $c->getIteratorClass());

// A three-element payload for an ArrayObject too: iteratorClass falls back.
$d = new ArrayObject();
$d->__unserialize([0, ['q' => 8], []]);
var_dump($d->getArrayCopy(), $d->getIteratorClass());

$e = new ArrayIterator([5, 6]);
$e->setFlags(1);
var_dump($e->getFlags());
