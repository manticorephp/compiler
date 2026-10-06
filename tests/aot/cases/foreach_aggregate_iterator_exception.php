<?php
// LEAK: an exception leaving a foreach over an IteratorAggregate never releases the iterator getIterator() returned
final class M { public function __construct(public string $data) {} }
final class C implements \IteratorAggregate {
    public int $n = 0;
    public function receive(): M { if ($this->n >= 2) { throw new \RuntimeException('x'); } $this->n++; return new M(str_repeat('m', 2000) . $this->n); }
    public function getIterator(): \Generator { while (true) { yield $this->receive(); } }
}
function run1(C $ws): int { $t = 0; foreach ($ws as $m) { $t += strlen($m->data); } return $t; }
function turn(): int { $c = new C(); try { return run1($c); } catch (\RuntimeException $e) { return 1; } }
$t = 0;
for ($i = 0; $i < 2000; $i++) { $t += turn(); }
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 40000; $i++) { $t += turn(); }
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 8 ? "flat\n" : "LEAK\n";
