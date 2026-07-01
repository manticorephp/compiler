<?php

// Recursive backend for print_r. The `print_r(...)` entry is a codegen builtin
// ({@see EmitLlvmBuiltins::biPrintR}) that DEEP-boxes its argument (so a nested
// array's values become tagged cells, not raw pointers) then calls this. PRELUDE
// (compiled WITH the program) so the `mixed` walk types against the program's
// arrays; gated in Main on `print_r(`. Matches PHP's format: an array prints
// `Array\n<pad>(\n<pad>    [k] => value\n<pad>)\n` with 8-space nesting.

function __mir_print_r(mixed $v, int $indent): void
{
    // RECURSE for every element (like var_dump): passing a value through the
    // `mixed $v` param re-boxes it to a proper cell, normalizing a deeply-nested
    // value whose string-ness was erased through the cell-of-cell foreach (a raw
    // pointer read as a cell). The parent's trailing "\n" both terminates a scalar
    // line and, after a nested array's own ")\n", writes PHP's blank separator.
    // Scalars print through `echo`'s runtime tag dispatch (int->digits,
    // string->bytes, bool true->"1"/false->"", null->"").
    if (is_array($v)) {
        $pad = str_repeat(' ', $indent);
        echo "Array\n", $pad, "(\n";
        foreach ($v as $k => $val) {
            echo $pad, '    [', $k, '] => ';
            __mir_print_r($val, $indent + 8);
            echo "\n";
        }
        echo $pad, ")\n";
    } else if (is_object($v)) {
        $pad = str_repeat(' ', $indent);
        echo get_class($v), " Object\n", $pad, "(\n", $pad, ")\n";
    } else if (is_string($v)) {
        // The is_* guard NARROWS $v to the concrete type in-branch, so the cast
        // unboxes the cell correctly. A bare `(string)$v` on the `mixed` value
        // mistypes it (a deeply-nested string reads as a raw pointer / int).
        echo (string)$v;
    } else if (is_bool($v)) {
        echo (string)$v;               // true -> "1", false -> ""
    } else if (is_null($v)) {
        // print_r(null) / a null element prints nothing.
    } else {
        echo (string)$v;               // int / float
    }
}
