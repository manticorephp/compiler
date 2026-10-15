<?php
class EC { public static function ce(string $s): string { return "ce:" . $s; } public static function Up(string $s): string { return strtoupper($s); } }
function g(mixed $cb) { return $cb("v"); }
function mk(string $m) { return "EC::" . $m; }
echo g(mk("ce")), "\n";
echo g(mk("UP")), "\n";
$m = "ce";
echo g("EC::{$m}"), "\n";
