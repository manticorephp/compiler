<?php
// alloc_churn — small-object churn with NO strings being built: objects, small
// packed arrays, and maps big enough to build a bucket index (>= 8 entries).
// Those are exactly the blocks the size-classed pool front-ends, and keeping
// string work out of the loop stops the string free list — which has existed
// for a long time — from masking the difference.
//
// Deterministic output, so the harness can still check parity against php.
class Node
{
    public function __construct(
        public int $id,
        public int $weight,
        public ?Node $next = null,
    ) {
    }
}

$sum = 0;
for ($i = 0; $i < 300000; $i++) {
    $a = new Node($i, $i * 3);
    $b = new Node($i + 1, $i * 5, $a);
    $sum += $b->weight + $b->next->id;

    $vec = [$i, $i + 1, $i + 2, $i + 3];
    $sum += $vec[3] - $vec[0];

    $map = [
        'id' => $i,
        'weight' => $i * 2,
        'left' => $i - 1,
        'right' => $i + 1,
        'depth' => $i % 7,
        'rank' => $i % 11,
        'flags' => $i % 3,
        'group' => $i % 13,
        'slot' => $i % 17,
        'gen' => $i % 19,
    ];
    $sum += $map['depth'] + $map['gen'] + $map['slot'];
}
echo $sum, "\n";
