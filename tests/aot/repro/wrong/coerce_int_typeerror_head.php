<?php
// A TypeError for a mixed value bound to an int parameter lacks php's "f(): Argument #1 ($x)" head and "called in" tail.
// issue: #727
function f(int $x): int { return $x; }
function m(mixed $v): mixed { return $v; }
try { f(m(null)); } catch (TypeError $e) { $s = $e->getMessage(); echo explode(', called in', $s)[0], "\n", str_contains($s, ' on line 5') ? "line ok" : "no line", "\n"; }
try { f(m([1])); } catch (TypeError $e) { echo explode(', called in', $e->getMessage())[0], "\n"; }
