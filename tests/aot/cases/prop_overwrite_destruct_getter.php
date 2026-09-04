<?php
// Overwriting an object property must run the old value's __destruct, EVEN WHEN
// the same class also has a getter that returns that property.
//
// A getter is `return $this->r;`, which the borrow scan treated as a raw borrow
// and used to veto the whole DECLARING CLASS's release-before-overwrite. So the
// class below leaked every overwritten Res and never ran its destructor, while
// the identical class WITHOUT a getter was already correct — the getter alone
// decided it. `emitReturn` already retains a borrowed property read on the way
// out (isBorrowedObjReturn), so the caller owns a reference of its own and the
// veto was never needed in this position.
//
// The getter must be CALLED, or it can be eliminated before the scan runs and
// there is nothing left to veto. It is called AFTER both stores, and its result
// is passed as a plain call ARGUMENT: a receiver (`$s->peek()->n`) or a
// condition never releases its temp, so the old value would sit at rc 2, the
// overwrite would drop it only to 1, and both destructors would fire at process
// exit instead — the fix would read as broken while working.

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

function show(?Res $r): void { echo "held " . ($r === null ? "-" : $r->n) . "\n"; }

$s = new Slot();
$s->r = new Res('a');
$s->r = new Res('b');
show($s->peek());
echo "end\n";
