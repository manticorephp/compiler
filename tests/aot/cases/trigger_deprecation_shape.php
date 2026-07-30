<?php

// symfony/deprecation-contracts verbatim: a global function hoisted out of its
// own function_exists guard, wrapping `@trigger_error(..., E_USER_DEPRECATED)`.
// Nothing must print until a handler asks for it.

if (!function_exists('trigger_deprecation')) {
    function trigger_deprecation(string $package, string $version, string $message, mixed ...$args): void
    {
        @trigger_error(($package || $version ? "Since $package $version: " : '')
            . ($args ? vsprintf($message, $args) : $message), \E_USER_DEPRECATED);
    }
}

echo "before\n";
trigger_deprecation("acme/lib", "1.2", "The %s method is deprecated.", "foo");
echo "after silent deprecation\n";

$seen = [];
set_error_handler(function (int $level, string $msg) use (&$seen): bool {
    echo "deprecation: ", $msg, "\n";
    return true;
});
trigger_deprecation("acme/lib", "2.0", "Use %s instead.", "bar");
restore_error_handler();

// Unsilenced, no handler — this one prints php's own line.
trigger_error("visible", E_USER_DEPRECATED);
echo "done\n";
