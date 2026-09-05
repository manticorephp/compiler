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
 *   local   CLOSED. A nested array literal in a LOCAL, not an argument: an
 *           ARRAY element had no release flavor at all, and then the outer
 *           flavor had nowhere to put the INNER one ({@see \Compile\Mir\Passes\
 *           EmitLlvmMemory::nestedArrFlavor}).
 *   nested  CLOSED. The same shape one level deeper — `vecarrarrstr`. Three
 *           levels of `arr` are emitted; deeper falls back to the repr walk.
 *   copy    CLOSED. `$q = $r` on a vec-of-arrays COPIES the buffer, and
 *           InsertMemoryOps called that "notowned" and blocked BOTH names, so
 *           neither got a release. `\Compile\Mir\VecCopyOnAssign` is now the one
 *           predicate the emitter and the pass both read.
 *
 * The working set is constant in every mode — each result is consumed to an int
 * and dropped — so RSS that tracks the iteration count IS the leak. At
 * `e172005`, 200k / 400k iterations:
 *
 *   owned    1 MB /   1 MB   flat
 *   alias   38 MB /  75 MB   LEAK   the borrowed pack element — the one left
 *   local    1 MB /   1 MB   flat   was 84/167
 *   nested   1 MB /   1 MB   flat   was 115/229
 *   copy     1 MB /   1 MB   flat   was 143/284
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

// ⚠ ONE FUNCTION PER MODE, and one LOCAL per mode. A name written with two
// different types in one function gets ONE rc flavor (first-write-wins), and
// with the nested flavors that is enough to make a whole mode read as a leak
// that is really the neighbouring mode's type. The first version of this file
// shared `$x` and `pl_run`, and it reported 84/167 for a shape that is flat.

function pl_owned(int $n): int
{
    $s = 0;
    for ($i = 0; $i < $n; $i++) { $m = array_merge(pl_pieces($i), pl_pieces($i + 1)); $s += count($m); }
    return $s;
}

function pl_alias(int $n): int
{
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        // The alias must be FRESH each iteration. A loop-invariant one leaks a
        // REFCOUNT and not memory — the same strings just climb to rc=2n — so
        // RSS reads flat and says nothing.
        $held = pl_pieces($i);
        $m = array_merge($held, ['tail']);
        $s += count($m) + count($held);
    }
    return $s;
}

function pl_local(int $n): int
{
    $s = 0;
    for ($i = 0; $i < $n; $i++) { $x = [pl_pieces($i), ['solo' . $i]]; $s += count($x) + count($x[0]); }
    return $s;
}

function pl_nested(int $n): int
{
    $s = 0;
    for ($i = 0; $i < $n; $i++) { $x = [[pl_pieces($i)], [['solo' . $i]]]; $s += count($x) + count($x[0]); }
    return $s;
}

function pl_copy(int $n): int
{
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        $x = [pl_pieces($i), pl_pieces($i + 1)];
        $q = $x;
        $x[] = ['w'];
        $s += count($x) + count($q[1]);
    }
    return $s;
}
$mode = $argc > 1 ? $argv[1] : 'owned';
$iters = $argc > 2 ? (int)$argv[2] : 200000;
if ($mode === 'owned') { echo $mode, ' ', pl_owned($iters), "\n"; }
elseif ($mode === 'alias') { echo $mode, ' ', pl_alias($iters), "\n"; }
elseif ($mode === 'local') { echo $mode, ' ', pl_local($iters), "\n"; }
elseif ($mode === 'nested') { echo $mode, ' ', pl_nested($iters), "\n"; }
elseif ($mode === 'copy') { echo $mode, ' ', pl_copy($iters), "\n"; }
else { echo $mode, " 0\n"; }
