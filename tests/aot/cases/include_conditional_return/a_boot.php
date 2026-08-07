<?php
// The composer bootstrap shape: a top-level `return` GUARDED by an `if`.
// vendor/autoload.php returns the loader this way, and every symfony polyfill
// bootstrap is `if (\PHP_VERSION_ID >= 80000) { return require ...80.php; }`.
//
// php: the return ends THIS FILE and hands the value back, and the including
// script carries on. Flattened into one __main it used to end the PROGRAM, so
// the entry — which sorts last — never ran at all.
//
// Each file appends to a shared log rather than echoing, because the eager
// include model runs every non-entry file's top-level code before the entry's:
// the ORDER WITHIN a file is what is under test here, and that is identical
// under both. Cross-file ordering is a separate, documented divergence.

$GLOBALS['icr_log'][] = 'boot: start';

if (strlen("abc") > 0) {
    $GLOBALS['icr_log'][] = 'boot: taking the guarded return';
    return ['from' => 'guard', 'n' => 7];
}

$GLOBALS['icr_log'][] = 'boot: MUST NOT APPEAR';
return ['from' => 'fallthrough', 'n' => 0];
