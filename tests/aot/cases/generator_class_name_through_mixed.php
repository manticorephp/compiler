<?php
// get_debug_type() and ::class of a Generator held in a mixed slot answer an empty name.
function g(): \Generator { yield 1; }
function t(mixed $m) { return get_debug_type($m) . '|' . gettype($m) . '|' . (is_object($m) ? $m::class : '-'); }
echo t(g()), "\n";
