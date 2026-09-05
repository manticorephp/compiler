<?php
/**
 * `$a = null;` before a vec-property COPY leaked the copy.
 *
 * The neutral-store rule lets a `null` / string-literal seed keep a local
 * ownable, then blocks any name that ALSO takes a plain owned producer — and a
 * blocked name gets neither the release-before-overwrite nor the scope-exit
 * release. Strings and objects are carved out of that block already; arrays
 * were not, because the SIGBUS it was written for is an array one
 * (`$conds = null; … $conds = [];` in `LowerFromAst::lowerMatch`, whose buffer
 * a live `MatchArm_` still held).
 *
 * But a COPY is the one array shape with no sharing at all: a vec-property read
 * and a mutated local-to-local alias are both answered with `__mir_array_copy`
 * + `__mir_array_adopt_*`, giving the local its own rc=1 buffer. Releasing that
 * cannot free anything another owner holds, so a name whose EVERY owned store
 * is a copy keeps its release.
 *
 *   bin/manticore compile tools/prof/null_seed_array_copy.php -o /tmp/ncopy
 *   /usr/bin/time -l /tmp/ncopy 300000 2>&1 | grep 'peak memory'
 *
 * Measured (arm64, 300k calls x 8 elements, peak footprint):
 *
 *   39.6 MB -> 1.1 MB
 *
 * php 8.5 is flat.
 *
 * ⚠ The same shape in `InferScans::collectDocListKeyArgs` is NOT fixed by this:
 * there `$args` is blocked by the `vecalias` rule instead, because `$argl =
 * $args;` is a genuine borrow — the emitter shares that buffer rather than
 * copying it. Giving a local array alias a real retain is the next step, and it
 * is its own change.
 */

class NcNode
{
    public string $s = '';
}

class NcHolder
{
    /** @var NcNode[] */
    public array $items = [];
}

function nc_run(NcHolder $h): int
{
    $args = null;
    $args = $h->items;
    return count($args);
}

$h = new NcHolder();
for ($i = 0; $i < 8; $i++) {
    $n = new NcNode();
    $n->s = 'x' . $i;
    $h->items[] = $n;
}
$total = 0;
$iters = (int)($argv[1] ?? 300000);
for ($i = 0; $i < $iters; $i++) { $total += nc_run($h); }
echo $total, "\n";
