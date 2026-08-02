<?php
// The polyfill idiom, twice over.
//
// 1. `if (!defined('N')) { define('N', v); }` must register N, because the
//    constant is then used where only a compile-time constant is allowed — a
//    DEFAULT PARAMETER VALUE. 45 guarded defines across symfony's tier-1
//    packages have exactly this shape, and without it the whole polyfill layer
//    is a hard `unknown constant` error.
//
// 2. `if (PHP_VERSION_ID < <target>) { ... }` must FOLD, not stay a runtime
//    branch. Version-shim bodies DECLARE CLASSES, which lowering does not
//    support as a statement — and folding is also the only correct answer,
//    since on a newer target the shim must not exist at all.

if (!defined('GUARDED_INT')) {
    define('GUARDED_INT', 5);
}
if (!defined('GUARDED_STR')) {
    define('GUARDED_STR', 'gs');
}

// An unconditional define WINS over a later guarded one for the same name.
define('WINS', 'first');
if (!defined('WINS')) {
    define('WINS', 'second');
}

// A duplicate guarded define is harmless — the shape that actually occurs when
// a polyfill ships bootstrap.php AND bootstrap80.php and both are compiled.
if (!defined('GUARDED_INT')) {
    define('GUARDED_INT', 999);
}

echo GUARDED_INT, "\n";
echo GUARDED_STR, "\n";
echo WINS, "\n";

var_dump(defined('GUARDED_INT'));
var_dump(defined('NEVER_DEFINED_ANYWHERE'));

// The reason it matters: a default parameter value is a constant expression.
function withDefault(int $flags = GUARDED_INT, string $tag = GUARDED_STR): string
{
    return $tag . ':' . $flags;
}
echo withDefault(), "\n";
echo withDefault(9), "\n";
echo withDefault(9, 'x'), "\n";

// A guard for a name that is NOT defined anywhere stays live and runs.
if (!defined('LATE_ONE')) {
    define('LATE_ONE', 'late');
}
echo LATE_ONE, "\n";

// Ordered version guards fold both ways.
if (PHP_VERSION_ID >= 80000) {
    echo "modern\n";
}
if (PHP_VERSION_ID < 70000) {
    echo "ancient — must never print\n";
}
echo PHP_VERSION_ID > 70000 ? "gt ok\n" : "gt wrong\n";
echo PHP_VERSION_ID <= 999999 ? "le ok\n" : "le wrong\n";
