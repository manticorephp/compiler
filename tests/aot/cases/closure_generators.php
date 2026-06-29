<?php
// A closure whose body yields is a generator: invoking it makes a Generator.
$g = function() { yield 1; yield 2; yield 3; };
foreach ($g() as $v) { echo $v, "\n"; }

// By-value capture flows into the generator frame.
$mul = 10;
$h = function() use ($mul) { for ($i = 1; $i <= 3; $i++) { yield $i * $mul; } };
foreach ($h() as $v) { echo $v, "\n"; }

// Captured string + explicit key.
$prefix = "item-";
$kv = function() use ($prefix) { yield 'a' => $prefix . "1"; yield 'b' => $prefix . "2"; };
foreach ($kv() as $k => $v) { echo $k, "=", $v, "\n"; }

// Factory returning a generator closure (escapes the defining frame).
function make_counter(int $base) {
    return function() use ($base) { yield $base + 1; yield $base + 2; };
}
$c = make_counter(100);
foreach ($c() as $v) { echo $v, "\n"; }

// Declared `: \Closure` return + $this capture in a method.
class Counter {
    private int $start = 5;
    public function gen(): \Closure {
        return function() { for ($i = $this->start; $i < $this->start + 3; $i++) { yield $i; } };
    }
}
$o = new Counter();
$cg = $o->gen();
foreach ($cg() as $v) { echo $v, "\n"; }

// send() into a closure generator.
$echoer = function() { $x = yield 1; echo "got ", $x, "\n"; $y = yield 2; echo "got ", $y, "\n"; };
$gen = $echoer();
$gen->current();
$gen->send(100);
$gen->send(200);

// Parameterised closure generator, reused.
$mk = function(int $n) { for ($i = 0; $i < $n; $i++) { yield $i; } };
foreach ($mk(2) as $v) { echo "a", $v, "\n"; }
foreach ($mk(3) as $v) { echo "b", $v, "\n"; }

// yield from inside a closure generator.
$yf = function() { yield 1; yield from [10, 20]; yield 2; };
foreach ($yf() as $v) { echo $v, "\n"; }
