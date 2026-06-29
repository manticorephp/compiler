<?php
// An `array`-typed ctor/method param stored into a `mixed` field is promoted
// to cell so the call site boxes the argument (element types known there).
// Was a SIGSEGV on var_dump/iteration of a homogeneous-valued backing array.
class Box {
    private mixed $d;
    public function __construct(array $x) { $this->d = $x; }
    public function set(array $y): void { $this->d = $y; }
    public function get(): mixed { return $this->d; }
}
$b = new Box(["k" => 10, "m" => 20]);
var_dump($b->get());
foreach ($b->get() as $k => $v) { echo $k, "=", $v, "\n"; }

$b->set(["p" => 1, "q" => 2, "r" => 3]);
var_dump($b->get());
echo count($b->get()), "\n";
