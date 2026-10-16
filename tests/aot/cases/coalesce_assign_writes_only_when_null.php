<?php
// `$t ??= v` writes only when `$t` is null or unset, and answers the value.
class Bag implements ArrayAccess
{
    /** @var array<string, mixed> */
    private array $d = ['a' => 1];
    public function offsetExists(mixed $k): bool { return isset($this->d[$k]); }
    public function offsetGet(mixed $k): mixed { return $this->d[$k]; }
    public function offsetSet(mixed $k, mixed $v): void { echo "set $k\n"; $this->d[$k] = $v; }
    public function offsetUnset(mixed $k): void { unset($this->d[$k]); }
}
class P
{
    /** @var array<string, array<string, bool>> */
    private array $refs = [];
    /** @var array<string, object> */
    private array $cache = [];
    private ?string $cur = null;
    public function hit(string $t, ?string $c, bool $soft): void
    {
        $this->cur = $c;
        if ($soft) {
            $this->refs[$t][$this->cur ?? ''] ??= true;
        } else {
            $this->refs[$t][$this->cur ?? ''] = false;
        }
    }
    public function obj(string $k): object { return $this->cache[$k] ??= new ArrayObject([$k]); }
    public function count(): int { return count($this->refs, COUNT_RECURSIVE); }
}
$p = new P();
for ($i = 0; $i < 60; $i++) { $p->hit('t' . ($i % 7), $i % 3 ? 'c' . ($i % 5) : null, $i % 2 === 0); }
echo $p->count(), "\n";
var_dump($p->obj('x') === $p->obj('x'));
$b = new Bag();
$b['a'] ??= 5;
$b['z'] ??= 6;
echo $b['a'], $b['z'], "\n";
$x = null;
echo ($x ??= 'first'), ($x ??= 'second'), "\n";
