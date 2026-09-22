<?php

// A top-level `return` in the ENTRY script ends the script, exactly as one in
// an included file ends that file (tests/aot/cases/include_conditional_return).
// It is not an exit STATUS — php leaves `$?` at 0 — and it is not a bailout
// either: the output buffer still drains and the shutdown functions still run.
//
// The bare form was the one that fell through: `return 5;` ended __main and
// `return;` emitted nothing at all, so everything after it ran.

register_shutdown_function(function (): void {
    echo "shutdown ran\n";
});

ob_start();
echo "buffered\n";
ob_end_flush();

echo "before\n";

if (strlen('ab') === 2) {
    return;
}

echo "MUST NOT APPEAR\n";
