<?php
// ReflectionMethod::invokeArgs cannot bind a by-reference parameter through a [&$a] element.
class Sum
{
    public function push(array &$into, int $v): void { $into[] = $v; }
}
$a = [];
(new ReflectionMethod(Sum::class, 'push'))->invokeArgs(new Sum(), [&$a, 5]);
echo count($a), "\n";
