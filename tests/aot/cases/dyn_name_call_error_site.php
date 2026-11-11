<?php
// An error raised while dispatching a call by run-time name reports the
// caller's file and line, not the dispatcher's.
function show(Throwable $e): void { echo get_class($e), ' ', basename($e->getFile()), ':', $e->getLine(), ' ', $e->getMessage(), "\n"; }
$x = 1;
$f = $argc > 9 ? 'strlen' : 'no_such_function';
try {
    $f($x);
} catch (Error $e) {
    show($e);
}
