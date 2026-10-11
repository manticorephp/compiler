<?php
// PHP 8.4 lazy-object API on a program with no lazy objects: nothing is uninitialized, initializing returns the object.
final class Plain { public function __construct(public int $n = 3) {} }

$r = new ReflectionClass(Plain::class);
$o = new Plain(7);
var_dump($r->isUninitializedLazyObject($o));
var_dump($r->initializeLazyObject($o) === $o);
var_dump($r->markLazyObjectAsInitialized($o) === $o);
if ($o !== $instance = $r->initializeLazyObject($o)) {
    echo "different\n";
} else {
    echo get_class($instance), ' ', $instance->n, "\n";
}
