<?php
class A
{
    public int $x { get => 1; set { throw new Error('set hook ran: ' . $value); } }
}

function make(): A|false
{
    return new A();
}

$a = make();
try {
    $a->x = 5;
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$b = [new A()][0];
try {
    $b->x = 6;
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
