<?php
function off(): float { return floor(27.5); }
$f = off();
var_dump(str_repeat('-', $f), str_repeat('=', max(0, $f)), substr('abcdef', max(0, 1.0), max(0, 2.0)));
$w = 28; $c = 0.0; $e = $w - $c - 1;
var_dump(strlen(str_repeat('ab', max(0, $e))));
