<?php
// Declarations php makes when control reaches them: under a runtime if, inside a
// function body (guarded and not), and picked by a compile-time version guard.
$multi = count($argv) > 5;
if (!$multi) {
    function only_single(int $x): int { return $x * 2; }
}
echo only_single(21), "\n";

function outer(string $s): string {
    if (!function_exists('inner_lower')) {
        function inner_lower(array $m): string { return strtolower($m[0]); }
    }
    return preg_replace_callback('/%[A-F0-9]{2}/', 'inner_lower', $s);
}
echo outer('a%2Fb%3A'), "\n";

function make_helpers(): void {
    function helper_one(): string { return 'one'; }
    class NestedBox { public function __construct(public int $v) {} }
}
make_helpers();
echo helper_one(), ' ', (new NestedBox(7))->v, "\n";

if (version_compare(PHP_VERSION, '8.1.6', '>=')) :
    function pick_version(): string { return 'new'; }
else :
    function pick_version(): string { return 'old'; }
endif;
echo pick_version(), "\n";

if (version_compare(PHP_VERSION, '99.0', '>=')) {
    class Picked { public static function n(): string { return 'future'; } }
} else {
    class Picked { public static function n(): string { return 'present'; } }
}
echo Picked::n(), "\n";
