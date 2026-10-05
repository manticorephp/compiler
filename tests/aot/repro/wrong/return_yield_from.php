<?php
// `return yield from` emits invalid IR
// issue: #60
function a(): \Generator { yield 1; return 5; }
function b(): \Generator { return yield from a(); }
$g = b();
foreach ($g as $v) { echo $v, "\n"; }
echo $g->getReturn(), "\n";
