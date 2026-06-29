<?php
function pairs() { yield "a" => 1; yield "b" => 2; yield "c" => 3; }
foreach (pairs() as $k => $v) { echo $k, "=", $v, "\n"; }

function echoer() {
    while (true) { $x = yield; echo "got ", $x, "\n"; }
}
$g = echoer();
$g->current();
$g->send(10);
$g->send(20);

// The yield expression is cell-typed: a sent value round-trips through
// var_dump with its real type; an UNSENT yield reads NULL (not raw 0).
function typed() { $a = yield 1; var_dump($a); $b = yield 2; var_dump($b); }
$t = typed();
$t->current();
$t->send(42);
$t->send("hi");
function unsent() { $x = yield 1; var_dump($x); }
foreach (unsent() as $v) {}

function withret() { yield 1; yield 2; return 99; }
$r = withret();
foreach ($r as $v) { echo $v, " "; }
echo "\n", $r->getReturn(), "\n";

function nums() { yield 5; yield 6; yield 7; }
$n = nums();
while ($n->valid()) { echo $n->current(), " "; $n->next(); }
echo "\n";
