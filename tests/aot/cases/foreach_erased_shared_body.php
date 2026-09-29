<?php
class Agg implements IteratorAggregate { public function getIterator(): Iterator { return new ArrayIterator(['a' => 1, 'b' => 2, 'c' => 3]); } }
class It implements Iterator { private $i = 0; public function current(): mixed { return $this->i * 10; } public function key(): mixed { return 'k' . $this->i; }
  public function next(): void { $this->i++; } public function rewind(): void { $this->i = 0; } public function valid(): bool { return $this->i < 4; } }
function gen() { yield 'x' => 5; yield 'y' => 6; yield 'z' => 7; }
function walk(mixed $m): string {
    $o = '';
    foreach ($m as $k => $v) {
        if ($v === 2 || $v === 6) { continue; }
        if ($v === 30) { break; }
        $o .= "$k:$v ";
    }
    return $o;
}
function nested(mixed $outer, mixed $inner): string {
    $o = '';
    foreach ($outer as $a) {
        foreach ($inner as $b) {
            if ($b === 7) { continue 2; }
            if ($a === 3) { break 2; }
            $o .= "$a$b,";
        }
        $o .= '|';
    }
    return $o;
}
foreach ([[1, 2, 3], gen(), new Agg(), new It()] as $m) { echo walk($m), "\n"; }
echo nested([1, 2, 3, 4], [5, 7, 9]), "\n";
echo nested(new ArrayIterator([1, 2, 3]), [5, 6, 8]), "\n";
