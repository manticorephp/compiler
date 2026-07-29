<?php

class Node
{
    public int $v = 0;
    public ?Node $next = null;
}

// A back-reference is `r:<slot>`, and php's slot counter advances for EVERY
// value — the array, each scalar, each object — so the numbering only lines up
// if the walker counts before it dispatches.
$a = new Node();
$b = new Node();
$b->v = 7;

echo serialize([$a, $a, $b]), "\n";
echo serialize([$a, $b, $a, $b]), "\n";
echo serialize([1, 2, $a, $a]), "\n";
echo serialize(['x' => $a, 'y' => $a]), "\n";

// A cycle terminates only because the object is registered before its
// properties are walked.
$c = new Node();
$c->v = 1;
$c->next = $c;
echo serialize($c), "\n";

$d = new Node();
$e = new Node();
$d->v = 1;
$e->v = 2;
$d->next = $e;
$e->next = $d;
echo serialize($d), "\n";

echo serialize([$a, [$a, [$a]]]), "\n";
