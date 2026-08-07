<?php

// A constructor is a call site like any other. Its array parameters used to be
// left out of the call-site element refinement, so a `?array` / bare-`array`
// ctor param stayed erased and the `$this->p = $param` store found no
// rc-managed type on either side — no retain. The object then held a BORROWED
// buffer, the caller's scope-exit release freed it, and the next allocation
// handed the same memory back: every arm reported the LAST buffer's contents.
// The identical store written as a method has always retained.

final class Src
{
    public function __construct(public mixed $stuff) {}
}

final class Arm
{
    public function __construct(public ?array $conds, public int $tag) {}
}

final class ArmExplicit
{
    public ?array $conds = null;

    public function __construct(?array $c)
    {
        $this->conds = $c;
    }
}

/** @param mixed $in */
function dup($in): array
{
    $o = [];
    foreach ($in as $v) { $o[] = $v; }
    return $o;
}

/**
 * @param mixed $src
 * @return Arm[]
 */
function buildPromoted($src): array
{
    $arms = [];
    foreach ($src as $one) {
        $c = $one->stuff === null ? null : dup($one->stuff);
        $arms[] = new Arm($c, 1);
    }
    return $arms;
}

/**
 * @param mixed $src
 * @return ArmExplicit[]
 */
function buildExplicit($src): array
{
    $arms = [];
    foreach ($src as $one) {
        $c = $one->stuff === null ? null : dup($one->stuff);
        $arms[] = new ArmExplicit($c);
    }
    return $arms;
}

$src = [new Src([1, 2, 3]), new Src([4, 5]), new Src(null), new Src([6])];
$promoted = buildPromoted($src);
$explicit = buildExplicit($src);

// Churn the allocator so a freed buffer is reused before it is read back.
$churn = [];
for ($i = 0; $i < 300; $i++) { $churn[] = dup([$i, $i + 1, $i + 2, $i + 3]); }

foreach ($promoted as $a) { echo $a->conds === null ? -1 : count($a->conds), "\n"; }
foreach ($explicit as $a) { echo $a->conds === null ? -1 : count($a->conds), "\n"; }
echo count($churn), "\n";
