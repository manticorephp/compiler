<?php
// @epic: error-handling
// @why: symfony/deprecation-contracts is a single global function that calls
//       trigger_error(E_USER_DEPRECATED), and symfony's ErrorHandler installs
//       set_error_handler to convert those. Every symfony package depends on it.

var_dump(function_exists('trigger_error'));
var_dump(function_exists('set_error_handler'));

$seen = [];
set_error_handler(function (int $no, string $msg) use (&$seen): bool {
    $seen[] = $no . ':' . $msg;
    return true;
});

trigger_error('deprecated thing', E_USER_DEPRECATED);
trigger_error('a notice', E_USER_NOTICE);
restore_error_handler();

var_dump($seen);
