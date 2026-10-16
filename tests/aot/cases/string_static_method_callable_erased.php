<?php
// A 'Class::method' string callable invoked through an erased slot returns 0 instead of calling the static method.
class EC { public static function ce(string $s): string { return "ce:" . $s; } }
function g(mixed $cb) { return $cb("v"); }
echo g("EC::ce"), "\n";
echo call_user_func("EC::ce", "w"), "\n";
