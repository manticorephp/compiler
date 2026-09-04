<?php

// The synthesized init carries the callee's DECLARED parameter type on
// `declaredType`, which is what stops the slot re-inferring to `unknown` and
// reading the callee-written elements raw. Read the out-array back every way
// that crosses the element channel: count, indexed read, and a keyed foreach.

function collect(int $n, ?array &$out): int
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out['k' . $i] = $i * 10;
    }
    return $n;
}

echo collect(3, $bag), "\n";
var_dump(count($bag));
var_dump($bag['k1']);
foreach ($bag as $k => $v) {
    echo $k, '=', $v, "\n";
}
var_dump($bag);

// ⚠ The parameter must be NULLABLE. php vivifies the undefined argument as
// NULL and then type-checks it against the declaration, so a non-nullable
// `array &$rows` is a TypeError at the CALL — the out-parameter idiom is
// spelled `?array` for exactly this reason.
function collectList(int $n, ?array &$rows): void
{
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = 'r' . $i;
    }
}

collectList(2, $list);
var_dump($list);
echo implode(',', $list), "\n";
