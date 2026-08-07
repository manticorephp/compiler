<?php

// Every desugaring in LowerExprs' call dispatch, reached through a NAMESPACE.
// An unqualified call inside `namespace App;` is qualified to `App\<name>` by
// the parser, and a gate matching the QUALIFIED name saw no match: the call
// fell through to an ordinary emit and died with "Call to undefined function".

namespace App;

define('APP_ANSWER', 42);

function shout(string $s): string { return \strtoupper($s) . '!'; }

$a = 1;
$b = 'two';
print_r(compact('a', 'b'));

var_dump(defined('APP_ANSWER'));
var_dump(defined('APP_MISSING'));
var_dump(constant('APP_ANSWER'));
var_dump(APP_ANSWER);

var_dump(function_exists('App\shout'));
var_dump(function_exists('no_such_function_at_all'));
$dyn = 'strlen';
var_dump(function_exists($dyn));

var_dump(call_user_func('App\shout', 'hi'));
var_dump(call_user_func_array('App\shout', ['ho']));
// A LITERAL argument array only. `call_user_func_array($cb, $runtimeArray)`
// forwards a `...$arr` pack, and a CODEGEN BUILTIN callee (str_repeat here) has
// no signature to expand it against — that is a compile-time refusal now, and
// was a compiler SIGSEGV before. Unrelated to the namespace, so not this file's
// subject; a stdlib/user callee takes the pack fine.
var_dump(call_user_func_array('str_repeat', ['ab', 2]));

$vals = [3, 1, 2];
$keys = ['c', 'a', 'b'];
array_multisort($vals, $keys);
print_r($vals);
print_r($keys);

$out = fopen('php://stdout', 'w');
fwrite($out, "stdout via fopen\n");

var_dump(count([[1, 2], [3]], COUNT_RECURSIVE));
var_dump(1.5, 'str', true, null);
