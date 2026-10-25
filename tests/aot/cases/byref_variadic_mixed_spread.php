<?php
// A by-ref variadic fed by a plain argument AND a spread (`put($b, ...$rest)`) loses the writes to the spread part.
function put(&...$x) { foreach ($x as $i => &$v) { $v = "p$i"; } }
$b = 1; $rest = [2, 3];
put($b, ...$rest);
var_dump($b, $rest);
