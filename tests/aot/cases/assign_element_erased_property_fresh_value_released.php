<?php
// $this->cache[$k] = <fresh string or cell> on an erased array property (an ArrayAccess class exists): the store counts its own copy, the fresh value is released.
class C implements ArrayAccess {
    public function offsetExists($o): bool { return false; }
    public function offsetGet($o): mixed { return null; }
    public function offsetSet($o, $v): void {}
    public function offsetUnset($o): void {}
}
class L {
    private $cache = [];
    public function put(string $k, int $i) { return $this->cache[$k] = str_repeat('x', 8) . $i; }
    public function putc(string $k, $v) { return $this->cache[$k] = $v === 1 ? [$v] : 'c' . $v; }
}
$l = new L; $l->put('a', 1); $l->putc('b', 2);
$base = memory_get_peak_usage();
for ($i = 0; $i < 20000; $i++) { $l->put('a', $i); $s = $l->put('b', $i); $l->putc('c', $i); }
echo $s, ' ', (memory_get_peak_usage() - $base) < 1000000 ? "flat\n" : "leak\n";
