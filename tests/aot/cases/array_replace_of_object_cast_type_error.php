<?php
class Box { public $s = "a"; public int $n = 2; protected $p = [1]; }
function cast(object $o): array { return (array) $o; }
$a = array_replace(["x" => 1], cast(new Box));
echo count($a), "\n";
echo $a["x"], $a["s"], $a["n"], "\n";
