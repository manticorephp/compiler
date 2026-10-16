<?php
class Box { public function __construct(public string $s) {} public function __destruct() { echo "free {$this->s}\n"; } }
$inc = function (&$v, $k = 0) { $v = $v . "z"; };
$s = "abc"; $inc($s); $inc($s); $inc($s);
echo $s, "\n";
$arr = ["a", "b", "c"];
foreach ($arr as $k => $_) { $inc($arr[$k]); $inc($arr[$k]); }
echo implode(",", $arr), "\n";
$swap = function (&$v) { $v = new Box("new"); };
$o = new Box("old");
$swap($o);
echo "after swap\n";
$o2 = $o;
$swap($o);
echo "after second swap\n";
unset($o, $o2);
$ar = function (array &$a) { $a = array_merge($a, [count($a)]); };
$list = [];
for ($i = 0; $i < 4; $i++) { $ar($list); }
echo implode(",", $list), "\n";
$w = ["x" => "1", "y" => "22"];
array_walk($w, function (&$v, $k) { $v = $k . $v; });
echo json_encode($w), "\n";
$n = 5; $dbl = function (&$v) { $v = $v * 2; }; $dbl($n); $dbl($n);
echo $n, "\n";
