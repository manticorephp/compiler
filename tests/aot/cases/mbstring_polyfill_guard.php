<?php
// symfony/polyfill-mbstring's shape: a guarded user definition must lose to the stdlib one.
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, ?string $encoding = null): int { return -1; }
}
if (!function_exists('mb_trim')) {
    function mb_trim(string $s, ?string $characters = null, ?string $encoding = null): string { return 'polyfill'; }
}
var_dump(function_exists('mb_str_pad'), mb_strlen("ї中"), mb_trim(" ї "));
