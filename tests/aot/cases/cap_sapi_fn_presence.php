<?php
// @epic: sapi-layer
// @why: the existence question alone, separated from calling these, because a
//       call to an absent function is a hard compile error under `compile` and
//       a silent link-stub under `build` -- either way it would mask the survey.
//       This is the SAPI epic's checklist.
//
// ⚠ Every call below passes a STRING LITERAL, and that is load-bearing. The
// first version of this probe looped `function_exists($f)` over an array, which
// measures something else entirely: a non-literal argument folds to false for
// EVERY function, builtins included, so the probe reported `trigger_error` and
// `ob_start` missing when both plainly work. It measured the dynamic-argument
// gap (its own row, `function-exists-dynamic-arg`) and called the answer SAPI
// coverage. Third time this audit has been bitten by a probe that measured
// PRESENCE-as-reported rather than the thing it named -- keep the literals.

echo 'header=', function_exists('header') ? 'present' : 'missing', "\n";
echo 'header_remove=', function_exists('header_remove') ? 'present' : 'missing', "\n";
echo 'headers_sent=', function_exists('headers_sent') ? 'present' : 'missing', "\n";
echo 'headers_list=', function_exists('headers_list') ? 'present' : 'missing', "\n";
echo 'http_response_code=', function_exists('http_response_code') ? 'present' : 'missing', "\n";
echo 'setcookie=', function_exists('setcookie') ? 'present' : 'missing', "\n";
echo 'setrawcookie=', function_exists('setrawcookie') ? 'present' : 'missing', "\n";
echo 'session_start=', function_exists('session_start') ? 'present' : 'missing', "\n";
echo 'session_id=', function_exists('session_id') ? 'present' : 'missing', "\n";
echo 'session_name=', function_exists('session_name') ? 'present' : 'missing', "\n";
echo 'session_destroy=', function_exists('session_destroy') ? 'present' : 'missing', "\n";
echo 'ob_start=', function_exists('ob_start') ? 'present' : 'missing', "\n";
echo 'ob_get_clean=', function_exists('ob_get_clean') ? 'present' : 'missing', "\n";
echo 'ob_get_contents=', function_exists('ob_get_contents') ? 'present' : 'missing', "\n";
echo 'ob_end_clean=', function_exists('ob_end_clean') ? 'present' : 'missing', "\n";
echo 'ob_get_level=', function_exists('ob_get_level') ? 'present' : 'missing', "\n";
echo 'php_sapi_name=', function_exists('php_sapi_name') ? 'present' : 'missing', "\n";
echo 'putenv=', function_exists('putenv') ? 'present' : 'missing', "\n";
echo 'spl_autoload_register=', function_exists('spl_autoload_register') ? 'present' : 'missing', "\n";
echo 'spl_autoload_unregister=', function_exists('spl_autoload_unregister') ? 'present' : 'missing', "\n";
echo 'spl_autoload_functions=', function_exists('spl_autoload_functions') ? 'present' : 'missing', "\n";
echo 'trigger_error=', function_exists('trigger_error') ? 'present' : 'missing', "\n";
echo 'set_error_handler=', function_exists('set_error_handler') ? 'present' : 'missing', "\n";
echo 'restore_error_handler=', function_exists('restore_error_handler') ? 'present' : 'missing', "\n";
echo 'register_shutdown_function=', function_exists('register_shutdown_function') ? 'present' : 'missing', "\n";
echo 'error_get_last=', function_exists('error_get_last') ? 'present' : 'missing', "\n";
echo 'PHP_SAPI=', PHP_SAPI, "\n";
