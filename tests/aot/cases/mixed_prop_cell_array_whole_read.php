<?php
// Whole read (var_dump / is_array / gettype / return / pass) of a mixed prop
// holding an array reads a tagged array cell, so it dispatches correctly
// instead of misreading a raw pointer — every array in a `mixed` slot is boxed
// FLAT, the SPL key-buffer shape (`$ks[]=$k; $this->k=$ks`) included, and an
// ArrayIterator in the same module iterates through the cell base.

class Cfg { public mixed $d; }
$c = new Cfg();
$c->d = ["host" => "x", "port" => null, "n" => 8080];
var_dump($c->d);
var_dump(is_array($c->d));
echo gettype($c->d), "\n";
var_dump($c->d["port"]);
echo count($c->d), "\n";
foreach ($c->d as $k => $v) { echo "$k:"; var_dump($v); }

// whole read via a mixed return
class Bag { public mixed $b; public function all(): mixed { return $this->b; } }
$bg = new Bag();
$bg->b = ["a" => 1.5, "z" => null, "s" => "t"];
var_dump($bg->all());

// SPL in the same module — the vec key-buffer is a boxed cell, iteration works
$it = new ArrayIterator(["p" => 10, "q" => 20, "r" => 30]);
foreach ($it as $k => $v) { echo "$k=$v "; }
echo "\n";
$it["s"] = 40;
echo $it["s"], " ", count($it), "\n";

// VEC value container (int-keyed heterogeneous) whole-read by a tag consumer.
class VBag { public mixed $v; }
$vb = new VBag();
$vb->v = ["a", null, 3, "d"];
var_dump($vb->v);
var_dump(is_array($vb->v));
var_dump($vb->v[1]);
echo count($vb->v), "\n";

// user key-buffer class (vec $k) must still work — boxing it would break lookup
class KB implements Iterator {
    private mixed $d; private mixed $k; private int $i = 0;
    public function __construct(mixed $data) { $this->d = $data; $ks = []; foreach ($this->d as $kk => $vv) { $ks[] = $kk; } $this->k = $ks; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < count($this->k); }
    public function current(): mixed { return $this->d[$this->k[$this->i]]; }
    public function key(): mixed { return $this->k[$this->i]; }
    public function next(): void { $this->i = $this->i + 1; }
}
$kb = new KB(["m" => "one", "n" => "two"]);
foreach ($kb as $k => $v) { echo "$k>$v "; }
echo "\n";
