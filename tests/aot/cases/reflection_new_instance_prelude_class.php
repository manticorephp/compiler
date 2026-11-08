<?php
foreach (['ArrayObject', 'ArrayIterator', 'SplObjectStorage', 'stdClass', 'SplStack', 'DateTimeImmutable'] as $c) {
    $r = new ReflectionClass($c);
    try { $o = $c === 'DateTimeImmutable' ? $r->newInstanceArgs(['2020-01-02']) : ($c === 'ArrayObject' || $c === 'ArrayIterator' ? $r->newInstanceArgs([[1,2]]) : $r->newInstanceArgs([])); echo $c, ' ok ', get_class($o), "\n"; }
    catch (Throwable $e) { echo $c, ' ERR ', $e->getMessage(), "\n"; }
}
$o = (new ReflectionClass('ArrayObject'))->newInstanceArgs([[1,2,3]]); echo count($o), "\n";
$o = (new ReflectionClass('ArrayObject'))->newInstance(); echo count($o), "\n";
