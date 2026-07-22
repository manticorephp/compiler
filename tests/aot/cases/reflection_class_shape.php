<?php

interface Shape { public function area(): float; }
interface Named {}

class Circle implements Shape, Named
{
    public function area(): float { return 3.14; }
    public function label(): string { return 'c'; }
    public function self_ret(): self { return $this; }
    public function noret() {}
}

$rc = new ReflectionClass('Circle');

$ifaces = $rc->getInterfaceNames();
sort($ifaces);
echo 'ifaces=', implode(',', $ifaces), "\n";
echo 'impl Shape=', $rc->implementsInterface('Shape') ? '1' : '0', "\n";
echo 'impl Named=', $rc->implementsInterface('Named') ? '1' : '0', "\n";

$area = $rc->getMethod('area');
echo 'area ret=', $area->getReturnType()->getName(),
     ' null=', $area->getReturnType()->allowsNull() ? '1' : '0', "\n";
$self = $rc->getMethod('self_ret');
echo 'self ret=', $self->getReturnType()->getName(), "\n";
$noret = $rc->getMethod('noret');
echo 'noret has=', $noret->hasReturnType() ? '1' : '0', "\n";
