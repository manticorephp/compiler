<?php
/**
 * Which ownership shape leaks a reference? One variant per shape, each with a
 * PAIRED CONTROL that differs in exactly one emitter decision — so a result
 * names a file:line, not a "category".
 *
 *   bin/manticore compile tools/prof/rcbalance.php -o /tmp/rcbalance   # MANTICORE_PROFILE=1
 *   /tmp/rcbalance <variant> <iters>
 *
 * READ THE COUNTERS, NOT THE RSS. `EmitLlvmModule.php:1290` states the profile
 * counts are deterministic while rps is not, so the verdict is an equation:
 *
 *     tagged_alloc - tagged_reclaim  ==  the objects still rooted at exit
 *
 * which for every variant here is a small constant independent of <iters>. A
 * variant whose difference GROWS with <iters> leaks one reference per
 * iteration. Peak RSS is the secondary signal, and the only one that reads the
 * quadratic string shape.
 *
 * php 8.5 is flat for every variant, so the oracle is free:
 *   php tools/prof/rcbalance.php <variant> <iters>
 *
 * ⚠ The counters only exist in a binary compiled with MANTICORE_PROFILE=1 (it
 * is a COMPILE-time flag baked into the emitted IR, Debug.php:223). Without it
 * the program runs and prints its guard value, and you get RSS only.
 *
 * ⚠ MANTICORE_POOL is opt-in and must stay OFF: the small-object pool carves
 * objects out of one mmap, so a leaked object never shows as a live malloc.
 *
 * WHAT EACH VARIANT DISCRIMINATES
 *
 *   ret_stmt        control — a discarded call result. emitDiscardedCallRelease
 *                   (EmitLlvmCalls.php) covers statement position.
 *   ret_arg_obj     control — an obj call result as a call ARGUMENT.
 *                   freshRcArgFlavor's obj arm.
 *   ret_arg_vec     control — the same for a vec (`int[]`) result.
 *   ret_arg_assoc   SUSPECT — the same for an assoc (`array<string,int>`).
 *                   EmitLlvm.php:2698 returns '' for isAssoc(), while
 *                   EmitLlvmModule.php:1974 hands assoc back at +1. The comment
 *                   at the caller says isBorrowedObjReturn covers "only
 *                   obj/vec/string"; the callee's own code says otherwise, and
 *                   records the freed-buffer crash that made it so.
 *   ret_recv        SUSPECT — `f()->m()`. A receiver temp has no release path.
 *   ret_cond        SUSPECT — `if (f()->ok())`. A condition temp likewise.
 *   ret_foreach     SUSPECT — `foreach (f() as $x)`. A subject temp likewise.
 *   prop_ow_read    SUSPECT — an object property overwritten in a loop, in a
 *                   class that ALSO has a plain getter. markPropBorrow's
 *                   default arm vetoes the release-before-overwrite for every
 *                   non-array property read, and `return $this->p;` is exactly
 *                   that shape.
 *   prop_ow_noread  control — the same overwrite with NO getter anywhere, so
 *                   the veto never fires. The pair is the whole experiment: the
 *                   veto is keyed per DECLARING CLASS, so the two shapes must
 *                   live in two DIFFERENT classes or they contaminate.
 *   append_pinned   SUSPECT (quadratic) — `$s .= …` on an accumulator that a
 *                   property also references. __mir_str_append takes its
 *                   in-place path only at rc == 1 (RuntimeLibrary.php:1213), so
 *                   one stray reference makes every append allocate 2x and
 *                   memcpy the whole accumulator, and the grow path's release
 *                   then drops 2->1 instead of freeing, leaking the old buffer.
 *   append_free     control — the same appends with nothing else referencing
 *                   the accumulator. Must stay linear.
 */

final class Holder
{
    public int $id = 0;

    public function __construct(int $id) { $this->id = $id; }

    public function ident(): int { return $this->id; }
}

final class Source
{
    public function makeObj(int $i): Holder { return new Holder($i); }

    /** @return array<string,int> */
    public function makeAssoc(int $i): array { return ['k' => $i, 'j' => $i + 1]; }

    /** @return int[] */
    public function makeVec(int $i): array { return [$i, $i + 1]; }

    public function ok(int $i): bool { return $i >= 0; }
}

function sink_obj(Holder $h): int { return $h->id; }

/** @param array<string,int> $a */
function sink_assoc(array $a): int { return $a['k']; }

/** @param int[] $a */
function sink_vec(array $a): int { return $a[0]; }

/**
 * The property slot IS read elsewhere (`peek`), so markPropBorrow's default
 * arm vetoes this class's release-before-overwrite.
 */
final class SlotRead
{
    public ?Holder $p = null;

    public function peek(): ?Holder { return $this->p; }
}

/** The same slot with no reader anywhere: nothing can veto it. */
final class SlotNoRead
{
    public ?Holder $p = null;
}

/** Holds a second reference to a string, pinning its refcount above 1. */
final class Pin
{
    public string $s = '';
}

function main(): int
{
    global $argv;
    $variant = $argv[1] ?? 'ret_stmt';
    $iters = (int)($argv[2] ?? 100000);
    $src = new Source();
    // One accumulated guard value, printed once. Without it dead-code
    // elimination is free to delete the whole loop and the variant measures
    // nothing — the failure mode an empty stub list already taught us.
    $guard = 0;

    if ($variant === 'ret_stmt') {
        for ($i = 0; $i < $iters; $i++) { $src->makeObj($i); }
    } elseif ($variant === 'ret_arg_obj') {
        for ($i = 0; $i < $iters; $i++) { $guard += sink_obj($src->makeObj($i)); }
    } elseif ($variant === 'ret_arg_assoc') {
        for ($i = 0; $i < $iters; $i++) { $guard += sink_assoc($src->makeAssoc($i)); }
    } elseif ($variant === 'ret_arg_vec') {
        for ($i = 0; $i < $iters; $i++) { $guard += sink_vec($src->makeVec($i)); }
    } elseif ($variant === 'ret_recv') {
        for ($i = 0; $i < $iters; $i++) { $guard += $src->makeObj($i)->ident(); }
    } elseif ($variant === 'ret_cond') {
        for ($i = 0; $i < $iters; $i++) {
            if ($src->makeObj($i)->ident() >= 0) { $guard++; }
        }
    } elseif ($variant === 'ret_foreach') {
        for ($i = 0; $i < $iters; $i++) {
            foreach ($src->makeVec($i) as $v) { $guard += $v; }
        }
    } elseif ($variant === 'prop_ow_read') {
        $slot = new SlotRead();
        for ($i = 0; $i < $iters; $i++) { $slot->p = new Holder($i); }
        $seen = $slot->peek();
        $guard += $seen === null ? 0 : $seen->id;
    } elseif ($variant === 'prop_ow_noread') {
        $slot2 = new SlotNoRead();
        for ($i = 0; $i < $iters; $i++) { $slot2->p = new Holder($i); }
        $guard += 1;
    } elseif ($variant === 'append_pinned') {
        $pin = new Pin();
        $acc = '';
        $pin->s = $acc;
        for ($i = 0; $i < $iters; $i++) { $acc .= 'x'; }
        $guard += \strlen($acc);
    } elseif ($variant === 'append_free') {
        $acc2 = '';
        for ($i = 0; $i < $iters; $i++) { $acc2 .= 'x'; }
        $guard += \strlen($acc2);
    } else {
        echo "unknown variant: " . $variant . "\n";
        return 2;
    }

    echo $variant . " iters=" . (string)$iters . " guard=" . (string)$guard . "\n";
    return 0;
}

exit(main());
