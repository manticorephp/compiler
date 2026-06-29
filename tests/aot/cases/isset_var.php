<?php
$s = "x";
echo isset($s) ? "1" : "0";
unset($s);
echo ",", isset($s) ? "1" : "0";
