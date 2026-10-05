<?php
final class V { public function __construct(public string $s) {} public function __destruct() { echo "free ", $this->s, "\n"; } }
final class H {
    public V $p;
    public function __construct() { $this->p = new V('a'); }
    public function swap(V $old): string { $this->p = new V('b'); return $old->s; }
    public function run(): string { return $this->swap($this->p) . $this->p->s; }
}
$h = new H();
echo $h->run(), "\n";
echo "end\n";
