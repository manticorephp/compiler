<?php
/**
 * The two halves of array-LITERAL element ownership that are still OPEN, in one
 * binary. The closed half — an OWNED pack element, freed by its own flavor — is
 * bench/cases/variadic_pack.php and stays in the corpus because it is flat.
 *
 *   bin/manticore compile tools/prof/packleak.php -o /tmp/packleak
 *   for v in owned alias local nested; do
 *     /usr/bin/time -l /tmp/packleak $v 400000 2>&1 | grep 'maximum resident'
 *   done
 *
 * php 8.5 is flat for every variant.
 *
 *   owned   the closed case — the control. A fresh producer in the pack
 *           transferred its +1, the literal is its sole owner, and the
 *           post-call release frees it completely ({@see \Compile\Mir\Passes\
 *           EmitLlvmArrays::emitArrayLitValue}).
 *   alias   ⛔OPEN. A BORROWED alias in the pack: the literal takes a co-owner
 *           retain that co-owns the ELEMENTS too, and giving those refs back
 *           after the call miscompiles the compiler (bisected, not explained —
 *           `8ab002a` died in LowerFns::finishClosure, the alias arm alone in
 *           LowerFromAst::bareName, both reading a recycled string header). So
 *           the release is buffer-only and leaks ONE REF PER ELEMENT per call.
 *   local   ⛔OPEN. A nested array literal in a LOCAL, not an argument. Nothing
 *           collects it at all: the by-value hand-off that justifies the
 *           call-site release does not exist here, so this one wants an
 *           ARRAY_REPR_* ownership stamp on the producer instead.
 *   nested  ⛔OPEN. The same local shape one level deeper, to show the leak is
 *           per NESTING LEVEL and not a one-off.
 *
 * The working set is constant in every mode — each result is consumed to an int
 * and dropped — so RSS that tracks the iteration count IS the leak. At
 * `e172005`, 200k / 400k iterations:
 *
 *   owned    1 MB /   1 MB   flat
 *   alias   38 MB /  75 MB   LEAK
 *   local   84 MB / 167 MB   LEAK
 *   nested 115 MB / 229 MB   LEAK
 *
 * ⚠ Every leaking mode needs a FRESH array each iteration. A loop-invariant
 * alias leaks a REFCOUNT and not memory — the same strings climb to rc=2n and
 * RSS reads flat — and a string LITERAL element is immortal, so its retain is a
 * sentinel no-op and hides the arm completely.
 */

/** @return string[] */
function pl_pieces(int $i): array
{
    return explode(',', 'alpha,beta,gamma' . $i);
}

function pl_run(string $mode, int $iters): int
{
    $sum = 0;
    // Built at RUNTIME on purpose: a string LITERAL is immortal and its retain
    // is a sentinel no-op, so a base of literals hides an alias arm entirely.
    $base = pl_pieces(7);
    for ($i = 0; $i < $iters; $i++) {
        if ($mode === 'owned') {
            $m = array_merge(pl_pieces($i), pl_pieces($i + 1));
            $sum += count($m);
        } elseif ($mode === 'alias') {
            // The alias must be FRESH each iteration. A loop-invariant one leaks a
            // REFCOUNT and not memory — the same three strings just climb to
            // rc=2n — so RSS reads flat and says nothing.
            $held = pl_pieces($i);
            $m = array_merge($held, ['tail']);
            $sum += count($m) + count($held);
        } elseif ($mode === 'local') {
            $x = [pl_pieces($i), ['solo' . $i]];
            $sum += count($x) + count($x[0]);
        } elseif ($mode === 'nested') {
            $x = [[pl_pieces($i)], [['solo' . $i]]];
            $sum += count($x) + count($x[0]);
        } else {
            $sum += 1;
        }
    }
    return $sum + count($base);
}

$mode = $argc > 1 ? $argv[1] : 'owned';
$iters = $argc > 2 ? (int)$argv[2] : 200000;
echo $mode, ' ', pl_run($mode, $iters), "\n";
