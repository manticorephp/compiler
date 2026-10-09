<?php
// Adding an int to an empty string held in a mixed value returns the int instead of throwing TypeError.
// issue: #147
function g(mixed $a, mixed $b): mixed { return $a + $b; }
try { var_dump(g("", 1)); } catch (TypeError $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
