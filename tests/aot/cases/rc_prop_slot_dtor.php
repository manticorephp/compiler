<?php

/**
 * Overwriting an object property runs the OLD object's destructor, then and
 * there — php's observable ordering, and the sharpest proof that a property slot
 * drops what it overwrites. Before that drop existed this printed set1/set2/set3
 * and only ONE `bye`, at teardown: every overwritten object was immortal and its
 * `__destruct` never ran at all.
 *
 * A string slot rides along on the same gate (nothing reads `$tag`), and
 * `$kept` pins the other side: it IS read, so its slot must keep its value and
 * the borrow stays valid.
 */

final class Noisy
{
    public function __construct(public int $id) {}
    public function __destruct() { echo 'bye', $this->id, "\n"; }
}

final class Holder5
{
    public ?Noisy $cur = null;
    public string $tag = '';
    public string $kept = '';

    public function readKept(): string { return $this->kept; }
}

$h = new Holder5();
for ($i = 1; $i <= 3; $i++) {
    $h->cur = new Noisy($i);
    $h->tag = 'tag' . $i;
    $h->kept = 'kept' . $i;
    $borrow = $h->readKept();
    echo 'set', $i, ' ', $h->tag, ' ', $borrow, "\n";
}
echo "done\n";
