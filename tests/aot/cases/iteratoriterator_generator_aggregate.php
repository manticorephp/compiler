<?php
final class G implements \IteratorAggregate { public function getIterator(): \Traversable { yield 'a' => 1; yield 'b' => 2; } }
$it = new \IteratorIterator(new G());
foreach ($it as $k => $v) { echo $k, $v, ' '; } echo "\n";
final class G2 implements \IteratorAggregate { public function __construct(private int $n) {} public function getIterator(): \Traversable { for ($i = 0; $i < $this->n; $i++) { yield $i; } } }
foreach (new \IteratorIterator(new G2(3)) as $k => $v) { echo $k, $v, ' '; } echo "\n";
