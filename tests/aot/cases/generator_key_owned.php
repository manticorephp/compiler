<?php
final class K { public function __construct(public string $n) {} public function __destruct() { echo "~K {$this->n}\n"; } }
function mk(string $n): mixed { return new K($n); }
function gen(): Generator { yield mk('a') => 1; }
foreach (gen() as $k => $v) { echo $k->n, "\n"; }
unset($k, $v); echo "after1\n";
final class It implements IteratorAggregate { public function getIterator(): Iterator { yield mk('b') => 2; } }
foreach (new It() as $k => $v) { echo $k->n, "\n"; }
unset($k, $v); echo "after2\n";
function gen2(): Generator { yield new K('c') => 1; }
foreach (gen2() as $k => $v) { echo $k->n, "\n"; }
unset($k, $v); echo "after3\n";
