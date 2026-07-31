<?php
// @epic: error-handling
// @why: function_exists($var) with a NON-literal argument used to fold to false
//       for EVERY function, builtins included — a loop over a list of names
//       reported strlen, count and floor all missing. It was described as a
//       conservative fold, but conservative is the wrong word for an answer that
//       is simply wrong: it is what made this project's own SAPI presence probe
//       claim trigger_error was absent, and it nearly bought a second false
//       finding. The function set is CLOSED at compile time, so the honest
//       answer is a lookup over the same closed world the literal fold consults.

foreach (['strlen', 'count', 'floor', 'trigger_error', 'ob_start', 'header',
          'session_start', 'array_map', 'preg_match', 'str_contains',
          'no_such_function_zzz'] as $n) {
    echo $n, '=', function_exists($n) ? 'y' : 'n', "\n";
}

// The two forms are decided by ONE predicate, so they may never disagree.
$present = 'strlen';
$absent  = 'no_such_function_zzz';
var_dump(function_exists('strlen') === function_exists($present));
var_dump(function_exists('no_such_function_zzz') === function_exists($absent));

// A user function is visible dynamically too.
function capFnExistsLocal(): int { return 1; }
$u = 'capFnExistsLocal';
var_dump(function_exists($u));
