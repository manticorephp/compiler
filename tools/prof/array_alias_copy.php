<?php
/**
 * `$b = $a` on an array the frame MUTATES is a COPY, and the copy leaked.
 *
 * php arrays are values, so {@see EmitLlvmLocals::emitStoreLocal} answers such
 * a store with `__mir_array_copy` + `__mir_array_adopt_*`: a fresh, independent,
 * rc=1 buffer that the destination owns outright. {@see InsertMemoryOps} did not
 * recognise it as an owned producer — a LOAD_LOCAL is not on its list — so it
 * BLOCKED the destination name, and a blocked name gets neither the
 * release-before-overwrite nor the scope-exit release. Every copy leaked, and it
 * took the name's OTHER owned stores down with it.
 *
 * `InferCalls::genericReturnType` is the compiler's own witness: one
 * `$args = $next;` inside its inheritance climb blocked `$args`, and with it the
 * copy the same name takes from `$recv->typeArgs` one line earlier. It was the
 * single largest live-set site of a self-compile — 830,279 blocks / 63.3 MB at
 * the peak, against a function that returns null for almost every call.
 *
 *   bin/manticore compile tools/prof/array_alias_copy.php -o /tmp/aliascopy
 *   for m in climb prop both; do
 *     /usr/bin/time -l /tmp/aliascopy $m 200000 2>&1 | grep 'peak memory'
 *   done
 *
 * Measured (arm64, 200k iterations x 8 elements, peak footprint):
 *
 *   climb   232.6 MB -> 1.2 MB   `$args = $next` — the local-to-local copy,
 *                                plus `$next` itself, which the `vecalias` rule
 *                                blocked as a shared source it no longer is
 *   prop      1.1 MB             control: a vec-property copy is owned already
 *   both     78.3 MB -> 1.2 MB   both stores on ONE name — the shape
 *                                `genericReturnType` has
 *
 * php 8.5 is flat for all three.
 *
 * ⚠ Unblocking only the DESTINATION left `climb` at 181 MB: the SOURCE stayed
 * blocked by the `vecalias` rule, whose premise ("two locals share one buffer")
 * is exactly what a copy makes false. Both halves or neither.
 */

class AacNode
{
    public string $s = '';
}

class AacRecv
{
    /** @var AacNode[] */
    public array $items = [];
}

/** @return AacNode[] */
function aac_seed(int $n): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $nd = new AacNode();
        $nd->s = 'node' . $i;
        $out[] = $nd;
    }
    return $out;
}

/** The local-to-local copy alone. */
function aac_climb(AacRecv $r, int $n): int
{
    $args = aac_seed($n);
    $next = [];
    foreach ($args as $a) { $next[] = $a; }
    $args = $next;
    return count($args);
}

/** The property copy alone — owned already, but only while nothing else on the
 *  name is blocked. */
function aac_prop(AacRecv $r, int $n): int
{
    $args = $r->items;
    return count($args);
}

/** Both on ONE name, which is the genericReturnType shape: the blocked
 *  local-to-local store takes the property copy down with it. */
function aac_both(AacRecv $r, int $n): int
{
    $args = $r->items;
    $next = [];
    foreach ($args as $a) { $next[] = $a; }
    $args = $next;
    return count($args);
}

$mode = $argv[1] ?? 'both';
$iters = (int)($argv[2] ?? 200000);
$r = new AacRecv();
$r->items = aac_seed(8);
$total = 0;
for ($i = 0; $i < $iters; $i++) {
    if ($mode === 'climb') { $total += aac_climb($r, 8); }
    elseif ($mode === 'prop') { $total += aac_prop($r, 8); }
    else { $total += aac_both($r, 8); }
}
echo $mode, ' ', $total, "\n";
