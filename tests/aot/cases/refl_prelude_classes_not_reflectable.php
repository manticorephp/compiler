<?php
// ReflectionClass of ArrayObject, SplObjectStorage and DateTimeImmutable throws "does not exist", and Exception lists internal properties instead of php's
foreach (['ArrayObject', 'SplObjectStorage', 'DateTimeImmutable'] as $c) {
    echo $c, ': ', (new ReflectionClass($c))->getName(), "\n";
}
$names = array_map(fn($p) => $p->getName(), (new ReflectionClass('Exception'))->getProperties());
echo implode(',', $names), "\n";
