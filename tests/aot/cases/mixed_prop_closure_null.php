<?php

// A `mixed` property that ever holds a \Closure keeps its slot RAW: a closure
// is a header-less struct, so it cannot NaN-box, and the whole slot therefore
// stores bare words. The READ side has to agree — it used to tag-decode the
// slot regardless, and a raw 0 (the `null` default) decoded as a double, kind
// 6, never the NULL tag: `$this->fn !== null` answered TRUE on a freshly
// constructed object, so `isStreaming()` was permanently true. php is the
// oracle.

final class Slot
{
    private mixed $fn = null;
    private mixed $anything = null;

    public function hasFn(): bool { return $this->fn !== null; }
    public function fnIsNull(): bool { return $this->fn === null; }
    public function setFn(callable $f): void { $this->fn = $f; }
    public function clearFn(): void { $this->fn = null; }
    public function callFn(int $x): int
    {
        $f = $this->fn;
        return $f($x);
    }

    public function hasAny(): bool { return $this->anything !== null; }
    public function setAny(mixed $v): void { $this->anything = $v; }
}

$s = new Slot();
echo 'fresh: ', $s->hasFn() ? 'set' : 'null', ' ', $s->fnIsNull() ? 'null' : 'set', "\n";

$s->setFn(function (int $x): int { return $x * 2; });
echo 'after set: ', $s->hasFn() ? 'set' : 'null', ' ', $s->fnIsNull() ? 'null' : 'set', "\n";
echo 'call: ', $s->callFn(21), "\n";

$s->clearFn();
echo 'after clear: ', $s->hasFn() ? 'set' : 'null', ' ', $s->fnIsNull() ? 'null' : 'set', "\n";

// The same slot holding a non-closure — the boxable path, which was already
// right, kept honest here so the two cannot drift apart.
$t = new Slot();
echo 'any fresh: ', $t->hasAny() ? 'set' : 'null', "\n";
$t->setAny(0);
echo 'any zero: ', $t->hasAny() ? 'set' : 'null', "\n";
$t->setAny('');
echo 'any empty: ', $t->hasAny() ? 'set' : 'null', "\n";
$t->setAny(false);
echo 'any false: ', $t->hasAny() ? 'set' : 'null', "\n";
$t->setAny(null);
echo 'any null: ', $t->hasAny() ? 'set' : 'null', "\n";

echo "done\n";
