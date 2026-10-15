<?php
// ReflectionClassConstant::getAttributes() exists and answers [] for a constant without attributes (symfony's ReflectionClassResource asks).
class K { const A = 1; const B = 2; }
foreach ((new ReflectionClass('K'))->getReflectionConstants() as $c) {
    echo $c->name, ':', count($c->getAttributes()), ':', count($c->getAttributes('Anything')), "\n";
}
