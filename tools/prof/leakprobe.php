<?php
/**
 * Minimal repro for "the live set only ever climbs".
 *
 * `report.php trend` over a self-host run shows every top site rising
 * monotonically and never falling — `Walk::children` 48 k → 1.70 M live child
 * arrays, `Type::unionWith` 8 k → 918 k live Types. That is retention, not a
 * working set, so the question is which SHAPE of call leaks its result.
 *
 *   ./bin/manticore.nopool compile tools/prof/leakprobe.php -o /tmp/leakprobe
 *   /usr/bin/time -l /tmp/leakprobe <mode> <iters>
 *
 * Modes, each calling the same allocating helper N times:
 *   discard   result dropped on the floor
 *   foreach   `foreach (mk($i) as $v)` — the shape Walk::children is used in
 *   assign    `$a = mk($i)` into the same local every iteration
 *   obj       an object result, dropped (the Type::unionWith shape)
 *
 * Peak RSS flat  => that shape releases its temporary.
 * Peak RSS ∝ N   => that shape leaks one allocation per call.
 */

class Box
{
    public int $a;

    public function __construct(int $a) { $this->a = $a; }
}

/** @return int[] */
function mkArr(int $i): array
{
    return [$i, $i + 1, $i + 2];
}

function mkObj(int $i): Box
{
    return new Box($i);
}

class ProbeNode
{
    public int $a;
    /** @var ProbeNode[] */
    public array $kids;

    /** @param ProbeNode[] $kids */
    public function __construct(int $a, array $kids)
    {
        $this->a = $a;
        $this->kids = $kids;
    }
}

function probeTree(int $fanout, int $depth): ProbeNode
{
    if ($depth <= 0) { return new ProbeNode($depth, []); }
    $kids = [];
    for ($i = 0; $i < $fanout; $i++) { $kids[] = probeTree($fanout, $depth - 1); }
    return new ProbeNode($depth, $kids);
}

/** @return ProbeNode[] */
function probeChildren(ProbeNode $n): array
{
    $out = [];
    foreach ($n->kids as $k) { $out[] = $k; }
    return $out;
}

/**
 * The MIXED-OWNERSHIP shape `Walk::children` actually has: some arms return the
 * node's OWN array (a borrow), others a freshly built one (owned). One return
 * type, two ownerships — the caller cannot release unconditionally without
 * over-releasing the borrow.
 *
 * @return ProbeNode[]
 */
function probeChildrenMixed(ProbeNode $n): array
{
    if ($n->a > 1) { return $n->kids; }
    $out = [];
    foreach ($n->kids as $k) { $out[] = $k; }
    return $out;
}

function probeWalkMixed(ProbeNode $n): int
{
    $t = $n->a;
    foreach (probeChildrenMixed($n) as $c) { $t += probeWalkMixed($c); }
    return $t;
}

function probeWalk(ProbeNode $n): int
{
    $t = $n->a;
    foreach (probeChildren($n) as $c) { $t += probeWalk($c); }
    return $t;
}

$mode = $argc > 1 ? $argv[1] : 'foreach';
$n = $argc > 2 ? (int)$argv[2] : 1000000;
$t = 0;

if ($mode === 'discard') {
    for ($i = 0; $i < $n; $i++) { mkArr($i); }
} elseif ($mode === 'foreach') {
    for ($i = 0; $i < $n; $i++) {
        foreach (mkArr($i) as $v) { $t += $v; }
    }
} elseif ($mode === 'assign') {
    $a = [];
    for ($i = 0; $i < $n; $i++) {
        $a = mkArr($i);
        $t += $a[0];
    }
} elseif ($mode === 'lit') {
    // Same overwrite, but the value is a literal built in place rather than a
    // call result: separates "arrays are never released on overwrite" from
    // "a CALL RESULT is not released on overwrite".
    $a = [];
    for ($i = 0; $i < $n; $i++) {
        $a = [$i, $i + 1, $i + 2];
        $t += $a[0];
    }
} elseif ($mode === 'assign_unset') {
    $a = [];
    for ($i = 0; $i < $n; $i++) {
        $a = mkArr($i);
        $t += $a[0];
        unset($a);
    }
} elseif ($mode === 'walk') {
    // The compiler's actual shape: a FIXED tree walked R times, where every
    // visit calls a helper that materialises a fresh children array and the
    // caller iterates it as a temporary. The tree is built once, so any growth
    // with R is the walk leaking — this is what `Walk::children` does 1.7 M
    // times without a single block coming back.
    $root = probeTree(3, 8);
    for ($r = 0; $r < $n; $r++) { $t += probeWalk($root); }
} elseif ($mode === 'walkmixed') {
    $root = probeTree(3, 8);
    for ($r = 0; $r < $n; $r++) { $t += probeWalkMixed($root); }
} elseif ($mode === 'obj') {
    for ($i = 0; $i < $n; $i++) {
        $o = mkObj($i);
        $t += $o->a;
    }
} else {
    echo "unknown mode\n";
    exit(2);
}

echo $mode, " ", $n, " -> ", $t, "\n";
