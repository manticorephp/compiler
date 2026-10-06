<?php
// strlen() of an int held in a cell (a generator's auto key) reads the int as a string pointer and crashes
// issue: #102
function g(): \Generator { yield 'a' => 1; yield 2; yield 3; }
foreach (g() as $k => $v) { echo strlen($k), "\n"; }
