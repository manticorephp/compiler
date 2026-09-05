<?php
/**
 * A local array ALIAS was a borrow nobody owned, so the pass blocked the SOURCE.
 *
 * `$b = $a` on an array the frame never mutates takes neither of the emitter's
 * two roads: no `__mir_array_copy` (that needs a proven mutation) and no retain
 * (blanket-retaining every alias is what `EmitLlvmLocals` records as having
 * written rc into a live heap string). The two names shared one buffer and
 * neither owned it, so {@see InsertMemoryOps}'s `vecalias` rule had to block the
 * source or it would be released twice — and a blocked name loses BOTH its
 * release-before-overwrite and its scope-exit release, taking every owned value
 * it ever held with it.
 *
 * One `$argl = $args;` in `InferScans::collectDocListKeyArgs` cost 38.2 MB of
 * live blocks that way: not the alias itself, but the four vec-property COPIES
 * `$args` takes one line earlier, which the block stranded.
 *
 * The fix co-owns instead — the emitter takes a +1, the pass stops blocking —
 * under ONE predicate both halves ask
 * ({@see InsertMemoryOps::arrayAliasCoOwns}), deliberately narrow: both sides
 * must name the same element, and it must be a STRING or a plain OBJECT. A
 * cell / unknown / erased element is the raw-word case that corrupted, and it
 * is refused.
 *
 *   bin/manticore compile tools/prof/array_alias_borrow.php -o /tmp/aliasborrow
 *   for m in alias noalias; do
 *     /usr/bin/time -l /tmp/aliasborrow $m 200000 2>&1 | grep 'peak memory'
 *   done
 *
 * Measured (arm64, 200k calls x 6 elements, peak footprint):
 *
 *   alias    26.8 MB -> 1.1 MB
 *   noalias   1.1 MB            control: the same copy with no alias
 *
 * php 8.5 is flat for both.
 */
class AbNode { public string $s = ''; }
final class AbCall {
    /** @var AbNode[] */
    public array $args = [];
}

/** The collectCallArgElems shape: a vec-property COPY, then a plain ALIAS. */
function ab_scan(AbCall $n): int
{
    $args = $n->args;
    $argl = $args;
    $c = 0;
    foreach ($argl as $a) { $c = $c + 1; }
    return $c;
}

/** Control: the same copy with no alias. */
function ab_noalias(AbCall $n): int
{
    $args = $n->args;
    $c = 0;
    foreach ($args as $a) { $c = $c + 1; }
    return $c;
}

$n = new AbCall();
for ($i = 0; $i < 6; $i++) { $nd = new AbNode(); $nd->s = 'a' . $i; $n->args[] = $nd; }
$mode = $argv[1] ?? 'alias';
$iters = (int)($argv[2] ?? 200000);
$t = 0;
for ($i = 0; $i < $iters; $i++) {
    if ($mode === 'noalias') { $t += ab_noalias($n); } else { $t += ab_scan($n); }
}
echo $mode, ' ', $t, "\n";
