<?php
// A generator made by calling a closure literal or a conditional's pick keeps its captures after the callee is released.
class H { public function __construct(public string $n) {} public function __destruct() { echo "drop {$this->n}\n"; } }
function viaLiteral(): Generator { $h = new H('lit'); return (function () use ($h) { yield $h->n . '1'; yield $h->n . '2'; })(); }
function viaCond(?Closure $cb): Generator { $h = new H('cond'); $d = function () use ($h) { yield $h->n . 'A'; yield $h->n . 'B'; }; return ($cb ?? $d)(); }
$g = viaLiteral(); foreach ($g as $v) { echo $v, "\n"; } unset($g);
$g = viaCond(null); foreach ($g as $v) { echo $v, "\n"; } unset($g);
echo "end\n";
