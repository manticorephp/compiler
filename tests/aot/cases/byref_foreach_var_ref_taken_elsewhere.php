<?php
// A by-ref foreach whose value variable is reference-taken elsewhere in the function writes the box address (or a dangling value) back into the element.
function t(): void { $q = [1, 2]; foreach ($q as &$v) { $v *= 2; } unset($v); var_dump($q[0]);
$s = ["a"]; foreach ($s as &$v) { $v .= "b"; } unset($v); var_dump($s[0]); $keep = [&$v]; }
t();
