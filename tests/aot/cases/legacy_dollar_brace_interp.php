<?php
// Legacy "${name}" string interpolation is lexed as a plain string instead of interpolating the variable.
$n = "x";
$x = "v";
$a = ["k" => "w"];
$v = "n";
echo "${n}", "\n";
echo "a${n}b ${a["k"]}", "\n";
