<?php
$a = ["x" => 1, "y" => 2, "z" => 3];
echo count($a);
unset($a["y"]);
echo ",", count($a), ",", isset($a["y"]) ? "1" : "0";
