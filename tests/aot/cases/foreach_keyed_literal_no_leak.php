<?php

// A keyed walk over a string-keyed literal releases the literal: its keys are
// constants that outlive it. Refused, the header copy loop of a server handler
// (`foreach (['content-type' => [...]] as $name => $values)`) leaked per request.
// @serial: a memory measurement.

function walk(): int
{
    $n = 0;
    foreach (['cache-control' => ['no-cache, private'], 'date' => ['Tue']] as $name => $values) {
        foreach ($values as $value) { $n += strlen($name . $value); }
    }
    return $n + strlen((string) $name) + count($values);
}

echo walk(), "\n";
$base = 0;
for ($i = 0; $i < 300000; $i++) {
    walk();
    if ($i === 1000) { $base = memory_get_peak_usage(); }
}
echo memory_get_peak_usage() - $base < 3000000 ? "flat\n" : "grows\n";
