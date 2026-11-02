<?php
// require/include of a constant path outside the compile set.
require __DIR__ . '/arith_string.php';
include_once __DIR__ . '/arith_string.php';
require_once __DIR__ . '/no_such_file.php';
include __DIR__ . '/no_such_file.php';
require __DIR__ . '/include_unresolved.php';
