<?php
// A local array assigned a string[] on one path and a cell-element array on another is read back as raw strings after an element store (SIGSEGV).
// issue: #376
/** @return string[] */
function pieces(string $g): array
{
    return explode(' ', $g);
}

function split(array $p, int $off): array
{
    foreach ($p as $k => $x) {
        if ($off === $k) {
            \array_splice($p, $k, 1, [$x . '1', $x . '2']);
            return $p;
        }
    }
    return $p;
}

function fix(string $g, int $cut): string
{
    $p = pieces($g);
    if ($cut >= 0) {
        $p = split($p, $cut);
    }
    $m = \count($p);
    for ($k = 0; $k < $m; $k++) {
        $c = $p[$k];
        if ($c === 'b') {
            continue;
        }
        if ($c[0] === '#') {
            $p[$k] = '//' . \substr($c, 1);
            continue;
        }
        $p[$k] = '<' . $c . '>';
    }
    return \implode('|', $p);
}

echo fix('a b #c', -1), "\n";
echo fix('a b #c', 1), "\n";
