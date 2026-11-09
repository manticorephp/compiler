<?php
// A builtin's same-named PHP body compiled into the program (the stdlib bootstrap twin, as in the compiler's own build) is shadowed even at a call Monomorphize repointed to a $mono$ clone.
/** @param mixed[] $a */
function __mc_sort_native(array &$a): bool { return false; }

/**
 * @param array<string, array<string, bool>> $deps
 * @return string[]
 */
function reach(array $deps, string $root): array
{
    /** @var array<string, bool> $seen */
    $seen = [];
    $queue = [$root];
    for ($i = 0; $i < count($queue); $i++) {
        foreach ($deps[$queue[$i]] ?? [] as $d => $_) {
            if (isset($seen[$d])) { continue; }
            $seen[$d] = true;
            $queue[] = $d;
        }
    }
    unset($seen[$root]);
    return array_keys($seen);
}

$a = reach(['i' => ['zeta' => true, 'alpha' => true], 'zeta' => ['mid' => true]], 'i');
sort($a);
echo implode(',', $a), "\n";
