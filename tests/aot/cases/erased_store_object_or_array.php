<?php
use Manticore\Ds\Int16Array;
final class A implements ArrayAccess {
    /** @var array<int|string, mixed> */
    private array $d = [];
    public function offsetExists(mixed $o): bool { return isset($this->d[$o]); }
    public function offsetGet(mixed $o): mixed { return $this->d[$o]; }
    public function offsetSet(mixed $o, mixed $v): void { if ($o === null) { $this->d[] = $v; } else { $this->d[$o] = $v; } }
    public function offsetUnset(mixed $o): void { unset($this->d[$o]); }
    public function dump(): string { return json_encode($this->d); }
}
function pick(int $n): mixed { if ($n === 0) { return new A(); } if ($n === 1) { return [1, 2]; } return Int16Array::fromArray([1, 2, 3]); }
function fill(mixed $m, int $k, mixed $v): mixed { $m[$k] = $v; return $m; }
$r = pick(0);
$r[0] = 9; $r['k'] = 'v'; $r[] = [1, 2]; $r[5] = $r[0] + 1;
echo $r->dump(), ' ', $r[5], "\n";
$a = pick(1);
$a[0] = 'x'; $a[] = 3; $a['s'] = null;
echo json_encode($a), "\n";
$t = pick(2);
$t[1] = 77;
echo $t[1], ' ', count($t), "\n";
try { $t[9] = 1; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$o = fill(new A(), 3, 'three');
echo $o->dump(), "\n";
echo json_encode(fill([0, 0], 1, 'one')), "\n";
$i = 0;
$r[$i++] = $i;
echo $r->dump(), ' ', $i, "\n";
