<?php
// A method call on a null ?Map receiver does not throw "Call to a member function on null" (fused callback returns the seed, plain call dereferences null).
// issue: #148
use Manticore\Ds\Map;

function fused(?Map $m): void
{
    try { var_dump($m->reduce(fn($c, $v) => $c + $v, 7)); }
    catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}

function plain(?Map $m): void
{
    try { var_dump($m->count()); }
    catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}

fused(null);
plain(null);
