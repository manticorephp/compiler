<?php
// The stdlib's bootstrap twins (rt/) compiled into a stdlib:false program, as in the compiler's own build.
// A builtin shadows its same-named PHP body even at a call Monomorphize repointed to a $mono$ clone,
// and through the body's by-name trampoline.

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
$f = '__mc_weak_arm';
$f();
echo "trampoline ok\n";
