<?php
// A mixed null or array bound to a float parameter is read as 0.0/1.0 instead of throwing TypeError.
function g(float $f): float { return $f; }
function m(mixed $v): mixed { return $v; }
try { var_dump(g(m(null))); } catch (TypeError $e) { echo explode(', called in', $e->getMessage())[0], "\n"; }
try { var_dump(g(m([1]))); } catch (TypeError $e) { echo explode(', called in', $e->getMessage())[0], "\n"; }
try { var_dump(g(m("abc"))); } catch (TypeError $e) { echo explode(', called in', $e->getMessage())[0], "\n"; }
