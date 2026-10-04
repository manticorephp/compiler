<?php
// `$dp[$k][] = row` on an absent mixed-key level creates the level inside the
// store; the boxed row must leave that fresh buffer CELL-hinted so a typed
// reader (docblock-typed param) decodes it instead of dereferencing the tag.

/** @param list<array{int, int}> $u */
function show(array $u): void
{
    foreach ($u as $r) {
        echo $r[0], ':', $r[1], "\n";
    }
}

function group(string $doc): array
{
    preg_match_all('/[a-z]+/', $doc, $matches);
    $dp = [];
    foreach ($matches[0] as $k => $m) {
        $dp[$m][] = [3, $k];
    }
    return $dp;
}

foreach (group('one two one three') as $name => $rows) {
    echo $name, "\n";
    show($rows);
}
