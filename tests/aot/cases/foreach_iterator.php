<?php
// foreach over a class implementing Iterator: rewind/valid/current/key/next.
class Counter implements Iterator {
    private int $cur;
    public function __construct(private int $from, private int $to) { $this->cur = $from; }
    public function current(): mixed { return $this->cur; }
    public function key(): mixed { return $this->cur - $this->from; }
    public function next(): void { $this->cur++; }
    public function rewind(): void { $this->cur = $this->from; }
    public function valid(): bool { return $this->cur <= $this->to; }
}
foreach (new Counter(1, 4) as $k => $v) { echo $k, "=", $v, "\n"; }

// String values via a typed backing array.
class Words implements Iterator {
    private int $i = 0;
    /** @var string[] */
    private array $w = ["foo", "bar", "baz"];
    public function current(): string { return $this->w[$this->i]; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i = $this->i + 1; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 3; }
}
$out = "";
foreach (new Words() as $w) { $out = $out . $w . " "; }
echo $out, "\n";

// break / continue inside an object foreach.
foreach (new Counter(0, 9) as $v) {
    if ($v === 2) { continue; }
    if ($v === 5) { break; }
    echo $v, " ";
}
echo "\n";

// IteratorAggregate: getIterator() returns a user Iterator.
class Bag implements IteratorAggregate {
    public function __construct(private int $n) {}
    public function getIterator(): Iterator { return new Counter(1, $this->n); }
}
$sum = 0;
foreach (new Bag(5) as $v) { $sum = $sum + $v; }
echo $sum, "\n";

// Nested object foreach.
foreach (new Counter(1, 2) as $a) {
    foreach (new Counter(1, 2) as $b) { echo $a, "*", $b, " "; }
}
echo "\n";
