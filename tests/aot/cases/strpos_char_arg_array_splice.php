<?php
// A program that passes a string-offset char to strpos() and also calls array_splice() on the array segfaults.
$p = ["ab"];
echo strpos("ba", $p[0][1]), "\n";
array_splice($p, 0, 0, ["z"]);
