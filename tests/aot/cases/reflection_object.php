<?php

class Widget
{
    const TAG = 'w';
    public int $size = 4;
    protected string $label = 'box';

    public function area(): int { return $this->size * $this->size; }
}

$w = new Widget();
$ro = new ReflectionObject($w);

echo 'class=', $ro->getName(), "\n";
echo 'isInstantiable=', $ro->isInstantiable() ? '1' : '0', "\n";
echo 'hasMethod area=', $ro->hasMethod('area') ? '1' : '0', "\n";
echo 'size=', $ro->getProperty('size')->getValue($w), "\n";
$ro->getProperty('size')->setValue($w, 10);
echo 'size2=', $ro->getProperty('size')->getValue($w), "\n";
echo 'area=', $ro->getMethod('area')->invoke($w), "\n";

$props = [];
foreach ($ro->getProperties() as $p) { $props[] = $p->getName(); }
sort($props);
echo 'props=', implode(',', $props), "\n";
