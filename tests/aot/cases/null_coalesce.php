<?php
$a = 0;
$b = $a ?? 99;
$c = 0 ?? 7;
echo $b, ",", $c;
