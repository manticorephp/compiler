<?php
class P {
    public string $name = "a";
    protected array $tr = ["x"];
    private ?array $t2 = null;
    private ?array $t3 = null;
    public function __construct() { $this->t2 = ["y", "z"]; }
    public function vars(): array { return get_object_vars($this); }
}
$o = new P;
$a = (array) $o;
echo json_encode(array_values($a)), "\n";
echo json_encode(array_values($o->vars())), "\n";
var_dump($a["\0P\0t3"]);
$b = array_replace(["\0*\0tr" => null], $a);
echo json_encode(array_values($b)), "\n";
for ($i = 0; $i < 200; $i++) { $o = new P; $c = array_replace([], (array) $o); }
echo count($c), "\n";
