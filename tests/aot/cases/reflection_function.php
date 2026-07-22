<?php

function greet(string $name, string $greeting = 'Hello'): string
{
    return $greeting . ', ' . $name . '!';
}
function add(int $a, int $b): int { return $a + $b; }
function noop(): void {}

$rf = new ReflectionFunction('greet');
echo 'name=', $rf->getName(), "\n";
echo 'nparams=', $rf->getNumberOfParameters(), "\n";
echo 'required=', $rf->getNumberOfRequiredParameters(), "\n";
echo 'ret=', $rf->getReturnType()->getName(), "\n";
foreach ($rf->getParameters() as $p) {
    echo '  param ', $p->getName(), ' type=', $p->getType()->getName(),
         ' opt=', $p->isOptional() ? '1' : '0', "\n";
}
echo 'invoke=', $rf->invoke('World'), "\n";
echo 'invoke2=', $rf->invoke('X', 'Hi'), "\n";

$radd = new ReflectionFunction('add');
echo 'add=', $radd->invokeArgs([3, 4]), "\n";

$rnoop = new ReflectionFunction('noop');
echo 'noop hasret=', $rnoop->hasReturnType() ? '1' : '0',
     ' ret=', $rnoop->getReturnType()->getName(), "\n";
