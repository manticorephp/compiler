<?php
// A variadic closure invoked through an erased callee gets its pack even when a prelude
// method (CallbackFilterIterator::accept, three arguments) was the first site of that shape.
$it = new CallbackFilterIterator(new ArrayIterator(['a' => 1, 'b' => 2]), static function (...$args) {
    return $args[0] > 1;
});
echo implode(',', iterator_to_array($it)), "\n";

function callAll(iterable $ls, object $e, string $name): void
{
    foreach ($ls as $l) { $l($e, $name, 7); }
}
$hits = [];
$ls = [
    static function (...$args) use (&$hits) { $hits[] = count($args) . ' ' . get_class($args[0]) . ' ' . $args[1] . ' ' . $args[2]; },
    static function ($e, ...$rest) use (&$hits) { $hits[] = get_class($e) . ' + ' . implode(',', $rest); },
    static function ($e, $n, $x = 'd', $y = 'dflt') use (&$hits) { $hits[] = "fixed $n $x $y"; },
];
callAll($ls, new ArrayObject(), 'ev');
echo implode("\n", $hits), "\n";
