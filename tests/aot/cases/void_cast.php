<?php

// PHP 8.5 `(void)` cast: evaluate and discard. The cast lowers AWAY, so the call
// stays in statement position and its result is still released — a Cast wrapper
// would hide it from the discarded-call release and leak.

function makeArray(int $n): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) { $out[] = 'item' . $i; }
    return $out;
}

function makeString(): string { return str_repeat('x', 64); }

for ($i = 0; $i < 2000; $i++) {
    (void) makeArray(16);
    (void) makeString();
}

echo "survived\n";
echo count(makeArray(3)), "\n";
(void) 42;
echo "done\n";
