<?php
// ReflectionClass::isInternal()/isUserDefined(): php's own classes (prelude /
// runtime library) vs the program's. symfony's @Symfony rules ask it.
interface MyI {}
class U implements MyI {}
final class UE extends Exception {}
$keep = [new SplFixedArray(1), new ArrayIterator([1])];
foreach (['U', 'MyI', 'UE', 'Exception', 'Countable', 'stdClass', 'SplFixedArray', 'Traversable', 'ArrayIterator'] as $c) {
    $r = new ReflectionClass($c);
    echo $c, ' ', var_export($r->isInternal(), true), ' ', var_export($r->isUserDefined(), true), "\n";
}
$o = new ReflectionObject(new UE('x'));
echo var_export($o->isInternal(), true), "\n";
