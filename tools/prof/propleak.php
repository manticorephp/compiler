<?php
/**
 * An rc-managed value stored into an object PROPERTY is never released when the
 * property is overwritten. Every local equivalent is flat, so this is a property
 * -slot gap, not a general one.
 *
 *   bin/manticore compile tools/prof/propleak.php -o /tmp/propleak
 *   for v in arr str obj arrlocal strlocal objlocal snap; do
 *     /usr/bin/time -l /tmp/propleak $v 400000 2>&1 | grep 'maximum resident'
 *   done
 *
 * Measured (arm64, 100k vs 400k iterations, peak RSS):
 *
 *   $o->arr = <fresh array>    191.7 -> 761.3 MB   LEAK
 *   $o->str = <fresh string>    45.4 -> 176.1 MB   LEAK
 *   $o->obj = new Node2(...)     4.8 ->  14.0 MB   LEAK  (~32 B/iter = one object)
 *   any of the three into a LOCAL  1.8 ->   1.8 MB  flat
 *
 * php 8.5 is flat for every variant.
 *
 * ⚠ WHY THE OBVIOUS FIX IS WRONG — release-before-overwrite in
 * `EmitLlvmObjects::emitStoreProperty` makes all three flat and passes a gen-1
 * self-build and its smoke test, then the gen-2 compiler emits
 *
 *     %r19 = getelementptr inbounds i8, ptr 19, i64 48
 *
 * a register-name string that was freed while still in use. The cause is the
 * `snap` shape below: `$objPtr = $this->lastValue` is a property read into a
 * local, and it takes NO reference — it borrows. The missing release is what
 * keeps that borrow valid. So the two are one problem with an order:
 * a property READ must own what it reads BEFORE a property WRITE may drop it.
 *
 * `snap` also shows why the leak hid for so long: it is invisible unless the
 * property actually changes, because otherwise every iteration aliases the one
 * same buffer.
 */

final class Node2
{
    public function __construct(public int $v) {}
}

final class Box
{
    /** @var array<string,string> */
    public array $arr = [];
    public string $str = '';
    public ?Node2 $obj = null;
}

/** @return array<string,string> */
function pl_freshArr(): array
{
    $out = [];
    for ($k = 0; $k < 12; $k++) { $out["key_" . $k] = "v" . $k; }
    return $out;
}

function pl_freshStr(): string
{
    $s = '';
    for ($k = 0; $k < 40; $k++) { $s = $s . "xyzabcdef"; }
    return $s;
}

$b = new Box();
$variant = isset($argv[1]) ? $argv[1] : 'arr';
$n = isset($argv[2]) ? (int)$argv[2] : 400000;

if ($variant === 'arr') {
    for ($i = 0; $i < $n; $i++) { $b->arr = pl_freshArr(); }
} elseif ($variant === 'str') {
    for ($i = 0; $i < $n; $i++) { $b->str = pl_freshStr(); }
} elseif ($variant === 'obj') {
    for ($i = 0; $i < $n; $i++) { $b->obj = new Node2($i); }
} elseif ($variant === 'arrlocal') {
    $l = [];
    for ($i = 0; $i < $n; $i++) { $l = pl_freshArr(); }
} elseif ($variant === 'strlocal') {
    $s = '';
    for ($i = 0; $i < $n; $i++) { $s = pl_freshStr(); }
} elseif ($variant === 'objlocal') {
    $o = null;
    for ($i = 0; $i < $n; $i++) { $o = new Node2($i); }
} elseif ($variant === 'snap') {
    // The compiler's own mergeLocals shape, and the one that shows the BORROW:
    // the snapshot local is never passed anywhere, yet every buffer the property
    // leaves behind stays resident. Overwriting the property is what makes it
    // visible — without that, every iteration aliases the same one buffer.
    $b->arr = pl_freshArr();
    for ($i = 0; $i < $n; $i++) {
        $saved = $b->arr;
        $b->arr = pl_freshArr();
    }
}
echo $variant, " ", $n, "\n";
