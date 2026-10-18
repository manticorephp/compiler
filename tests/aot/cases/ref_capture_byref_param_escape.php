<?php
// A closure that captures a by-ref param by reference keeps a live value after the call, and the caller sees every write.
class S { public $cb; function set(int &$v): void { $this->cb = function () use (&$v) { return $v; }; $v = $v + 1; } }
class W { public array $a = [5]; public int $c = 10; public string $s = "s"; }
function mk(S $s): void { $x = 7; $s->set($x); echo $x, "\n"; }
$s = new S; mk($s);
str_repeat("x", 1000);
var_dump(($s->cb)());
function mk3(S $s): void { $w = new W; $s->set($w->a[0]); echo $w->a[0], "\n"; }
mk3($s); var_dump(($s->cb)());
function app(string &$t, string $x): void { $f = function () use (&$t, $x) { $t .= $x; }; $f(); $f(); }
$w = new W; app($w->s, "ab"); echo $w->s, "\n";
$local = "q"; app($local, "r"); echo $local, "\n";
function count_up(int &$n): int { return (int) preg_replace_callback('/a/', function ($m) use (&$n) { $n++; return 'b'; }, 'aaa') === 0 ? $n : $n; }
$cnt = 0; count_up($cnt); echo $cnt, "\n";
$w2 = new W; count_up($w2->c); echo $w2->c, "\n";
function thrower(int &$n): void { $f = function () use (&$n) { $n = 42; throw new Exception("t"); }; $f(); }
$w3 = new W; try { thrower($w3->c); } catch (Exception $e) { echo "caught ", $w3->c, "\n"; }
function rec(array &$acc, int $d): void { $f = function () use (&$acc, $d) { $acc[] = $d; }; $f(); if ($d > 0) { rec($acc, $d - 1); } }
$w4 = new W; rec($w4->a, 3); echo implode(",", $w4->a), "\n";
function peek(string &$t, string $x): void { $f = function () use (&$t) { return strlen($t); }; $f(); $t = str_repeat($x, 30); }
function loopit(): void { $w = new W; for ($i = 0; $i < 1000; $i++) { peek($w->s, "x"); } }
loopit(); $b = memory_get_usage();
for ($j = 0; $j < 200; $j++) { loopit(); }
echo (memory_get_usage() - $b) < 300000 ? "flat" : "leak", "\n";
