<?php
// an int list vivified in a returned tuple, destructured and passed to an array<string, int[]> parameter, reads its ints as boxed words
/** @return array{0: array<string, int[]>, 1: int} */
function index(): array
{
    $by = [];
    foreach (['a', 'b', 'a'] as $i => $w) {
        $by[$w][] = $i;
    }
    return [$by, 0];
}

/** @param array<string, int[]> $by */
function used(array $by): void
{
    foreach ($by['a'] ?? [] as $occ) {
        echo $occ, "\n";
    }
}

[$by, $n] = index();
used($by);
