<?php
// A readonly property initialised through a dynamic-name store can be initialised again by the constructor (Zend: Error "Cannot modify readonly property Ro::$x")
// issue: #471
class Ro { public function __construct(public readonly string $x = 'init') {} }
$o = (new ReflectionClass('Ro'))->newInstanceWithoutConstructor();
$set = \Closure::bind(function (string $k, string $v): void { $this->$k = $v; }, $o, 'Ro');
$set('x', 'a');
try { $o->__construct(); echo $o->x, "\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
