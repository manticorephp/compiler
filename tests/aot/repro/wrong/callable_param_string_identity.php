<?php
// A string passed to a native `callable` parameter is wrapped in a Closure, so is_string() on it answers false.
// issue: #615
function kind(callable $c): string { return (is_string($c) ? "string" : (is_array($c) ? "array" : "object")) . ":" . $c("A"); }
class K { public static function lo(string $x): string { return strtolower($x); } }
echo kind("strtolower"), " ", kind(["K", "lo"]), " ", kind(fn($x) => $x . $x), "\n";
