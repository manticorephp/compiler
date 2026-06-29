<?php
$a = ["x" => 1, "y" => 2, "z" => 3];
foreach ($a as $k => &$v) { $v = $v * 100; }
echo $a["x"], ",", $a["y"], ",", $a["z"];
