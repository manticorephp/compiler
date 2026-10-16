<?php
// ReflectionProperty PHP 8.4 queries a plain property answers without error (asymmetric visibility is parsed, not recorded).
class Box
{
    public int $a = 1;
    protected string $b = 'x';
    private array $c = [];
}

foreach ((new ReflectionClass(Box::class))->getProperties() as $p) {
    echo $p->getName(), ' ', var_export($p->isProtectedSet(), true), ' ', var_export($p->isPrivateSet(), true),
        ' ', var_export($p->isVirtual(), true), ' ', count($p->getHooks()), "\n";
}
