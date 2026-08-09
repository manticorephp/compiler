<?php

// Recursive backend for print_r. The `print_r(...)` entry is a codegen builtin
// ({@see EmitLlvmBuiltins::biPrintR}) that DEEP-boxes its argument (so a nested
// array's values become tagged cells, not raw pointers) then calls this. PRELUDE
// (compiled WITH the program) so the `mixed` walk types against the program's
// arrays; gated in Main on `print_r(`. Matches PHP's format: an array prints
// `Array\n<pad>(\n<pad>    [k] => value\n<pad>)\n` with 8-space nesting.
//
// It BUILDS A STRING rather than echoing, exactly like `__mir_var_export`: that
// is what `print_r($v, true)` hands back, and the echo form is then just a write
// of the same text. One owner for the format — the two modes cannot drift.

function __mir_print_r_str(mixed $v, int $indent): string
{
    // RECURSE for every element (like var_dump): passing a value through the
    // `mixed $v` param re-boxes it to a proper cell, normalizing a deeply-nested
    // value whose string-ness was erased through the cell-of-cell foreach (a raw
    // pointer read as a cell). The parent's trailing "\n" both terminates a scalar
    // line and, after a nested array's own ")\n", writes PHP's blank separator.
    if (is_array($v)) {
        $pad = str_repeat(' ', $indent);
        $out = "Array\n" . $pad . "(\n";
        foreach ($v as $k => $val) {
            $out = $out . $pad . '    [' . $k . '] => '
                 . __mir_print_r_str($val, $indent + 8) . "\n";
        }
        return $out . $pad . ")\n";
    }
    if ($v instanceof \Resource) {
        // Before the is_object arm, which is true for a \Resource here (php
        // disagrees) and would print `Resource Object\n(\n)\n`. php prints
        // `Resource id #5`, with no trailing newline of its own.
        return 'Resource id #' . (string)$v->id;
    }
    if (is_object($v)) {
        $pad = str_repeat(' ', $indent);
        return get_class($v) . " Object\n" . $pad . "(\n" . $pad . ")\n";
    }
    if (is_string($v)) {
        // The is_* guard NARROWS $v to the concrete type in-branch, so the cast
        // unboxes the cell correctly. A bare `(string)$v` on the `mixed` value
        // mistypes it (a deeply-nested string reads as a raw pointer / int).
        //
        // The `''` prefix is load-bearing: unboxing a cell hands back the
        // BORROWED buffer, and the caller owns what it gets back (`$s =
        // print_r($x, true)` releases it), so the scalar arm must answer a fresh
        // string or the argument's own buffer is freed under it.
        return '' . (string)$v;
    }
    if (is_bool($v)) {
        return '' . (string)$v;        // true -> "1", false -> ""
    }
    if (is_null($v)) {
        return '';                     // print_r(null) prints nothing
    }
    return '' . (string)$v;            // int / float
}

// The echo form, kept as its own symbol for ONE generation: the compiler that
// rebuilds the compiler still emits `call @manticore___mir_print_r`, and the
// prelude is read from disk at compile time, so dropping the name would turn
// every `print_r` it compiles into a link stub. Dead code for the current
// generation's emitter, which writes the string itself.
function __mir_print_r(mixed $v, int $indent): void
{
    echo __mir_print_r_str($v, $indent);
}
