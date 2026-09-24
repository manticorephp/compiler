<?php
$gen = static function () { yield 'a' => 1; yield 'b' => 2; };
function g1(\Closure $f): \Generator { yield from $f(); }
echo "1:"; foreach (g1($gen) as $k => $v) { echo $k, $v, ' '; } echo "\n";
function g2(callable $f): \Generator { $c = $f(...); yield from $c(); }
echo "2:"; foreach (g2($gen) as $k => $v) { echo $k, $v, ' '; } echo "\n";
final class H { private \Closure $f; public function __construct(callable $f) { $this->f = $f(...); }
  public function it(): \Generator { yield from ($this->f)(); }
  public function it2(): \Generator { $f = $this->f; yield from $f(); } }
echo "3:"; foreach ((new H($gen))->it() as $k => $v) { echo $k, $v, ' '; } echo "\n";
echo "4:"; foreach ((new H($gen))->it2() as $k => $v) { echo $k, $v, ' '; } echo "\n";
$c = $gen(...);
echo "5:"; foreach ($c() as $k => $v) { echo $k, $v, ' '; } echo "\n";
