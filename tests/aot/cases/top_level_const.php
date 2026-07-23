<?php
// Top-level `const NAME = <const-expr>;` (outside a class) — lowered to define(),
// resolved at compile time like php.
const WORKERS = 8;
const GREETING = "hi";
const MAX = 10 * 6 + 4;
echo WORKERS, " ", GREETING, " ", MAX, "\n";
function scaled(): int { return WORKERS * 2; }
echo scaled(), "\n";
