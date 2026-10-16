<?php
// ReflectionClass::getDefaultProperties(): statics first, then instance defaults; typed-without-default is omitted.
class B { public $inherited = 'b'; protected int $typedNoDefault; public static $sb = 1; }
class C extends B {
    public $a = 1;
    public ?string $s = null;
    public int $n = 5;
    public array $arr = [1, 2];
    public $untyped;
    private bool $flag = false;
    public static $count = 3;
    public static int $typedStatic = 7;
    public int $uninit;
    public function __construct(public int $promoted = 0) {}
}
$d = (new ReflectionClass('C'))->getDefaultProperties();
ksort($d);
foreach ($d as $k => $v) { echo $k, '=', json_encode($v), "\n"; }
echo count((new ReflectionClass('stdClass'))->getDefaultProperties()), "\n";
