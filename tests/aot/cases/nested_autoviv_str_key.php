<?php
// string-keyed 2-level auto-viv (was garbage float)
$a = [];
$a["outer"]["inner"] = true;
$a["outer"]["second"] = 5;
$a["x"]["y"] = "hi";
var_dump($a);
