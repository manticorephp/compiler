<?php
// `(float)($mixed ?? 0.0)` over a float held in a mixed element converts its bit pattern as an int
function f(mixed ...$v): float { return (float)($v[0] ?? 0.0); }
var_dump(f(0.1), f(-2.5), f(3));
