<?php

class Plain
{
    public int $a = 1;
    public string $s = 'txt';
}

class Vis
{
    public int $a = 1;
    protected int $b = 2;
    private int $c = 3;
}

class Sub extends Vis
{
    public int $d = 4;
    private int $e = 5;
}

class Empty_
{
}

class Holder
{
    public array $list = [1, 2];
    public ?Plain $inner = null;
}

echo serialize(new Plain()), "\n";
echo serialize(new Vis()), "\n";
echo serialize(new Sub()), "\n";
echo serialize(new Empty_()), "\n";
echo serialize(new stdClass()), "\n";

$h = new Holder();
$h->inner = new Plain();
echo serialize($h), "\n";

echo serialize([new Plain(), 'k' => new Vis()]), "\n";
echo serialize(['deep' => ['obj' => new Plain()]]), "\n";
