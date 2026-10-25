<?php
// `readonly` is accepted as a function name
function readonly($a, $b = true) { return $a . "ro"; }
echo readonly("x"), "\n";
