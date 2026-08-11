<?php

/**
 * A slot the emitter fills with a NaN-BOXED value must be released as a CELL.
 *
 * `/** @var int[]|null $c *\/` lowers to a cell (a nullable CONTAINER keeps its
 * cell lowering), so the slot is cell-repr and the store boxes on the way in —
 * while the producer is a plain owned CALL whose type is `int[]`. Taking the
 * release flavor from the PRODUCER emitted `__mir_array_release_buf` on the tag
 * and faulted on `0xfff7…` at scope exit.
 *
 * The nullable ARRAY PROPERTY below is the same disagreement reached through a
 * property read (the shape `Compile\Mir\Match_::children()` is built on).
 */

/** @return int[] */
function rcsMake(int $n): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) { $out[] = $i * 2; }
    return $out;
}

function rcsSum(int $iters): int
{
    $total = 0;
    for ($i = 0; $i < $iters; $i++) {
        /** @var int[]|null $c */
        $c = rcsMake(3);
        if ($c !== null) {
            foreach ($c as $v) { $total = $total + $v; }
        }
    }
    return $total;
}

final class RcsArm
{
    /** @param int[]|null $conds */
    public function __construct(public ?array $conds, public int $body) {}
}

final class RcsMatch
{
    /** @param RcsArm[] $arms */
    public function __construct(public array $arms) {}

    /** @return int[] */
    public function children(): array
    {
        $out = [];
        /** @var RcsArm[] $arms */
        $arms = $this->arms;
        foreach ($arms as $arm) {
            /** @var int[]|null $conds */
            $conds = $arm->conds;
            if ($conds !== null) {
                foreach ($conds as $c) { $out[] = $c; }
            }
            $out[] = $arm->body;
        }
        return $out;
    }
}

echo rcsSum(4), "\n";

$m = new RcsMatch([new RcsArm([1, 2], 3), new RcsArm(null, 4), new RcsArm([5], 6)]);
for ($i = 0; $i < 3; $i++) {
    $kids = $m->children();
    echo \count($kids), ' ', \implode(',', $kids), "\n";
}
