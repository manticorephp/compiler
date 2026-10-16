<?php
// array_replace() of an int-valued literal and an `(array)$object` throws "Argument must be of type int, string given".
// issue: #258
class Ex extends \RuntimeException { protected string $t = 'x'; }
function f(object $o): int { $a = (array) $o; return \count(array_replace(['z' => 1], $a)); }
echo f(new Ex('m')), "\n";
