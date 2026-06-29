<?php
// ArrayAccess: $obj[$k] / $obj[$k]=$v / $obj[]= / isset / unset dispatch to
// offsetGet / offsetSet / offsetExists / offsetUnset.
class Map implements ArrayAccess {
    /** @var array<string,int> */
    private array $d = [];
    public function offsetExists($k): bool { return isset($this->d[$k]); }
    public function offsetGet($k): mixed { return $this->d[$k] ?? -1; }
    public function offsetSet($k, $v): void { if ($k === null) { $this->d[] = $v; } else { $this->d[$k] = $v; } }
    public function offsetUnset($k): void { unset($this->d[$k]); }
}
$m = new Map();
$m["x"] = 10;
$m["y"] = 20;
echo $m["x"], " ", $m["y"], "\n";
echo isset($m["x"]) ? "yes" : "no", "\n";
echo isset($m["z"]) ? "yes" : "no", "\n";
unset($m["x"]);
echo isset($m["x"]) ? "yes" : "no", "\n";
echo $m["x"], "\n";

// Append + int keys.
class Vec implements ArrayAccess {
    /** @var int[] */
    private array $items = [];
    public function offsetExists($k): bool { return isset($this->items[$k]); }
    public function offsetGet($k): mixed { return $this->items[$k]; }
    public function offsetSet($k, $v): void { if ($k === null) { $this->items[] = $v; } else { $this->items[$k] = $v; } }
    public function offsetUnset($k): void { unset($this->items[$k]); }
}
$v = new Vec();
$v[] = 100;
$v[] = 200;
$v[] = 300;
echo $v[0], " ", $v[1], " ", $v[2], "\n";
$v[1] = 999;
echo $v[1], "\n";
