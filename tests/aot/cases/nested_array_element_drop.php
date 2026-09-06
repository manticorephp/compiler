<?php
// A NESTED array element is the member the release-flavor family was missing:
// `$a = [f(), g()]` freed the outer buffer and stranded both inner arrays.
// `vecarr` / `assocarr` close that, and this pins the half that a wrong claim
// breaks — the retain and the release are ONE decision, so an alias, a
// by-value argument, a param and a recursive call all have to agree. Getting
// the release deeper than the retain here freed a live key: array_merge_
// recursive answered `[""]=> float(2.16E-314)`.

/** @return string[] */
function nd_row(int $i): array { return explode(',', 'a,b' . $i); }

/** @param array<int, array> $rows */
function nd_total(array $rows): int
{
    $n = 0;
    foreach ($rows as $r) { $n += count($r); }
    return $n;
}

/** @param array<int, array> $rest */
function nd_pack(array $first, array ...$rest): int
{
    $n = count($first);
    foreach ($rest as $r) { $n += count($r); }
    return $n;
}

$out = [];
for ($i = 0; $i < 3; $i++) {
    // A nested literal in a LOCAL, rebuilt every iteration — the drop path.
    $grid = [nd_row($i), ['solo' . $i]];
    // An ALIAS of it: the retain must co-own to the same depth the drop walks.
    $same = $grid;
    // By value into a callee, which co-owns what it was handed.
    $out[] = nd_total($grid) . ':' . count($same) . ':' . $same[0][1];
    // The variadic pack of the same shape, and a nested literal INSIDE one.
    $out[] = (string)nd_pack($grid[0], nd_row($i + 1), [[1, 2], [3]][0]);
    // Overwrite the local with a different nesting, then read it back.
    $grid = [[$i], [$i + 1, $i + 2]];
    $out[] = count($grid) . '/' . count($grid[1]) . '/' . $grid[1][1];
}
foreach ($out as $line) { echo $line, "\n"; }

// The recursive shape that caught a release deeper than its retain.
$a = ['x' => ['p' => 1, 'q' => [1, 2]], 'y' => 'keep'];
$b = ['x' => ['q' => [9], 'r' => 3]];
$m = array_merge_recursive($a, $b);
echo implode(',', array_keys($m)), ' ', implode(',', array_keys($m['x'])), ' ',
     count($m['x']['q']), ' ', $m['y'], "\n";
$r = array_replace_recursive($a, $b);
echo implode(',', array_keys($r['x'])), ' ', $r['x']['q'][0], ' ', $r['y'], "\n";
