<?php

/**
 * `__mir_var_export` — the recursive backend of the `var_export` builtin, the
 * exact counterpart of `__mir_var_dump`: it walks a tagged cell through the
 * is_type / cast / foreach primitives, and its object arm calls
 * `__mir_export_object`, which LowerFromAst generates per class table.
 *
 * It lives HERE rather than in the stdlib (where the array-only version used to)
 * for one reason: the stdlib is a prebuilt `.o` and cannot receive an object, so
 * an object nested inside an array had nowhere to go. One owner, in the prelude,
 * where the generated per-class arms are also linked.
 *
 * `$indent` is php's `level - 1` expressed in columns. php indents var_export by
 * a level counter that rises by 2 per nesting, and it spends that counter
 * differently either side of a `=>`:
 *
 *   - an ARRAY prints its keys at $indent + 2 and closes at $indent
 *   - an OBJECT prints its keys at $indent + 3 and closes at $indent
 *   - either one, when nested, first breaks the line and indents to $indent
 *
 * Both recurse at $indent + 2. The off-by-one between the two key columns is
 * php's, not a rounding here — `\P::__set_state(array(` puts its first key at
 * column 3 while `array (` puts its first key at column 2.
 */
function __mir_var_export(mixed $v, int $indent): string
{
    if ($v === null) { return 'NULL'; }
    if (is_bool($v)) { return $v ? 'true' : 'false'; }
    if (is_int($v)) { return (string)$v; }
    if (is_float($v)) {
        // upperE=1, forceDot=1 — var_export must round-trip as a float, so an
        // integer-valued decimal keeps its `.0`.
        return __mc_dtoa_core(__float_bits((float)$v), 1, 1);
    }
    if (is_string($v)) {
        return "'" . __mc_var_export_qstr((string)$v) . "'";
    }
    // BEFORE is_object, exactly as var_dump does: a \Resource IS an object to us
    // and the object arm would walk its guts. php exports any resource as NULL.
    if ($v instanceof \Resource) { return 'NULL'; }
    if (is_object($v)) { return __mir_export_object($v, $indent); }
    if (!is_array($v)) { return 'NULL'; }
    $pad = str_repeat(' ', $indent);
    $inner = $pad . '  ';
    $out = ($indent > 0 ? ("\n" . $pad) : '') . "array (\n";
    foreach ($v as $k => $e) {
        $ks = is_int($k) ? (string)$k : ("'" . __mc_var_export_qstr((string)$k) . "'");
        $out = $out . $inner . $ks . ' => ' . __mir_var_export($e, $indent + 2) . ",\n";
    }
    return $out . $pad . ')';
}
