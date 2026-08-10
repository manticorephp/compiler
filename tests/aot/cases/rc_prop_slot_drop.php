<?php

/**
 * A property slot must DROP the rc value it overwrites — a local slot always has,
 * a property slot never did, so every array an overwritten property left behind
 * was immortal (`$o->arr = fresh()` 191.7 MB at 100k iterations, 761.3 MB at
 * 400k; the same value into a local is flat, and php is flat).
 *
 * The drop is only legal where nothing borrows the slot's value without taking a
 * reference, so this case pins BOTH sides of that gate:
 *   - `Sink` is never read  → the drop fires;
 *   - `Walker` iterates its own property and reassigns it inside the loop, and
 *     `Peek` borrows an ELEMENT and then overwrites → the drop must NOT fire, or
 *     the loop walks a freed buffer and the borrowed element is freed under a
 *     live local (that one is cross-frame: the frame that overwrites never reads
 *     the property at all).
 */

final class Sink
{
    /** @var string[] */
    public array $rows = [];

    /** @param string[] $v */
    public function put(array $v): void { $this->rows = $v; }
}

final class Walker
{
    /** @var string[] */
    public array $items = [];

    /** @param string[] $v */
    public function set(array $v): void { $this->items = $v; }

    public function walkAndReplace(): string
    {
        $out = '';
        foreach ($this->items as $it) {
            $out = $out . $it . ';';
            $this->items = ['replaced'];
        }
        return $out;
    }
}

final class Peek
{
    /** @var string[] */
    public array $items = [];

    /** @param string[] $v */
    public function set(array $v): void { $this->items = $v; }
}

function rpsBorrowThenReplace(Peek $p): string
{
    $s = $p->items[0];
    $p->set(['zzz']);
    return $s;
}

$sink = new Sink();
for ($i = 0; $i < 200; $i++) {
    $sink->put(['row' . $i, 'row' . ($i + 1), 'row' . ($i + 2)]);
}
echo \implode(',', $sink->rows), "\n";

$w = new Walker();
$w->set(['a', 'b', 'c']);
echo $w->walkAndReplace(), "\n";
echo \implode(',', $w->items), "\n";

$p = new Peek();
$p->set(['alpha', 'beta']);
echo rpsBorrowThenReplace($p), "\n";
echo \implode(',', $p->items), "\n";

$self = new Sink();
$self->put(['x', 'y']);
$self->rows = $self->rows;
echo \implode(',', $self->rows), "\n";
