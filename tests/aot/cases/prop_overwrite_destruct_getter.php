<?php
// Overwriting an object property must run the old value's __destruct, EVEN WHEN
// the same class also has a getter that returns that property.
//
// A getter is `return $this->r;`, which the borrow scan used to treat as a raw
// borrow and veto the whole DECLARING CLASS's release-before-overwrite. So the
// class below leaked every overwritten Res and never ran its destructor, while
// the identical class without a getter was correct — the getter alone decided
// it. `emitReturn` already retains a borrowed property read on the way out, so
// the caller owns its own reference and the veto was never needed here.
//
// The getter must be CALLED: an unreachable one can be eliminated before the
// scan runs, and then there is nothing to veto and the test passes either way.

final class Res
{
    public function __construct(public string $n) {}

    public function __destruct() { echo "bye " . $this->n . "\n"; }
}

final class Slot
{
    public ?Res $r = null;

    public function peek(): ?Res { return $this->r; }
}

$s = new Slot();
$s->r = new Res('a');
echo "held " . ($s->peek()?->n ?? '-') . "\n";
$s->r = new Res('b');
echo "held " . ($s->peek()?->n ?? '-') . "\n";
$s->r = null;
echo "end\n";
