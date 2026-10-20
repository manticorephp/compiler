<?php
// A qualified call to an undefined function (foo\f() in namespace A) falls back to A\f / the global function instead of throwing.
// issue: #377
namespace A;

function f(): string { return "A\\f called"; }

foreach (['foo\f', '\B\strlen'] as $which) {
    try {
        if ($which === 'foo\f') {
            echo foo\f(), "\n";
        } else {
            echo \B\strlen('abc'), "\n";
        }
    } catch (\Error $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }
}
echo f(), ' ', \strlen('abcd'), ' ', strlen('ab'), "\n";
