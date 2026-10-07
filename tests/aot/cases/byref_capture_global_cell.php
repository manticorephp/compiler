<?php
function g() { global $gx; $f = function () use (&$gx) { $gx = $gx . "!"; }; $f(); $f(); }
$gx = "a";
g();
echo $gx, "\n";
$GLOBALS['gx'] = "z";
g();
echo $gx, "\n";
