<?php

/**
 * `__mir_var_dump` — the recursive backend of the `var_dump` builtin. Walks a
 * tagged cell through the is_type / cast / foreach primitives.
 *
 * Injected only when the program actually CALLS var_dump (see the demand gate in
 * Main.php): it pulls in the tagged/assoc runtime, and its object arm calls
 * `__mir_dump_object`, which LowerFromAst generates per class table (a tenth of
 * the module in a big program).
 */
function __mir_var_dump(mixed $v, int $indent): void
{
    $pad = '';
    $j = 0;
    while ($j < $indent) { $pad = $pad . '  '; $j = $j + 1; }
    if (is_int($v)) { echo 'int(', (string)$v, ")\n"; return; }
    if (is_float($v)) { echo 'float(', __mir_float_repr($v), ")\n"; return; }
    if (is_bool($v)) {
        $b = (string)$v;
        if ($b === '1') { echo "bool(true)\n"; } else { echo "bool(false)\n"; }
        return;
    }
    if (is_null($v)) { echo "NULL\n"; return; }
    if (is_string($v)) {
        $sv = (string)$v;
        echo 'string(', (string)strlen($sv), ') "', $sv, "\"\n";
        return;
    }
    if (is_object($v)) { __mir_dump_object($v, $indent); return; }
    echo 'array(', (string)count($v), ") {\n";
    foreach ($v as $k => $val) {
        if (is_int($k)) { echo $pad, '  [', (string)$k, "]=>\n", $pad, '  '; }
        else { echo $pad, '  ["', $k, "\"]=>\n", $pad, '  '; }
        __mir_var_dump($val, $indent + 1);
    }
    echo $pad, "}\n";
}
