<?php
function native_fn(): string { return "native"; }
// guard false (exists) -> polyfill dropped, native wins
if (!function_exists('native_fn')) {
    function native_fn(): string { return "polyfill"; }
}
echo native_fn(), "\n";
// guard true (absent) -> hoisted + callable
if (!function_exists('poly_added')) {
    function poly_added(): string { return "added"; }
}
echo poly_added(), "\n";
// else branch hoists when the then-guard is false
if (function_exists('never_ever_xyz')) {
    function unreachable_fn(): string { return "no"; }
} else {
    function from_else(): string { return "else"; }
}
echo from_else(), "\n";
