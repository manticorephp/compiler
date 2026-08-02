<?php

// A non-entry file whose top-level `return` sits inside an `if`. This is the
// composer bootstrap shape, not a contrived one:
//
//   vendor/symfony/polyfill-intl-icu/bootstrap.php:19
//       if (\PHP_VERSION_ID >= 80000) {
//           return require __DIR__.'/bootstrap80.php';
//       }
//
// and vendor/autoload.php returns the loader the same way.
//
// php: the `return` ends THIS FILE and the including script continues.
// manticore: every file's top-level statements are flattened into one __main,
// so it ends the PROGRAM — the entry never runs.

if (strlen("abc") > 0) {
    return 1;
}

echo "a_boot: not reached in php either\n";
