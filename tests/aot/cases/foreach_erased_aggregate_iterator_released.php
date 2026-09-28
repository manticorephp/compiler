<?php
// A foreach over an ERASED subject that turns out to be an IteratorAggregate
// never gave back getIterator()'s result, not even at the loop's end — the
// iterator kept its subject alive (php-cs-fixer: a Tokens, every token in it).
final class It implements \Iterator {
    private int $i = 0;
    public function __construct(private array $d, private string $n) {}
    public function __destruct() { echo "~It ", $this->n, "\n"; }
    public function current(): mixed { return $this->d[$this->i]; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i++; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < \count($this->d); }
}
final class Agg implements \IteratorAggregate {
    public function __construct(private string $n) {}
    public function __destruct() { echo "~Agg ", $this->n, "\n"; }
    public function getIterator(): \Iterator { return new It([1, 2, 3], $this->n); }
}
function sum(mixed $s): int { $t = 0; foreach ($s as $v) { $t += $v; } return $t; }
function first(mixed $s): int { foreach ($s as $v) { return $v; } return -1; }
function brk(mixed $s): int { $t = 0; foreach ($s as $v) { $t += $v; if ($v === 2) { break; } } return $t; }
function plain(mixed $s): int { $t = 0; foreach ($s as $v) { $t += $v; } return $t; }
echo sum(new Agg('a')), "\n";
echo "after sum\n";
echo first(new Agg('b')), "\n";
echo "after first\n";
echo brk(new Agg('c')), "\n";
echo "after brk\n";
$it = new It([4, 5], 'direct');
echo plain($it), "\n";
echo "after plain\n";
unset($it);
echo "end\n";
