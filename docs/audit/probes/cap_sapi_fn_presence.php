<?php
// @epic: sapi-layer
// @why: the existence question alone, separated from calling these, because a
//       call to an absent function is a hard compile error under `compile` and
//       a silent link-stub under `build` -- either way it would mask the survey.
//       This is the SAPI epic's checklist.

$fns = [
    'header', 'header_remove', 'headers_sent', 'headers_list', 'http_response_code',
    'setcookie', 'setrawcookie',
    'session_start', 'session_id', 'session_name', 'session_destroy',
    'ob_start', 'ob_get_clean', 'ob_get_contents', 'ob_end_clean', 'ob_get_level',
    'php_sapi_name', 'putenv', 'filter_input', 'fastcgi_finish_request',
    'spl_autoload_register', 'spl_autoload_unregister', 'spl_autoload_functions',
    'trigger_error', 'set_error_handler', 'restore_error_handler',
    'register_shutdown_function', 'error_get_last',
    'move_uploaded_file', 'is_uploaded_file',
    'cli_set_process_title', 'stream_resolve_include_path', 'phpinfo',
];
foreach ($fns as $f) { echo $f, '=', function_exists($f) ? 'present' : 'missing', "\n"; }
echo 'PHP_SAPI=', PHP_SAPI, "\n";
