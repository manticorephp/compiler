<?php

/** @param int[] $xs */
function annotated(array $xs): int { return count($xs); }

function unannotated(array $xs): int { return count($xs); }

/** @return string[] */
function annotatedReturn(): array { return []; }

function unannotatedReturn(): array { return []; }

function generic(array $xs): int { return 0; }   // native no generic -> warn
function nativeGeneric(int ...$xs): int { return 0; }  // not array hint

class Bag
{
    /** @var float[] */
    public array $ok = [];

    public array $bad = [];

    public function take(array $items): void {}  // warn
}
