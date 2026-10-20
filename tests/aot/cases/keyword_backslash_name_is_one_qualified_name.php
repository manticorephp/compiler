<?php
// a reserved word followed by \Name is one qualified name to Zend (echo\A\f() is a call), but the compiler lexes it as the keyword
namespace A;

function f(): string { return "called"; }

try {
    echo\A\f();
} catch (\Error $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
