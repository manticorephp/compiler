<?php
// ReflectionClassConstant::getAttributes() is missing (Call to undefined method); symfony's ReflectionClassResource calls it.
// issue: #244

#[Attribute]
class Tag { public function __construct(public string $v = '') {} }

class K
{
    #[Tag('x')]
    const A = 1;
    const B = 2;
}

foreach ((new ReflectionClass('K'))->getReflectionConstants() as $c) {
    echo $c->name, ':';
    foreach ($c->getAttributes() as $a) { echo ' ', $a->getName(), json_encode($a->getArguments()); }
    echo "\n";
}
