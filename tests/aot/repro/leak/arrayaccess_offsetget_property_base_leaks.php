<?php
// A property read on an ArrayAccess offsetGet result (`$o[$k]->p`) never releases the returned object.
// issue: #132
final class Box { public function __construct(public string $s) {} }
final class C implements ArrayAccess {
    private array $d = [];
    public function offsetExists(mixed $o): bool { return isset($this->d[$o]); }
    public function offsetGet(mixed $o): mixed { return $this->d[$o]; }
    public function offsetSet(mixed $o, mixed $v): void { $this->d[$o] = $v; }
    public function offsetUnset(mixed $o): void { unset($this->d[$o]); }
}
function churn(int $n): int
{
    $b = new C();
    $len = 0;
    for ($i = 0; $i < $n; $i++) { $b[$i & 127] = new Box('c'); $len += strlen($b[$i & 127]->s); }
    return $len;
}
churn(2000);
$before = memory_get_peak_usage();
churn(200000);
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
