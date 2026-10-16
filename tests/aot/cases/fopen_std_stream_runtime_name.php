<?php
// fopen() of a std stream name computed at run time answers the same stream the
// literal does, not false (VarDumper's `static::$defaultOutput = 'php://stdout'`).
$n = 'php://' . 'stdout';
$f = fopen($n, 'w');
var_dump(is_resource($f));
echo "a\n";
fwrite($f, "b\n");
echo "c\n";

class D
{
    public static $o = 'php://output';
    public $s;
    public function __construct() { $this->s = fopen(static::$o, 'w'); }
}
class E extends D
{
    public static $o = 'php://stdout';
}
var_dump(is_resource((new D())->s), is_resource((new E())->s));
var_dump(is_resource(fopen(strtoupper('php://stderr'), 'w')));
var_dump(get_resource_type(fopen(implode('', ['php://', 'stdin']), 'r')));
