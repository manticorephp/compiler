<?php
// An exception raised inside a prelude function reports the prelude's line instead of the caller's line.
// issue: #610
function f(): void
{
    try {
        str_split('abc', 0);
    } catch (ValueError $e) {
        echo basename($e->getFile()), ':', $e->getLine(), "\n";
    }
}
f();
try {
    array_combine([1, 2], [1]);
} catch (ValueError $e) {
    echo basename($e->getFile()), ':', $e->getLine(), "\n";
}
