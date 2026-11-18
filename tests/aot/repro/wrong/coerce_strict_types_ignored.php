<?php
// Under declare(strict_types=1) a mixed numeric string bound to an int parameter is coerced instead of throwing TypeError.
// issue: #731
declare(strict_types=1);
function f(int $x): int { return $x; }
function m(mixed $v): mixed { return $v; }
try { var_dump(f(m("5"))); } catch (TypeError $e) { echo explode(', called in', $e->getMessage())[0], "\n"; }
try { var_dump(f(m(true))); } catch (TypeError $e) { echo explode(', called in', $e->getMessage())[0], "\n"; }
