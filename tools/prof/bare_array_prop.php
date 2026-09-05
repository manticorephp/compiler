<?php
/**
 * A concrete array stored into a BARE `array` property strands every element.
 *
 * The store retains at the VALUE's flavor ({@see EmitLlvmMemory::
 * arrayRetainFlavor} reads the informative side, so a `vec[obj<BapNode>]` local
 * retains at `vecobj` — buffer AND elements), while the class drop for a slot
 * whose declared type is a bare `array` is the repr-dispatching
 * `__mir_array_release`. That helper reads element ownership off the buffer's
 * own `ARRAY_REPR_*` bits, and a CONCRETE array is deliberately never stamped
 * ({@see EmitLlvmArrays::erasedReprCode} — the `uasort` write-back hazard). So
 * the drop frees the buffer and gives back nothing: one leaked element graph
 * per call, with the retain that took them still outstanding.
 *
 * The SAME property declared `@var BapNode[]` is flat. That contrast is the
 * whole diagnosis, and it is why `typed` is a mode here and not a comment.
 *
 *   bin/manticore compile tools/prof/bare_array_prop.php -o /tmp/bareprop
 *   for m in bare typed elemstore moved; do
 *     /usr/bin/time -l /tmp/bareprop $m 100000 2>&1 | grep 'peak memory'
 *   done
 *
 * Measured (arm64, 100k iterations x 20 nodes, peak footprint):
 *
 *   bare        226.4 MB -> 1.3 MB   whole-slot store from a LOCAL (retained)
 *   moved       226.4 MB -> 1.3 MB   whole-slot store of a call RETURN (moved)
 *   typed         1.3 MB            control: the element type is declared
 *   elemstore     1.3 MB            control: an element store STAMPS the repr,
 *                                   so the plain release already walks it
 *
 * php 8.5 is flat for all four.
 *
 * ⚠ The two leaking modes need SEPARATE classes from the two flat ones: the
 * flags that decide a slot's drop are keyed by declaring class + property, so
 * sharing one class let the element-store mode silence the others and the
 * probe read flat for the wrong reason.
 */

class BapNode
{
    public string $s = '';
}

/** The leaking shape: no element type on the slot.
 *
 * ⚠ ONE CLASS PER MODE, deliberately. The flags that decide a slot's drop are
 * keyed by declaring class + property, so an element store or a moved store
 * into the SAME class silences the whole-slot-store leak and the probe reads
 * flat for the wrong reason (it did, first try). */
class BapBare
{
    public array $kids = [];
}

class BapMoved
{
    public array $kids = [];
}

class BapElem
{
    public array $kids = [];
}

/** The control: the same slot, element type declared. */
class BapTyped
{
    /** @var BapNode[] */
    public array $kids = [];
}

/** @return BapNode[] */
function bap_build(int $n): array
{
    $kids = [];
    for ($i = 0; $i < $n; $i++) {
        $nd = new BapNode();
        $nd->s = 'node' . $i;
        $kids[] = $nd;
    }
    return $kids;
}

function bap_bare(int $n): int
{
    // Built into a LOCAL, so the property store RETAINS; the `moved` mode is
    // the same store of a call RETURN, which transfers its +1 instead. Both
    // leak, and by the same amount — the drop is what is wrong, not the store.
    $kids = [];
    for ($i = 0; $i < $n; $i++) {
        $nd = new BapNode();
        $nd->s = 'node' . $i;
        $kids[] = $nd;
    }
    $h = new BapBare();
    $h->kids = $kids;
    return count($h->kids);
}

function bap_typed(int $n): int
{
    $kids = [];
    for ($i = 0; $i < $n; $i++) {
        $nd = new BapNode();
        $nd->s = 'node' . $i;
        $kids[] = $nd;
    }
    $h = new BapTyped();
    $h->kids = $kids;
    return count($h->kids);
}

function bap_moved(int $n): int
{
    $h = new BapMoved();
    $h->kids = bap_build($n);
    return count($h->kids);
}

/** Element stores through the slot: the base is erased, so each store stamps
 *  the buffer's repr and the plain release already walks it. */
function bap_elemstore(int $n): int
{
    $h = new BapElem();
    for ($i = 0; $i < $n; $i++) {
        $nd = new BapNode();
        $nd->s = 'node' . $i;
        $h->kids[] = $nd;
    }
    return count($h->kids);
}

$mode = $argv[1] ?? 'bare';
$iters = (int)($argv[2] ?? 100000);
$total = 0;
for ($i = 0; $i < $iters; $i++) {
    if ($mode === 'typed') { $total += bap_typed(20); }
    elseif ($mode === 'elemstore') { $total += bap_elemstore(20); }
    elseif ($mode === 'moved') { $total += bap_moved(20); }
    else { $total += bap_bare(20); }
}
echo $mode, ' ', $total, "\n";
