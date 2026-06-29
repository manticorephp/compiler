<?php
// Generic SPL-style container backed by a `mixed` (cell) array: cell-array
// store/get/isset/unset + cell-key dispatch + foreach over a cell array with
// string keys. Regression for the cell-array-store SIGSEGV + cell-key handling.
class ArrIt implements Iterator, ArrayAccess, Countable {
    private mixed $d;
    private mixed $k;
    private int $i = 0;
    public function __construct(mixed $data = []) { $this->d = $data; $this->buildKeys(); }
    private function buildKeys(): void { $ks = []; foreach ($this->d as $kk => $vv) { $ks[] = $kk; } $this->k = $ks; }
    public function rewind(): void { $this->buildKeys(); $this->i = 0; }
    public function valid(): bool { return $this->i < count($this->k); }
    public function current(): mixed { return $this->d[$this->k[$this->i]]; }
    public function key(): mixed { return $this->k[$this->i]; }
    public function next(): void { $this->i = $this->i + 1; }
    public function offsetExists(mixed $o): bool { return isset($this->d[$o]); }
    public function offsetGet(mixed $o): mixed { return $this->d[$o]; }
    public function offsetSet(mixed $o, mixed $v): void { if ($o === null) { $this->d[] = $v; } else { $this->d[$o] = $v; } }
    public function offsetUnset(mixed $o): void { unset($this->d[$o]); }
    public function count(): int { return count($this->d); }
}
$a = new ArrIt(["x" => 1, "y" => 2]);
foreach ($a as $kk => $vv) { echo $kk, "=", $vv, "\n"; }
echo "count=", count($a), "\n";
$a["z"] = "nine";
$a[] = "app";
echo "get z=", $a["z"], "\n";
echo "isset y=", isset($a["y"]) ? "Y" : "N", "\n";
echo "isset q=", isset($a["q"]) ? "Y" : "N", "\n";
unset($a["x"]);
echo "after unset count=", count($a), "\n";
foreach ($a as $kk => $vv) { echo $kk, "->", $vv, "\n"; }
