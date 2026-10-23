<?php
// A string[] local reassigned from array_merge()/array_slice() results is read back with the wrong element repr when unrelated bare-array calls of the same functions exist (SIGSEGV).
/** @return string[] */
function pieces(string $g): array
{
    return explode('|', $g);
}

function isSpace(string $p): bool
{
    return $p[0] !== '/';
}

function hasR(string $p): bool
{
    return $p === 'z';
}

/** @param string[] $pieces */
function put(array $pieces): string
{
    return \implode('', $pieces);
}

function fix(string $ga, string $gb, string $ws): string
{
    $a = pieces($ga);
    $b = pieces($gb);
    $m = \count($a);
    if (!hasR($a[$m - 1])) {
        $target = 2;
        if ($target >= 0) {
            $a = \array_merge($a, \array_slice($b, 0, $target + 1));
            $b = \array_slice($b, $target + 1);
            $m = \count($a);
        }
    }
    $last = -1;
    for ($x = $m - 1; $x >= 0; $x--) {
        $last = $x;
        break;
    }
    if ($last >= 0 && isSpace($a[$last])) {
        $a[$last] = $ws;
    } else {
        $a[] = 'q';
    }
    return put($a);
}

function runArgs(array $argv): int
{
    return \count(\array_slice($argv, 1));
}

function withProgramName(array $args): int
{
    return \count(\array_merge(['x'], $args));
}

echo runArgs(\array_merge(['x'], ['help'])), "\n";
echo fix(' ', " |// c|\n", "\n"), "\n";
