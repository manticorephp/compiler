<?php
// Constant named OBJECT is lexed as the object type keyword in a default value
define("OBJECT", "OBJECT");
function g($o = OBJECT, $y = 0) { return $o . $y; }
echo g(), "\n";
echo OBJECT, "\n";
