<?php

interface HasApi { const API = 3; }

class Config implements HasApi
{
    const VERSION = '2.1';
    const MAX = 50;
    protected const SCALE = 2;
}

$rcc = new ReflectionClassConstant('Config', 'VERSION');
echo 'name=', $rcc->getName(), ' value=', $rcc->getValue(),
     ' decl=', $rcc->getDeclaringClass()->getName(),
     ' pub=', $rcc->isPublic() ? '1' : '0', "\n";

$rc = new ReflectionClass('Config');
$names = [];
foreach ($rc->getReflectionConstants() as $c) {
    $names[] = $c->getName() . '=' . $c->getValue();
}
sort($names);
echo 'consts: ', implode(',', $names), "\n";
echo 'getRC MAX=', $rc->getReflectionConstant('MAX')->getValue(), "\n";
echo 'getRC API=', $rc->getReflectionConstant('API')->getValue(), "\n";
var_dump($rc->getReflectionConstant('NOPE'));
