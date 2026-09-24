<?php
final class D { public function __construct(private int $n) {} public function it(): \Generator { yield $this->n; yield $this->n + 1; } }
$o = new D(3); foreach ($o->it() as $v) { echo $v; } echo "\n";
foreach ((new D(5))->it() as $v) { echo $v; } echo "\n";
$g = (new D(7))->it(); foreach ($g as $v) { echo $v; } echo "\n";
