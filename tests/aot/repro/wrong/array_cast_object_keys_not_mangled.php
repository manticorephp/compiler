<?php
// (array) $obj returns private and protected property keys unmangled instead of "\0Class\0name" / "\0*\0name"
// issue: #239
class A
{
    private $p = 1;
    protected $q = 2;
    public $r = 3;
}
class B extends A
{
    private $s = 4;
}
foreach ((array) new B() as $k => $v) {
    echo json_encode($k), '=', $v, "\n";
}
var_dump(array_key_exists("\0A\0p", (array) new A()));
