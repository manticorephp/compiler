<?php

// __FILE__ / __DIR__ used to compile to the EMPTY STRING — every other magic
// constant was handled and these two fell through to ''. Anything that locates a
// sibling file the way real code does (`require __DIR__ . '/x.php'`, a config or
// fixture path) silently looked in the filesystem root, and `file_exists()` on it
// answered false with no error to explain why.
//
// They are folded at PARSE time now, because lowering only ever sees a statement
// list flattened across every file of the build.
//
// The absolute path is machine-specific, so only path-RELATIVE facts are asserted
// here — which also keeps this comparable against php in difftest.

var_dump(__DIR__ === dirname(__FILE__));
var_dump(basename(__DIR__));
var_dump(basename(__FILE__));
var_dump(is_dir(__DIR__));
var_dump(file_exists(__FILE__));
var_dump(str_starts_with(__FILE__, '/'));
var_dump(str_ends_with(__DIR__, '/tests/aot/cases'));
var_dump(strlen(__FILE__) > strlen(__DIR__));

// A sibling directory reached through __DIR__ — the pattern that was broken.
var_dump(is_dir(__DIR__ . '/../expected'));
var_dump(file_exists(__DIR__ . '/../fixtures/tls_localhost.pem'));

// Inside a function and a closure the value is still the FILE's, not the caller's.
function whereAmI(): string { return __DIR__; }
$fn = fn(): string => __FILE__;
var_dump(whereAmI() === __DIR__);
var_dump($fn() === __FILE__);

// The other magic constants keep working.
var_dump(__LINE__ > 0);
function named(): string { return __FUNCTION__; }
var_dump(named());
class Where { public function m(): string { return __CLASS__ . '::' . __FUNCTION__; } }
var_dump((new Where())->m());
echo "done\n";
